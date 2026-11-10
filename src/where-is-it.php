<?php
declare(strict_types=1);

/** All item and photo access follows the current installation, with no admin bypass. */
function whereCollection(PDO $db, array $user, string $id, bool $lock = false): array
{
    $widget = availableWidget($db, $user, $id, $lock);
    if ($widget['kind'] !== 'where-is-it') throw new HttpError(404, 'This collection is unavailable.');
    return $widget;
}

function whereSnapshot(PDO $db, array $user): array
{
    $query = $db->prepare("SELECT i.* FROM where_is_it_items i JOIN family_widgets w ON w.id=i.widget_id
        WHERE i.deleted_at IS NULL AND w.deleted_at IS NULL AND w.kind='where-is-it'
        AND (w.owner_id=? OR w.visibility='family') ORDER BY i.name,i.id");
    $query->execute([$user['id']]);
    return ['ownerId'=>(int)$user['id'], 'items'=>array_map(static fn(array $row): array => [
        'id'=>(string)$row['id'], 'widgetId'=>(string)$row['widget_id'], 'title'=>$row['name'],
        'location'=>$row['location'], 'note'=>$row['note'], 'photoKey'=>$row['photo_key'],
        'version'=>(int)$row['version'], 'updatedAt'=>activityTimestamp($row['updated_at'])
    ], $query->fetchAll())];
}

function mutateWhereItem(PDO $db, array $config, array $user, string $operation, array $body): array
{
    if (!in_array($operation, ['add','edit','remove'], true)) throw new HttpError(404, 'Unknown item action.');
    $widgetId = widgetIdentifier($body['widgetId'] ?? null);
    $id = $operation === 'add' ? null : widgetIdentifier($body['id'] ?? null);
    $key = $body['requestKey'] ?? null;
    if (!is_string($key) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $key)) throw new HttpError(422, 'Reopen the item and try again.');
    if ($operation !== 'add' && (!is_int($body['version'] ?? null) || $body['version'] < 1)) throw new HttpError(422, 'Refresh the item before saving.');
    $draft = null; $upload = null;
    if ($operation !== 'remove') {
        $draft = [widgetText($body['title'] ?? null,100), widgetText($body['location'] ?? null,160), memoryText(['story'=>$body['note'] ?? ''],'story',1000)];
        if (!is_bool($body['removePhoto'] ?? false)) throw new HttpError(422, 'Choose whether to remove the photo.');
        $upload = memoryUpload();
        if ($upload && ($body['removePhoto'] ?? false)) throw new HttpError(422, 'Choose a new photo or remove the saved one.');
    }
    // Exact retries include uploaded bytes; a key cannot silently change intent.
    $signature = hash('sha256', json_encode([$operation,$widgetId,$id,$body['version'] ?? null,$draft,$body['removePhoto'] ?? false,
        $upload ? hash_file('sha256',$upload['tmp']) : null], JSON_THROW_ON_ERROR));
    $newPath = null; $commitAttempted = false;
    $db->beginTransaction();
    try {
        $db->prepare('SELECT id FROM users WHERE id=? FOR UPDATE')->execute([$user['id']]);
        whereCollection($db,$user,$widgetId,true);
        $receipt = $db->prepare('SELECT signature,item_id FROM where_is_it_receipts WHERE actor_id=? AND request_key=?');
        $receipt->execute([$user['id'],$key]); $saved = $receipt->fetch();
        if ($saved) {
            if (!hash_equals($saved['signature'],$signature)) throw new HttpError(409,'This save request changed. Reopen the item before saving.','where_conflict');
            $db->commit();
            return whereSnapshot($db,$user) + ['receipt'=>['itemId'=>(string)$saved['item_id'],'requestKey'=>$key,'action'=>$operation]];
        }
        $old = null;
        if ($id !== null) {
            $query = $db->prepare('SELECT * FROM where_is_it_items WHERE id=? AND widget_id=? AND deleted_at IS NULL FOR UPDATE');
            $query->execute([$id,$widgetId]); $old = $query->fetch();
            if (!$old) throw new HttpError(404,'This item has been removed. Your draft is kept.');
            if ((int)$old['version'] !== $body['version']) throw new HttpError(409,'This item changed. Review the latest saved details before retrying.','where_conflict');
        }
        $photoKey = $old['photo_key'] ?? null; $mime = $old['photo_mime'] ?? null;
        if ($operation !== 'remove') {
            if ($body['removePhoto'] ?? false) { $photoKey = null; $mime = null; }
            if ($upload) {
                $photoKey = bin2hex(random_bytes(32)); $mime = $upload['mime'];
                $newPath = memoryPhotoPath($config,$photoKey);
                $directory = dirname($newPath);
                if (!is_dir($directory) && !mkdir($directory,0700,true) && !is_dir($directory)) throw new RuntimeException('Unable to create private photo storage.');
                if (!move_uploaded_file($upload['tmp'],$newPath)) throw new RuntimeException('Unable to save photo.');
                @chmod($newPath,0600);
            }
        }
        if ($operation === 'add') {
            $db->prepare('INSERT INTO where_is_it_items(widget_id,name,location,note,photo_key,photo_mime,updated_at) VALUES(?,?,?,?,?,?,UTC_TIMESTAMP(6))')
                ->execute([$widgetId,...$draft,$photoKey,$mime]);
            $id = (string)$db->lastInsertId();
        } elseif ($operation === 'edit') {
            $db->prepare('UPDATE where_is_it_items SET name=?,location=?,note=?,photo_key=?,photo_mime=?,version=version+1,updated_at=UTC_TIMESTAMP(6) WHERE id=?')
                ->execute([...$draft,$photoKey,$mime,$id]);
        } else {
            $db->prepare('UPDATE where_is_it_items SET deleted_at=UTC_TIMESTAMP(6),updated_at=UTC_TIMESTAMP(6),version=version+1 WHERE id=?')->execute([$id]);
        }
        $db->prepare('INSERT INTO where_is_it_receipts(actor_id,request_key,signature,item_id) VALUES(?,?,?,?)')->execute([$user['id'],$key,$signature,$id]);
        $commitAttempted = true;
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        if (!$commitAttempted && $newPath !== null) removeMemoryPhoto($config,basename($newPath));
        throw $error;
    }
    // Delete replaced files only after a confirmed commit; soft-deleted item photos are retained.
    if ($operation === 'edit' && $old['photo_key'] && $old['photo_key'] !== $photoKey) removeMemoryPhoto($config,$old['photo_key']);
    return whereSnapshot($db,$user) + ['receipt'=>['itemId'=>$id,'requestKey'=>$key,'action'=>$operation]];
}

