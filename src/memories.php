<?php
declare(strict_types=1);
require_once __DIR__ . "/memory-photos.php";

function memoryActor(array $row, string $prefix): array
{
    return ['id' => (int) $row[$prefix . '_by'], 'displayName' => familyDisplayName([
        'display_name' => $row[$prefix . '_name'], 'nickname' => $row[$prefix . '_nickname'],
    ])];
}

function memoryAudit(array $row): array
{
    return ['title' => $row['title'], 'story' => $row['story'], 'photoKey' => $row['photo_key'], 'photoAlt' => $row['photo_alt']];
}

/** Family feeds need change flags, while exact old/new stories remain in admin audit history. */
function memoryActivitySummary(array $row): array
{
    $before = $row['before_data'] === null ? null : json_decode($row['before_data'], true, 16, JSON_THROW_ON_ERROR);
    $after = $row['after_data'] === null ? null : json_decode($row['after_data'], true, 16, JSON_THROW_ON_ERROR);
    return [
        'id' => (string)$row['id'], 'memoryId' => (string)$row['entity_id'], 'action' => $row['action'],
        'actor' => ['id' => (int)$row['actor_id'], 'displayName' => familyDisplayName([
            'display_name' => $row['current_name'] ?? $row['actor_name'], 'nickname' => $row['current_nickname'],
        ])], 'occurredAt' => activityTimestamp($row['occurred_at']),
        'before' => $before === null ? null : ['title' => $before['title'] ?? 'a memory'],
        'after' => $after === null ? null : ['title' => $after['title'] ?? 'a memory'],
        'changes' => [
            'story' => ($before['story'] ?? '') !== ($after['story'] ?? ''),
            'photo' => ($before['photoKey'] ?? null) !== ($after['photoKey'] ?? null),
            'description' => ($before['photoAlt'] ?? '') !== ($after['photoAlt'] ?? ''),
        ],
    ];
}

