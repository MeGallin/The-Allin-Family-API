<?php
declare(strict_types=1);

function sharedActivityTypes(): array
{
    return ['shopping_item', 'calendar_event', 'memory', 'poll', 'user_profile', 'user_account'];
}

// Call inside the same transaction as the domain write. Actor identity comes only from the session.
function recordActivity(PDO $db, array $user, string $entityType, int $entityId, string $action, ?array $before, ?array $after): void
{
    $statement = $db->prepare('INSERT INTO activity_events
        (entity_type, entity_id, action, actor_id, actor_name, occurred_at, before_data, after_data)
        VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(6), ?, ?)');
    $statement->execute([
        $entityType, $entityId, $action, $user['id'], $user['display_name'],
        $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
        $after === null ? null : json_encode($after, JSON_THROW_ON_ERROR),
    ]);
}

function activityTimestamp(string $value): string
{
    return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
}