function serveWherePhoto(PDO $db, array $config, array $user): void
{
    $id = widgetIdentifier($_GET['id'] ?? null);
    $key = $_GET['v'] ?? null;
    $query = $db->prepare('SELECT * FROM where_is_it_items WHERE id=? AND deleted_at IS NULL');
    $query->execute([$id]); $item = $query->fetch();
    if (!$item || !is_string($key) || !$item['photo_key'] || !hash_equals($item['photo_key'],$key)) throw new HttpError(404,'Photo unavailable.');
    whereCollection($db,$user,(string)$item['widget_id']);
    $path = memoryPhotoPath($config,$key);
    if (!is_file($path)) throw new HttpError(404,'Photo unavailable.');
    [$path,$mime] = memoryPhotoPreview($config,$key,$item['photo_mime'],960);
    $stream = @fopen($path,'rb');
    if (!$stream) throw new HttpError(404,'Photo unavailable.');
    session_write_close();
    header('Cache-Control: private, no-store');
    header('Content-Type: ' . $mime);
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; sandbox");
    header('Content-Disposition: inline; filename="item-photo"');
    header('Content-Length: ' . filesize($path));
    fpassthru($stream); fclose($stream);
}

/** Installation receipts acknowledge exact retries even after later edits or removal. */
function mutateWhereInstallation(PDO $db, array $user, string $operation, array $body): array
{
    if (!in_array($operation,['add','edit','remove'],true)) throw new HttpError(422,'This action does not apply to this collection.');
    $draft = in_array($operation,['add','edit'],true) ? [widgetText($body['title'] ?? null,120),$body['visibility'] ?? null] : null;
    if ($draft && !in_array($draft[1],['private','family'],true)) throw new HttpError(422,'Choose Personal or Family.');
    $requestKey = $body['requestKey'] ?? null;
    $key = widgetRequestKey($user,$body,'where-installation-' . $operation);
    $signature = hash('sha256',json_encode([$operation,$body['id']??null,$body['version']??null,$draft],JSON_THROW_ON_ERROR));
    $db->beginTransaction();
    try {
        // Serialize per-owner installation limits and UUID reuse across installations.
        $db->prepare('SELECT id FROM users WHERE id=? FOR UPDATE')->execute([$user['id']]);
        $query=$db->prepare('SELECT signature,widget_id FROM where_is_it_installation_receipts WHERE actor_id=? AND request_key=?');
        $query->execute([$user['id'],$requestKey]); $receipt=$query->fetch();
        if ($receipt) {
            if (!hash_equals($receipt['signature'],$signature)) throw new HttpError(409,'This save request changed. Reopen the settings.','widget_conflict');
            $db->commit();
            return widgetsSnapshot($db,$user) + ['receipt'=>['widgetId'=>(string)$receipt['widget_id'],'requestKey'=>$requestKey,'action'=>$operation]];
        }
        if ($operation === 'add') {
            $query=$db->prepare('SELECT COUNT(*) FROM family_widgets WHERE owner_id=? AND deleted_at IS NULL'); $query->execute([$user['id']]);
            if ((int)$query->fetchColumn()>=30) throw new HttpError(422,'You can keep up to 30 widgets.');
            $db->prepare("INSERT INTO family_widgets(owner_id,kind,title,visibility,request_key,created_at,updated_at) VALUES(?,'where-is-it',?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))")
                ->execute([$user['id'],...$draft,$key]);
            $id=(string)$db->lastInsertId();
            $metadata=['kind'=>'where-is-it','ownerId'=>(int)$user['id'],'visibility'=>$draft[1],'version'=>1];
        } else {
            $id=widgetIdentifier($body['id'] ?? null);
            $query=$db->prepare("SELECT * FROM family_widgets WHERE id=? AND kind='where-is-it' FOR UPDATE");
            $query->execute([$id]); $widget=$query->fetch();
            if (!$widget || (int)$widget['owner_id']!==(int)$user['id']) throw new HttpError(403,'Only the owner can manage this installation.');
            if ($widget['deleted_at']!==null) throw new HttpError(404,'This collection was removed.');
            if (!is_int($body['version'] ?? null) || $body['version']!==(int)$widget['version']) throw new HttpError(409,'This installation changed. Reopen its latest settings.','widget_conflict');
            if ($operation==='edit') $db->prepare('UPDATE family_widgets SET title=?,visibility=?,version=version+1,updated_at=UTC_TIMESTAMP(6) WHERE id=?')->execute([...$draft,$id]);
            else $db->prepare('UPDATE family_widgets SET deleted_at=UTC_TIMESTAMP(6),updated_at=UTC_TIMESTAMP(6),version=version+1 WHERE id=?')->execute([$id]);
            $metadata=['kind'=>'where-is-it','ownerId'=>(int)$user['id'],'visibility'=>$draft[1]??$widget['visibility'],'version'=>(int)$widget['version']+1];
        }
        $db->prepare('INSERT INTO where_is_it_installation_receipts(actor_id,request_key,signature,widget_id) VALUES(?,?,?,?)')->execute([$user['id'],$requestKey,$signature,$id]);
        recordActivity($db,$user,'widget',(int)$id,$operation==='add'?'created':($operation==='edit'?'edited':'removed'),null,$metadata);
        $db->commit();
    } catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
    return widgetsSnapshot($db,$user) + ['receipt'=>['widgetId'=>$id,'requestKey'=>$requestKey,'action'=>$operation]];
}
