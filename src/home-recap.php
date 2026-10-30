<?php
declare(strict_types=1);

// Only the two shared, whole-family sources are eligible. Never project generic audit payloads.
function homeRecapSnapshot(PDO $db, array $user): array
{
    $db->beginTransaction();
    try {
        $db->query('SELECT id FROM home_recap_clock WHERE id = 1 FOR UPDATE')->fetch();
        $through = (string) $db->query("SELECT COALESCE(MAX(id), 0) FROM activity_events
            WHERE entity_type IN ('calendar_event', 'shopping_item')")->fetchColumn();
        // First use starts at this boundary, without showing any historical backlog.
        $db->prepare('INSERT IGNORE INTO home_recap_reviews (user_id, reviewed_id, offered_id) VALUES (?, ?, ?)')
            ->execute([$user['id'], $through, $through]);
        $cursor = $db->prepare('SELECT reviewed_id FROM home_recap_reviews WHERE user_id = ? FOR UPDATE');
        $cursor->execute([$user['id']]);
        $reviewed = (string) $cursor->fetchColumn();
        $events = $db->prepare("SELECT e.*, u.display_name, u.nickname FROM activity_events e
            JOIN users u ON u.id = e.actor_id
            WHERE e.id > ? AND e.id <= ? AND e.actor_id <> ?
                AND e.entity_type IN ('calendar_event', 'shopping_item')
                AND e.action IN ('created', 'edited', 'checked', 'unchecked', 'removed')
            ORDER BY e.id DESC");
        $events->execute([$reviewed, $through, $user['id']]);
        $items = [];
        foreach ($events->fetchAll() as $event) {
            $key = $event['entity_type'] . ':' . $event['entity_id'];
            if (isset($items[$key])) continue;
            $before = $event['before_data'] === null ? null : json_decode($event['before_data'], true, 16, JSON_THROW_ON_ERROR);
            $after = $event['after_data'] === null ? null : json_decode($event['after_data'], true, 16, JSON_THROW_ON_ERROR);
            if ($before == $after) continue;
            $data = $after ?? $before;
            if (!is_array($data) || !is_string($data['title'] ?? null)) continue;
            $calendar = $event['entity_type'] === 'calendar_event';
            $change = match ($event['action']) {
                'created' => 'Added', 'removed' => 'Removed', 'checked' => 'Picked up',
                'unchecked' => 'Put back on the list', default => 'Updated',
            };
            if ($event['action'] === 'edited' && !$calendar && ($before['title'] ?? null) === ($after['title'] ?? null)
                && ($before['important'] ?? false) !== ($after['important'] ?? false)) {
                $change = ($after['important'] ?? false) ? 'Marked important' : 'Cleared importance for';
            }
            if ($event['action'] === 'edited' && $calendar) {
                $change = match (true) {
                    ($before['startsAt'] ?? null) !== ($after['startsAt'] ?? null) => 'Rescheduled',
                    ($before['title'] ?? null) !== ($after['title'] ?? null) => 'Renamed',
                    ($before['allDay'] ?? false) !== ($after['allDay'] ?? false),
                    ($before['timeZone'] ?? null) !== ($after['timeZone'] ?? null) => 'Changed timing for',
                    ($before['annually'] ?? false) !== ($after['annually'] ?? false) => 'Changed annual repeat for',
                    default => 'Updated notes for',
                };
            }
            // Deliberately whitelist titles, attribution and fixed navigation identifiers only.
            // Calendar notes, raw before/after JSON and private source payloads never leave here.
            $items[$key] = [
                'id' => (string) $event['id'], 'source' => $calendar ? 'calendar' : 'shopping',
                'recordId' => (string) $event['entity_id'], 'title' => $data['title'], 'change' => $change,
                'actor' => familyDisplayName($event), 'occurredAt' => activityTimestamp($event['occurred_at']),
                'removed' => $event['action'] === 'removed',
                ...($calendar ? ['startsAt' => $data['startsAt'], 'allDay' => (bool) ($data['allDay'] ?? false)] : []),
            ];
        }
        $db->prepare('UPDATE home_recap_reviews SET offered_id = GREATEST(offered_id, CAST(? AS UNSIGNED)) WHERE user_id = ?')
            ->execute([$through, $user['id']]);
        $db->commit();
        return ['ownerId' => (int) $user['id'], 'throughId' => $through, 'items' => array_values($items)];
    } catch (Throwable $error) {
        $db->rollBack();
        throw $error;
    }
}

function reviewHomeRecap(PDO $db, array $user, array $body): array
{
    $through = $body['throughId'] ?? null;
    if (!is_string($through) || !preg_match('/^(0|[1-9][0-9]{0,19})$/D', $through)
        || (strlen($through) === 20 && strcmp($through, '18446744073709551615') > 0)) {
        throw new HttpError(422, 'Refresh your updates and try again.');
    }
    $db->beginTransaction();
    try {
        $cursor = $db->prepare('SELECT offered_id FROM home_recap_reviews WHERE user_id = ? FOR UPDATE');
        $cursor->execute([$user['id']]);
        $offered = $cursor->fetchColumn();
        if ($offered === false || strlen($through) > strlen((string) $offered)
            || (strlen($through) === strlen((string) $offered) && strcmp($through, (string) $offered) > 0)) {
            throw new HttpError(409, 'Refresh your updates before marking them reviewed.');
        }
        // Monotonic, retry-safe and bounded to the caller's displayed snapshot, never MAX(id).
        $db->prepare('UPDATE home_recap_reviews SET reviewed_id = GREATEST(reviewed_id, CAST(? AS UNSIGNED)) WHERE user_id = ?')
            ->execute([$through, $user['id']]);
        $db->commit();
        return ['reviewed' => true];
    } catch (Throwable $error) {
        $db->rollBack();
        throw $error;
    }
}