function memoriesSnapshot(PDO $db, array $user): array
{
    $db->beginTransaction();
    try {
        $query = $db->prepare('SELECT m.*, c.display_name AS created_name, c.nickname AS created_nickname,
            u.display_name AS updated_name, u.nickname AS updated_nickname,
            (SELECT COUNT(*) FROM memory_reactions r WHERE r.memory_id = m.id) AS reactions,
            EXISTS(SELECT 1 FROM memory_reactions r WHERE r.memory_id = m.id AND r.user_id = ?) AS liked
            FROM memories m JOIN users c ON c.id = m.created_by JOIN users u ON u.id = m.updated_by
            WHERE m.deleted_at IS NULL ORDER BY m.created_at DESC, m.id DESC');
        $query->execute([$user['id']]);
        $rows = $query->fetchAll();
        $history = $db->query("SELECT a.*, u.display_name AS current_name, u.nickname AS current_nickname
            FROM activity_events a LEFT JOIN users u ON u.id = a.actor_id
            WHERE a.entity_type = 'memory' ORDER BY a.id DESC LIMIT 100")->fetchAll();
        $db->commit();
    } catch (Throwable $error) {
        $db->rollBack();
        throw $error;
    }
    return [
        'memories' => array_map(static fn(array $row): array => [
            'id' => (string) $row['id'], 'title' => $row['title'], 'story' => $row['story'],
            'photoWidth' => $row['photo_width'] === null ? null : (int)$row['photo_width'],
            'photoHeight' => $row['photo_height'] === null ? null : (int)$row['photo_height'],
            'photoKey' => $row['photo_key'], 'photoAlt' => $row['photo_alt'], 'version' => (int) $row['version'],
            'createdBy' => memoryActor($row, 'created'), 'updatedBy' => memoryActor($row, 'updated'),
            'createdAt' => activityTimestamp($row['created_at']), 'updatedAt' => activityTimestamp($row['updated_at']),
            'reactions' => (int) $row['reactions'], 'liked' => (bool) $row['liked'],
        ], $rows),
        'activity' => array_map('memoryActivitySummary', $history),
    ];
}

function memoryRequest(): array
{
    if (!str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data;')) {
        throw new HttpError(415, 'Send memory details as a form.');
    }
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 11 * 1024 * 1024) throw new HttpError(413, 'Choose a photo up to 10 MB.');
    $raw = $_POST['payload'] ?? null;
    if (!is_string($raw) || strlen($raw) > 40000) throw new HttpError(422, 'Enter the memory details again. The upload may exceed the server limit.');
    try { $body = json_decode($raw, false, 16, JSON_THROW_ON_ERROR); }
    catch (JsonException $error) { throw new HttpError(400, 'Invalid memory details.'); }
    if (!is_object($body)) throw new HttpError(400, 'Invalid memory details.');
    return (array) $body;
}

function memoryText(array $body, string $field, int $max, bool $required = false): string
{
    $value = $body[$field] ?? '';
    if (!is_string($value) || preg_match('//u', $value) !== 1) throw new HttpError(422, 'Enter valid memory text.');
    $value = trim(str_replace(["\r\n", "\r"], "\n", $value));
    $length = preg_match_all('/./us', $value);
    if ($length === false || $length > $max || ($required && $length === 0) ||
        preg_match($field === 'story' ? '/[\x00-\x08\x0b-\x1f\x7f]/' : '/[\x00-\x1f\x7f]/', $value)) {
        throw new HttpError(422, "Use " . ($required ? '1 to ' : 'up to ') . $max . " characters for the memory " . $field . '.');
    }
    return $value;
}

function memoryUpload(): ?array
{
    if (!isset($_FILES['photo'])) return null;
    $file = $_FILES['photo'];
    if (!is_array($file) || is_array($file['error'] ?? null)) throw new HttpError(422, 'Choose one photo.');
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if ($file['error'] !== UPLOAD_ERR_OK) throw new HttpError(422, 'The photo could not be uploaded. Choose a photo up to 10 MB and try again.');
    if (!is_uploaded_file($file['tmp_name']) || filesize($file['tmp_name']) > 10 * 1024 * 1024) throw new HttpError(413, 'Choose a photo up to 10 MB.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $info = @getimagesize($file['tmp_name']);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) || !$info || $info['mime'] !== $mime) {
        throw new HttpError(422, 'Choose a valid JPG, PNG or WebP photo.');
    }
    if ($info[0] < 1 || $info[1] < 1 || $info[0] > 8192 || $info[1] > 8192 || $info[0] * $info[1] > 24000000) {
        throw new HttpError(422, 'Resize the photo to 24 megapixels or less, with no side longer than 8192 pixels.');
    }
    $dimensions = memoryPhotoInfo($file['tmp_name'], $mime);
    return ['tmp' => $file['tmp_name'], 'mime' => $mime, 'width' => $dimensions['width'] ?? $info[0], 'height' => $dimensions['height'] ?? $info[1]];
}

function mutateMemory(PDO $db, array $config, array $user, string $operation, array $body): array
{
    $draft = null;
    $upload = null;
    if (in_array($operation, ['add', 'edit'], true)) {
        $draft = ['title' => memoryText($body, 'title', 80, true), 'story' => memoryText($body, 'story', 5000), 'photoAlt' => memoryText($body, 'photoAlt', 160)];
        if (isset($body['removePhoto']) && !is_bool($body['removePhoto'])) throw new HttpError(422, 'Choose whether to remove the photo.');
        $upload = memoryUpload();
        if ($upload && ($body['removePhoto'] ?? false)) throw new HttpError(422, 'Choose a new photo or remove the current one.');
    }
    if ($operation === 'add') {
        if (!isset($body['requestKey']) || !is_string($body['requestKey']) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $body['requestKey'])) throw new HttpError(422, 'Reopen the form and try again.');
    } elseif (!isset($body['id']) || !is_string($body['id']) || !ctype_digit($body['id']) || strlen($body['id']) > 18 ||
        ($operation !== 'react' && (!isset($body['version']) || !is_int($body['version']) || $body['version'] < 1))) {
        throw new HttpError(422, 'Refresh the memories and try again.');
    }
    if ($operation === 'react' && (!isset($body['liked']) || !is_bool($body['liked']))) throw new HttpError(422, 'Choose a reaction.');
    $newPath = null;
    $oldKey = null;
    $committed = false;
    $commitAttempted = false;
    $db->beginTransaction();
    try {
        $old = null;
        if ($operation === 'add') {
            $requestKey = hash('sha256', $user['id'] . ':' . $body['requestKey']);
            $query = $db->prepare('SELECT id FROM memories WHERE request_key = ?');
            $query->execute([$requestKey]);
            if ($query->fetch()) {
                $db->commit();
                return memoriesSnapshot($db, $user);
            }
        } else {
            $query = $db->prepare('SELECT * FROM memories WHERE id = ? AND deleted_at IS NULL FOR UPDATE');
            $query->execute([$body['id']]);
            $old = $query->fetch();
            if (!$old || ($operation !== 'react' && (int) $old['version'] !== $body['version'])) throw new HttpError(409, 'This memory changed. Load the latest version before saving again.', 'memory_conflict');
            if ($operation === 'remove' && (int) $old['created_by'] !== (int) $user['id'] && $user['role'] !== 'admin') throw new HttpError(403, 'Only the person who shared this memory or an administrator can remove it.');
        }
        $key = $old['photo_key'] ?? null;
        $mime = $old['photo_mime'] ?? null;
        $photoWidth = $old['photo_width'] ?? null; $photoHeight = $old['photo_height'] ?? null;
        if ($draft) {
            if ($body['removePhoto'] ?? false) { $key = null; $mime = null; $photoWidth = null; $photoHeight = null; }
            if ($upload) {
                $key = bin2hex(random_bytes(32));
                $mime = $upload['mime'];
                $photoWidth = $upload['width']; $photoHeight = $upload['height'];
            }
            if (!$key && $draft['story'] === '') throw new HttpError(422, 'Add a photo or write a story.');
            if ($upload) {
                $newPath = memoryPhotoPath($config, $key);
                $directory = dirname($newPath);
                if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('Unable to create private photo storage.');
                if (!move_uploaded_file($upload['tmp'], $newPath)) throw new RuntimeException('Unable to save photo.');
                @chmod($newPath, 0600);
            }
        }
        if ($operation === 'add') {
            $query = $db->prepare('INSERT INTO memories (title, story, photo_key, photo_mime, photo_width, photo_height, photo_alt, created_by, updated_by, created_at, updated_at, request_key)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), ?)');
            $query->execute([$draft['title'], $draft['story'], $key, $mime, $photoWidth, $photoHeight, $draft['photoAlt'], $user['id'], $user['id'], $requestKey]);
            recordActivity($db, $user, 'memory', (int) $db->lastInsertId(), 'created', null, $draft + ['photoKey' => $key]);
        } elseif ($operation === 'react') {
            $query = $db->prepare($body['liked'] ? 'INSERT IGNORE INTO memory_reactions (memory_id, user_id, created_at) VALUES (?, ?, UTC_TIMESTAMP(6))' : 'DELETE FROM memory_reactions WHERE memory_id = ? AND user_id = ?');
            $query->execute([$old['id'], $user['id']]);
            if ($query->rowCount()) recordActivity($db, $user, 'memory', (int) $old['id'], $body['liked'] ? 'liked' : 'unliked', ['title' => $old['title'], 'liked' => !$body['liked']], ['title' => $old['title'], 'liked' => $body['liked']]);
        } else {
            $before = memoryAudit($old);
            $after = $operation === 'remove' ? $before : $draft + ['photoKey' => $key];
            // Compare values independently of JSON/object property order.
            if ($operation === 'remove' || $before != $after) {
                $db->prepare('UPDATE memories SET title = ?, story = ?, photo_key = ?, photo_mime = ?, photo_width = ?, photo_height = ?, photo_alt = ?, updated_by = ?, updated_at = UTC_TIMESTAMP(6), version = version + 1,
                    deleted_at = ' . ($operation === 'remove' ? 'UTC_TIMESTAMP(6)' : 'NULL') . ' WHERE id = ?')
                    ->execute([$after['title'], $after['story'], $key, $mime, $photoWidth, $photoHeight, $after['photoAlt'], $user['id'], $old['id']]);
                recordActivity($db, $user, 'memory', (int) $old['id'], $operation === 'remove' ? 'removed' : 'edited', $before, $operation === 'remove' ? null : $after);
                if ($old['photo_key'] !== $key) $oldKey = $old['photo_key'];
            }
        }
        $commitAttempted = true;
        $db->commit();
        $committed = true;
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        // A lost connection during COMMIT has an uncertain outcome: retain its file.
        if (!$committed && !$commitAttempted && $newPath && is_file($newPath)) @unlink($newPath);
        // Concurrent retries share a unique request key; return the committed result.
        if ($operation === 'add' && $error instanceof PDOException && ($error->errorInfo[1] ?? null) === 1062) return memoriesSnapshot($db, $user);
        throw $error;
    }
    if ($oldKey) removeMemoryPhoto($config, $oldKey);
    return memoriesSnapshot($db, $user);
}
