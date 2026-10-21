<?php
declare(strict_types=1);

function adminActivitySnapshot(PDO $db, array $user, array $query): array
{
    requireFamilyAdmin($user);
    $types = sharedActivityTypes();
    $type = $query['type'] ?? '';
    $actor = $query['actor'] ?? '';
    $before = $query['before'] ?? '';
    if (!is_string($type) || ($type !== '' && !in_array($type, $types, true))) {
        throw new HttpError(422, 'Choose an activity type.');
    }
    foreach ([$actor, $before] as $value) {
        if (!is_string($value) || ($value !== '' && (!ctype_digit($value) || strlen($value) > 18 || (int) $value < 1))) {
            throw new HttpError(422, 'Choose a valid activity filter.');
        }
    }
    // This allowlist excludes private notes even if another feature accidentally logs them here.
    $where = 'a.entity_type IN (' . implode(',', array_fill(0, count($types), '?')) . ')';
    $args = $types;
    foreach (['a.entity_type' => $type, 'a.actor_id' => $actor, 'a.id' => $before] as $field => $value) {
        if ($value !== '') {
            $where .= ' AND ' . $field . ($field === 'a.id' ? ' < ?' : ' = ?');
            $args[] = $value;
        }
    }
    $statement = $db->prepare("SELECT a.*, u.nickname, u.display_name AS current_name,
        target.display_name AS target_name, target.nickname AS target_nickname
        FROM activity_events a LEFT JOIN users u ON u.id = a.actor_id
        LEFT JOIN users target ON a.entity_type IN ('user_profile', 'user_account') AND target.id = a.entity_id
        WHERE " . $where . ' ORDER BY a.id DESC LIMIT 51');
    $statement->execute($args);
    $rows = $statement->fetchAll();
    $more = count($rows) > 50;
    $rows = array_slice($rows, 0, 50);
    return [
        'entries' => array_map(static fn(array $row): array => [
            'id' => (string) $row['id'], 'entityType' => $row['entity_type'],
            'entityId' => (string) $row['entity_id'], 'action' => $row['action'],
            'actor' => ['id' => (int) $row['actor_id'], 'displayName' => familyDisplayName([
                'display_name' => $row['current_name'] ?? $row['actor_name'], 'nickname' => $row['nickname'],
            ])],
            'targetName' => $row['target_name'] === null ? null : familyDisplayName([
                'display_name' => $row['target_name'], 'nickname' => $row['target_nickname'],
            ]),
            'occurredAt' => activityTimestamp($row['occurred_at']),
            'before' => $row['before_data'] === null ? null : json_decode($row['before_data'], true, 16, JSON_THROW_ON_ERROR),
            'after' => $row['after_data'] === null ? null : json_decode($row['after_data'], true, 16, JSON_THROW_ON_ERROR),
        ], $rows),
        'nextCursor' => $more ? (string) $rows[count($rows) - 1]['id'] : null,
    ];
}
