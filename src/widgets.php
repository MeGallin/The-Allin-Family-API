<?php
declare(strict_types=1);

function widgetText(mixed $value, int $max): string
{
    if (!is_string($value) || preg_match('//u', $value) !== 1) throw new HttpError(422, 'Enter valid widget text.');
    if (preg_match('/[\x00-\x1f\x7f\x{2028}\x{2029}\x{202a}-\x{202e}\x{2066}-\x{2069}]/u', $value)) throw new HttpError(422, 'Keep names on one line without control characters.');
    $value = preg_replace('/[\x{200b}\x{2060}\x{feff}\x{00ad}]/u', '', $value);
    $value = trim(preg_replace('/[\p{Z}\s]+/u', ' ', $value));
    $length = preg_match_all('/./us', $value);
    if (!$length || $length > $max || !preg_match('/[^\p{Z}\p{C}\p{M}]/u',$value)) throw new HttpError(422, "Use 1 to $max visible characters, on one line.");
    return $value;
}

function widgetRequestKey(array $user, array $body, string $operation): string
{
    $key = $body['requestKey'] ?? null;
    if (!is_string($key) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $key)) throw new HttpError(422, 'Reopen the widget and try again.');
    return hash('sha256', $user['id'] . ':' . $operation . ':' . (is_string($body['id'] ?? null) ? $body['id'] : '') . ':' . $key);
}

function widgetIdentifier(mixed $value): string
{
    if (!is_string($value) || !preg_match('/^[1-9][0-9]{0,17}$/D', $value)) throw new HttpError(422, 'Choose a valid widget or meal.');
    return $value;
}

function widgetDraft(array $body): array
{
    $title = widgetText($body['title'] ?? null, 120);
    $visibility = $body['visibility'] ?? null;
    if (!in_array($visibility, ['private', 'family'], true)) throw new HttpError(422, 'Choose personal or family visibility.');
    $meals = $body['meals'] ?? null;
    if (!is_array($meals) || count($meals) < 2 || count($meals) > 12 || array_keys($meals) !== range(0, count($meals) - 1)) throw new HttpError(422, 'Add between 2 and 12 meals.');
    $seen = [];
    $meals = array_map(static function ($meal) use (&$seen): array {
        if ($meal instanceof stdClass) $meal = (array)$meal;
        if (!is_array($meal)) throw new HttpError(422, 'Enter a meal name.');
        $name = widgetText($meal['title'] ?? null, 80);
        $normal = function_exists('mb_strtolower') ? mb_strtolower($name,'UTF-8') : strtolower($name);
        if (isset($seen[$normal])) throw new HttpError(422, 'Use a different name for each meal so every choice has an equal chance.');
        $seen[$normal] = true;
        return ['id' => isset($meal['id']) ? widgetIdentifier($meal['id']) : null, 'title' => $name];
    }, $meals);
    return compact('title', 'visibility', 'meals');
}

function widgetMetadata(array $widget, int $mealCount): array
{
    // Admin can monitor usage without obtaining private titles, meals or results.
    return ['kind' => 'dinner-spinner', 'ownerId' => (int)$widget['owner_id'], 'visibility' => $widget['visibility'], 'mealCount' => $mealCount, 'version' => (int)$widget['version']];
}

function widgetSpinFields(array $spin): array
{
    return ['id' => (string)$spin['id'], 'widgetId' => (string)$spin['widget_id'], 'mealId'=>(string)$spin['meal_id'], 'mealVersion'=>(int)$spin['meal_version'], 'meal' => $spin['meal_title'],
        'actor' => ['id' => (int)$spin['actor_id'], 'name' => familyDisplayName($spin)], 'occurredAt' => activityTimestamp($spin['occurred_at'])];
}

function availableWidget(PDO $db, array $user, string $id, bool $lock=false): array
{
    $query=$db->prepare('SELECT * FROM family_widgets WHERE id=? AND deleted_at IS NULL' . ($lock?' FOR UPDATE':''));
    $query->execute([$id]); $widget=$query->fetch();
    if (!$widget || ((int)$widget['owner_id']!==(int)$user['id'] && $widget['visibility']!=='family')) throw new HttpError(404,'This widget is not available in your view.');
    return $widget;
}

