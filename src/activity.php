<?php
declare(strict_types=1);

function adminActivityTypes(): array
{
    // Daily Word exposes lifecycle/publication metadata only to administrators.
    // It is a separate entity so private installation events cannot change family widget ETags.
    return ['shopping_item', 'calendar_event', 'memory', 'poll', 'user_profile', 'user_account', 'widget', 'daily_word', 'focus_timer'];
}

// Call inside the same transaction as the domain write. Actor identity comes only from the session.
function recordActivity(PDO $db, array $user, string $entityType, int $entityId, string $action, ?array $before, ?array $after): void
{
    if (in_array($entityType, ['calendar_event', 'shopping_item'], true)) {
        // Lock BEFORE allocating the audit ID, held until the domain transaction commits.
        // Otherwise a lower, still-uncommitted ID could be lost behind a reviewed cursor.
        $db->query('SELECT id FROM home_recap_clock WHERE id = 1 FOR UPDATE')->fetch();
    }
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
