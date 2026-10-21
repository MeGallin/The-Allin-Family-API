<?php
declare(strict_types=1);

function shoppingSnapshot(PDO $db): array
{
    // A consistent read keeps the list and its recent history in agreement.
    $db->beginTransaction();
    try {
        $rows = $db->query('SELECT s.*, c.display_name AS creator_name, u.display_name AS updater_name,
            c.nickname AS creator_nickname, u.nickname AS updater_nickname
            FROM shopping_items s JOIN users c ON c.id = s.created_by JOIN users u ON u.id = s.updated_by
            WHERE s.deleted_at IS NULL ORDER BY s.created_at DESC, s.id DESC')->fetchAll();
        $events = $db->query("SELECT e.*, u.nickname AS actor_nickname, u.display_name AS actor_current_name
            FROM activity_events e LEFT JOIN users u ON u.id = e.actor_id
            WHERE e.entity_type = 'shopping_item' ORDER BY e.id DESC LIMIT 100")->fetchAll();
        $db->commit();
    } catch (Throwable $error) {
        $db->rollBack();
        throw $error;
    }
    return [
        'items' => array_map(static fn(array $row): array => [
            'id' => (string) $row['id'], 'title' => $row['title'], 'checked' => (bool) $row['checked'],
            'version' => (int) $row['version'],
            'createdBy' => ['id' => (int) $row['created_by'], 'displayName' => familyDisplayName(['display_name' => $row['creator_name'], 'nickname' => $row['creator_nickname']])],
            'updatedBy' => ['id' => (int) $row['updated_by'], 'displayName' => familyDisplayName(['display_name' => $row['updater_name'], 'nickname' => $row['updater_nickname']])],
            'createdAt' => activityTimestamp($row['created_at']), 'updatedAt' => activityTimestamp($row['updated_at']),
        ], $rows),
        'activity' => array_map(static fn(array $row): array => [
            'id' => (string) $row['id'], 'itemId' => (string) $row['entity_id'], 'action' => $row['action'],
            'actor' => ['id' => (int) $row['actor_id'], 'displayName' => familyDisplayName(['display_name' => $row['actor_current_name'] ?? $row['actor_name'], 'nickname' => $row['actor_nickname']])],
            'occurredAt' => activityTimestamp($row['occurred_at']),
            'before' => $row['before_data'] === null ? null : json_decode($row['before_data'], true, 16, JSON_THROW_ON_ERROR),
            'after' => $row['after_data'] === null ? null : json_decode($row['after_data'], true, 16, JSON_THROW_ON_ERROR),
        ], $events),
    ];
}

function shoppingTitle(array $body): string
{
    $title = $body['title'] ?? null;
    if (!is_string($title) || preg_match('//u', $title) !== 1) throw new HttpError(422, 'Enter an item name.');
    $title = trim($title);
    $length = preg_match_all('/./us', $title);
    if ($length === false || $length < 1 || $length > 100 || preg_match('/[\x00-\x1f\x7f]/', $title)) {
        throw new HttpError(422, 'Use an item name of 1 to 100 characters without line breaks.');
    }
    return $title;
}

function mutateShopping(PDO $db, array $user, string $operation, array $body): array
{
    $title = in_array($operation, ['add', 'edit'], true) ? shoppingTitle($body) : null;
    if ($operation === 'add' && (!isset($body['requestKey']) || !is_string($body['requestKey']) ||
        !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $body['requestKey']))) {
        throw new HttpError(422, 'Refresh the page and try adding the item again.');
    }
    if ($operation === 'check' && (!array_key_exists('checked', $body) || !is_bool($body['checked']))) {
        throw new HttpError(422, 'Choose whether the item is picked up.');
    }
    if ($operation !== 'add' && (!isset($body['id'], $body['version']) ||
        !is_string($body['id']) || !ctype_digit($body['id']) || strlen($body['id']) > 18 ||
        !is_int($body['version']) || $body['version'] < 1)) {
        throw new HttpError(422, 'Refresh the list and try again.');
    }
    $db->beginTransaction();
    try {
        if ($operation === 'add') {
            // The key is scoped to this user so retries cannot duplicate an already-saved addition.
            $requestKey = substr(hash('sha256', $user['id'] . ':' . $body['requestKey']), 0, 36);
            $insert = $db->prepare('INSERT INTO shopping_items (title, created_by, updated_by, created_at, updated_at, request_key)
                VALUES (?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), ?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)');
            $insert->execute([$title, $user['id'], $user['id'], $requestKey]);
            $id = (int) $db->lastInsertId();
            if ($insert->rowCount() === 1) recordActivity($db, $user, 'shopping_item', $id, 'created', null, ['title' => $title, 'checked' => false]);
        } else {
            $query = $db->prepare('SELECT * FROM shopping_items WHERE id = ? AND deleted_at IS NULL FOR UPDATE');
            $query->execute([$body['id']]);
            $item = $query->fetch();
            if (!$item || (int) $item['version'] !== $body['version']) {
                throw new HttpError(409, 'Someone else changed this item. The list has been refreshed; please try again.', 'shopping_conflict');
            }
            $id = (int) $item['id'];
            $before = ['title' => $item['title'], 'checked' => (bool) $item['checked']];
            $after = $before;
            if ($operation === 'edit') $after['title'] = $title;
            if ($operation === 'check') $after['checked'] = $body['checked'];
            if ($operation === 'remove' || $before !== $after) {
                $db->prepare('UPDATE shopping_items SET title = ?, checked = ?, updated_by = ?,
                    updated_at = UTC_TIMESTAMP(6), version = version + 1, deleted_at = ' .
                    ($operation === 'remove' ? 'UTC_TIMESTAMP(6)' : 'NULL') . ' WHERE id = ?')
                    ->execute([$after['title'], (int) $after['checked'], $user['id'], $id]);
                $action = $operation === 'remove' ? 'removed' : ($operation === 'edit' ? 'edited' : ($after['checked'] ? 'checked' : 'unchecked'));
                recordActivity($db, $user, 'shopping_item', $id, $action, $before, $operation === 'remove' ? null : $after);
            }
        }
        $db->commit();
    } catch (Throwable $error) {
        $db->rollBack();
        throw $error;
    }
    return shoppingSnapshot($db);
}