function widgetsSnapshot(PDO $db, array $user): array
{
    $db->beginTransaction();
    try {
        $query = $db->prepare("SELECT w.*, u.display_name, u.nickname, h.user_id AS hidden_by
            FROM family_widgets w JOIN users u ON u.id=w.owner_id
            LEFT JOIN hidden_family_widgets h ON h.widget_id=w.id AND h.user_id=?
            WHERE w.deleted_at IS NULL AND (w.owner_id=? OR w.visibility='family') ORDER BY w.updated_at DESC,w.id DESC");
        $query->execute([$user['id'], $user['id']]);
        $rows = $query->fetchAll();
        $mealRows = []; $spinRows = [];
        if ($rows) {
            $ids = array_column($rows, 'id');
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $meals = $db->prepare('SELECT id,widget_id,title,version FROM dinner_meals WHERE widget_id IN (' . $placeholders . ') AND deleted_at IS NULL ORDER BY position,id');
            $meals->execute($ids);
            foreach ($meals->fetchAll() as $meal) $mealRows[$meal['widget_id']][] = ['id'=>(string)$meal['id'], 'title'=>$meal['title'], 'version'=>(int)$meal['version']];
            // Bound each history independently without issuing one query per widget.
            $spins = $db->prepare('SELECT recent.*,u.display_name,u.nickname FROM
                (SELECT s.*,ROW_NUMBER() OVER(PARTITION BY widget_id ORDER BY id DESC) AS pick_number FROM dinner_spins s WHERE widget_id IN (' . $placeholders . ') AND actor_id=?) recent
                JOIN users u ON u.id=recent.actor_id WHERE recent.pick_number<=10 ORDER BY recent.id DESC');
            $spins->execute(array_merge($ids,[$user['id']]));
            foreach ($spins->fetchAll() as $spin) $spinRows[$spin['widget_id']][] = widgetSpinFields($spin);
        }
        $publications=widgetPublicationsSnapshot($db,array_column($rows,'id'));
        $widgets = [];
        foreach ($rows as $row) {
            $widgets[] = ['id' => (string)$row['id'], 'kind' => $row['kind'], 'title' => $row['title'], 'visibility' => $row['visibility'],
                'owner' => ['id' => (int)$row['owner_id'], 'name' => familyDisplayName($row)], 'version' => (int)$row['version'],
                'hidden' => $row['hidden_by'] !== null, 'createdAt' => activityTimestamp($row['created_at']), 'updatedAt' => activityTimestamp($row['updated_at']),
                'meals' => $mealRows[$row['id']] ?? [], 'spins' => $spinRows[$row['id']] ?? [], 'publications'=>$publications[$row['id']]??[]];
        }
        $db->commit();
        return ['ownerId' => (int)$user['id'], 'widgets' => $widgets];
    } catch (Throwable $error) { $db->rollBack(); throw $error; }
}

function mutateWidget(PDO $db, array $user, string $operation, array $body): array
{
    if (in_array($operation,['publish','respond','eaten'],true)) return mutateWidgetDecision($db,$user,$operation,$body);
    $draft = in_array($operation, ['add', 'edit'], true) ? widgetDraft($body) : null;
    if ($draft) {
        // Use the database's Unicode comparison so composed/decomposed accents cannot weight a wheel twice.
        $parts=array_fill(0,count($draft['meals']),'SELECT CONVERT(? USING utf8mb4) AS meal');
        $distinct=$db->prepare('SELECT COUNT(DISTINCT meal COLLATE utf8mb4_unicode_ci) FROM (' . implode(' UNION ALL ',$parts) . ') choices');
        $distinct->execute(array_column($draft['meals'],'title'));
        if ((int)$distinct->fetchColumn()!==count($draft['meals'])) throw new HttpError(422,'Use a different name for each meal so every choice has an equal chance.');
    }
    $key = in_array($operation, ['add', 'spin'], true) ? widgetRequestKey($user, $body, $operation) : null;
    $id = $operation === 'add' ? null : widgetIdentifier($body['id'] ?? null);
    if (!in_array($operation, ['add','hide'], true) && (!isset($body['version']) || !is_int($body['version']) || $body['version'] < 1)) throw new HttpError(422, 'Refresh this widget before trying again.');
    $selected = null;
    $db->beginTransaction();
    try {
        if ($operation === 'add') {
            // Serialize installation limits and retries for this owner.
            $db->prepare('SELECT id FROM users WHERE id=? FOR UPDATE')->execute([$user['id']]);
            $query = $db->prepare('SELECT id FROM family_widgets WHERE request_key=?');
            $query->execute([$key]);
            $existing = $query->fetchColumn();
            if (!$existing) {
                $query = $db->prepare('SELECT COUNT(*) FROM family_widgets WHERE owner_id=? AND deleted_at IS NULL');
                $query->execute([$user['id']]);
                if ((int)$query->fetchColumn() >= 30) throw new HttpError(422, 'You can keep up to 30 widgets. Remove one before adding another.');
                foreach ($draft['meals'] as $meal) if ($meal['id'] !== null) throw new HttpError(422, 'New meal choices cannot reference another widget.');
                $db->prepare('INSERT INTO family_widgets(owner_id,title,visibility,request_key,created_at,updated_at) VALUES(?,?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))')
                    ->execute([$user['id'], $draft['title'], $draft['visibility'], $key]);
                $id = (string)$db->lastInsertId();
                $insert = $db->prepare('INSERT INTO dinner_meals(widget_id,title,position) VALUES(?,?,?)');
                foreach ($draft['meals'] as $position => $meal) $insert->execute([$id, $meal['title'], $position]);
                recordActivity($db, $user, 'widget', (int)$id, 'created', null, widgetMetadata(['owner_id'=>$user['id'], 'visibility'=>$draft['visibility'], 'version'=>1], count($draft['meals'])));
            }
        } else {
            $widget = availableWidget($db,$user,$id,true);
            $owner = $widget && (int)$widget['owner_id'] === (int)$user['id'];
            if (!in_array($operation, ['spin','hide'], true) && !$owner) throw new HttpError(403, 'Only the owner can change this widget.');
            $query = $db->prepare('SELECT * FROM dinner_meals WHERE widget_id=? AND deleted_at IS NULL ORDER BY position,id');
            $query->execute([$id]);
            $meals = $query->fetchAll();
            $before = widgetMetadata($widget, count($meals));
            if ($operation === 'hide') {
                if ($owner || !is_bool($body['hidden'] ?? null)) throw new HttpError(422, 'Choose whether to hide this shared widget.');
                if ($body['hidden']) $db->prepare('INSERT IGNORE INTO hidden_family_widgets(widget_id,user_id) VALUES(?,?)')->execute([$id,$user['id']]);
                else $db->prepare('DELETE FROM hidden_family_widgets WHERE widget_id=? AND user_id=?')->execute([$id,$user['id']]);
                recordActivity($db,$user,'widget',(int)$id,$body['hidden']?'hidden':'shown',null,['kind'=>'dinner-spinner']);
            } elseif ($operation === 'spin') {
                $query = $db->prepare('SELECT s.*,u.display_name,u.nickname FROM dinner_spins s JOIN users u ON u.id=s.actor_id WHERE s.request_key=? AND s.widget_id=? AND s.actor_id=?');
                $query->execute([$key,$id,$user['id']]);
                $spin = $query->fetch();
                if (!$spin && (int)$widget['version'] !== $body['version']) {
                    throw new HttpError(409, 'This installation changed. Review it before spinning again.', 'widget_conflict');
                }
                if (!$spin) {
                    if (count($meals) < 2) throw new HttpError(422, 'The owner needs to add at least two meals.');
                    $meal = $meals[random_int(0,count($meals)-1)];
                    $db->prepare('INSERT INTO dinner_spins(widget_id,actor_id,meal_id,meal_title,meal_version,base_version,request_key,occurred_at) VALUES(?,?,?,?,?,?,?,UTC_TIMESTAMP(6))')
                        ->execute([$id,$user['id'],$meal['id'],$meal['title'],$meal['version'],$widget['version'],$key]);
                    $spinId = $db->lastInsertId();
                    $query = $db->prepare('SELECT s.*,u.display_name,u.nickname FROM dinner_spins s JOIN users u ON u.id=s.actor_id WHERE s.id=?');
                    $query->execute([$spinId]); $spin=$query->fetch();
                    // Trials remain private to the actor, and never replace the family's suggestion.
                    recordActivity($db,$user,'widget',(int)$id,'spun',$before,array_replace($before,['spinId'=>(string)$spinId]));
                }
                $selected = widgetSpinFields($spin);
            } else {
                if ((int)$widget['version'] !== $body['version']) throw new HttpError(409, 'This widget changed in another window. Reopen its latest version before saving.', 'widget_conflict');
                if ($operation === 'edit') {
                    $existing = array_column($meals, null, 'id'); $submitted=[];
                    foreach ($draft['meals'] as $meal) {
                        if ($meal['id'] !== null) {
                            if (!isset($existing[$meal['id']]) || isset($submitted[$meal['id']])) throw new HttpError(422, 'Refresh the meal list before saving.');
                            $submitted[$meal['id']]=true;
                        }
                    }
                    if (count($submitted)!==count($existing)) throw new HttpError(422, 'Remove meals using the Remove control so you have time to Undo.');
                    foreach ($draft['meals'] as $position => $meal) {
                        if ($meal['id']===null) $db->prepare('INSERT INTO dinner_meals(widget_id,title,position) VALUES(?,?,?)')->execute([$id,$meal['title'],$position]);
                        else $db->prepare('UPDATE dinner_meals SET version=version+IF(BINARY title<>BINARY ?,1,0),title=?,position=? WHERE id=? AND widget_id=?')->execute([$meal['title'],$meal['title'],$position,$meal['id'],$id]);
                    }
                    $db->prepare('UPDATE family_widgets SET title=?,visibility=?,version=version+1,updated_at=UTC_TIMESTAMP(6) WHERE id=?')->execute([$draft['title'],$draft['visibility'],$id]);
                    $after=widgetMetadata(['owner_id'=>$widget['owner_id'],'visibility'=>$draft['visibility'],'version'=>(int)$widget['version']+1],count($draft['meals']));
                    recordActivity($db,$user,'widget',(int)$id,'edited',$before,$after);
                } elseif ($operation==='remove-meal') {
                    $mealId=widgetIdentifier($body['mealId']??null);
                    $match=array_values(array_filter($meals,static fn($meal)=>(string)$meal['id']===$mealId));
                    if (!$match) throw new HttpError(404,'This meal is no longer on the wheel.');
                    if (count($meals)<=2) throw new HttpError(422,'Keep at least two meals on the wheel. Add another before removing this one.');
                    $db->prepare('UPDATE dinner_meals SET deleted_at=UTC_TIMESTAMP(6),version=version+1 WHERE id=?')->execute([$mealId]);
                    $db->prepare('UPDATE family_widgets SET version=version+1,updated_at=UTC_TIMESTAMP(6) WHERE id=?')->execute([$id]);
                    recordActivity($db,$user,'widget',(int)$id,'meal_removed',$before,array_replace($before,['mealCount'=>count($meals)-1,'version'=>(int)$widget['version']+1]));
                } elseif ($operation==='remove') {
                    $db->prepare('UPDATE family_widgets SET deleted_at=UTC_TIMESTAMP(6),updated_at=UTC_TIMESTAMP(6),version=version+1 WHERE id=?')->execute([$id]);
                    recordActivity($db,$user,'widget',(int)$id,'removed',$before,null);
                } else throw new HttpError(404,'Unknown widget action.');
            }
        }
        $db->commit();
    } catch (Throwable $error) { $db->rollBack(); throw $error; }
    return widgetsSnapshot($db,$user)+['selectedSpin'=>$selected];
}
