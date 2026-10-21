<?php
declare(strict_types=1);

function calendarSnapshot(PDO $db): array
{
    $db->beginTransaction();
    try {
        $rows = $db->query('SELECT e.*, c.display_name AS creator_name, c.nickname AS creator_nickname,
            u.display_name AS updater_name, u.nickname AS updater_nickname
            FROM calendar_events e JOIN users c ON c.id = e.created_by JOIN users u ON u.id = e.updated_by
            WHERE e.deleted_at IS NULL ORDER BY e.starts_at, e.id')->fetchAll();
        $history = $db->query("SELECT a.*, u.nickname AS actor_nickname, u.display_name AS actor_current_name
            FROM activity_events a LEFT JOIN users u ON u.id = a.actor_id
            WHERE a.entity_type = 'calendar_event' ORDER BY a.id DESC LIMIT 100")->fetchAll();
        $db->commit();
    } catch (Throwable $error) {
        $db->rollBack();
        throw $error;
    }
    return [
        'events' => array_map(static fn(array $row): array => [
            'id' => (string) $row['id'], 'title' => $row['title'],
            'startsAt' => activityTimestamp($row['starts_at']), 'allDay' => (bool) $row['all_day'], 'annually' => (bool) $row['annually'], 'timeZone' => $row['time_zone'], 'detail' => $row['detail'],
            'version' => (int) $row['version'],
            'createdBy' => ['id' => (int) $row['created_by'], 'displayName' => familyDisplayName(['display_name' => $row['creator_name'], 'nickname' => $row['creator_nickname']])],
            'updatedBy' => ['id' => (int) $row['updated_by'], 'displayName' => familyDisplayName(['display_name' => $row['updater_name'], 'nickname' => $row['updater_nickname']])],
            'createdAt' => activityTimestamp($row['created_at']), 'updatedAt' => activityTimestamp($row['updated_at']),
        ], $rows),
        'activity' => array_map(static fn(array $row): array => [
            'id' => (string) $row['id'], 'eventId' => (string) $row['entity_id'], 'action' => $row['action'],
            'actor' => ['id' => (int) $row['actor_id'], 'displayName' => familyDisplayName(['display_name' => $row['actor_current_name'] ?? $row['actor_name'], 'nickname' => $row['actor_nickname']])],
            'occurredAt' => activityTimestamp($row['occurred_at']),
            'before' => $row['before_data'] === null ? null : json_decode($row['before_data'], true, 16, JSON_THROW_ON_ERROR),
            'after' => $row['after_data'] === null ? null : json_decode($row['after_data'], true, 16, JSON_THROW_ON_ERROR),
        ], $history),
    ];
}

function calendarText(array $body, string $field, int $max, bool $required): string
{
    $value = $body[$field] ?? null;
    if (!is_string($value) || preg_match('//u', $value) !== 1) throw new HttpError(422, 'Enter valid event details.');
    $value = trim(str_replace(["\r\n", "\r"], "\n", $value));
    $length = preg_match_all('/./us', $value);
    $controls = $field === 'detail' ? '/[\x00-\x08\x0b-\x1f\x7f]/' : '/[\x00-\x1f\x7f]/';
    if ($length === false || ($required && $length < 1) || $length > $max || preg_match($controls, $value)) {
        throw new HttpError(422, $field === 'title' ? 'Use a title of 1 to 80 characters without line breaks.' : 'Use up to 300 characters for the event notes.');
    }
    return $value;
}

function calendarDraft(array $body): array
{
    $title = calendarText($body, 'title', 80, true);
    $detail = calendarText($body, 'detail', 300, false);
    $iso = $body['startsAt'] ?? null;
    $zone = $body['timeZone'] ?? null;
    $allDay = $body['allDay'] ?? false;
    $annually = $body['annually'] ?? false;
    if (!is_bool($annually) || ($annually && !$allDay)) throw new HttpError(422, 'Annual repeats require an all-day event.');
    if (!is_bool($allDay)) throw new HttpError(422, 'Choose whether the event is all day.');
    if (!is_string($iso) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/D', $iso)) {
        throw new HttpError(422, 'Choose a valid event date and time.');
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.v\Z', $iso, new DateTimeZone('UTC'));
    if (!$date || $date->format('Y-m-d\TH:i:s.v\Z') !== $iso || $date->format('Y') < '1000' || $date->format('Y') > '9998') {
        throw new HttpError(422, 'Choose a valid event date and time.');
    }
    if (!is_string($zone) || strlen($zone) > 80 || !in_array($zone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
        throw new HttpError(422, 'Choose a valid time zone.');
    }
    if ($allDay && substr($iso, 10) !== 'T00:00:00.000Z') throw new HttpError(422, 'Choose a valid all-day date.');
    return ['title' => $title, 'startsAt' => $iso, 'allDay' => $allDay, 'annually' => $annually, 'timeZone' => $zone, 'detail' => $detail];
}

function mutateCalendar(PDO $db, array $user, string $operation, array $body): array
{
    $draft = in_array($operation, ['add', 'edit'], true) ? calendarDraft($body) : null;
    if ($operation === 'add' && (!isset($body['requestKey']) || !is_string($body['requestKey']) ||
        !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $body['requestKey']))) {
        throw new HttpError(422, 'Refresh the calendar and try adding the event again.');
    }
    if ($operation !== 'add' && (!isset($body['id'], $body['version']) || !is_string($body['id']) ||
        !ctype_digit($body['id']) || strlen($body['id']) > 18 || !is_int($body['version']) || $body['version'] < 1)) {
        throw new HttpError(422, 'Refresh the calendar and try again.');
    }
    $db->beginTransaction();
    try {
        if ($operation === 'add') {
            $key = hash('sha256', $user['id'] . ':' . $body['requestKey']);
            $insert = $db->prepare('INSERT INTO calendar_events
                (title, starts_at, all_day, annually, time_zone, detail, created_by, updated_by, created_at, updated_at, request_key)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), ?)
                ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)');
            $insert->execute([$draft['title'], (new DateTimeImmutable($draft['startsAt']))->format('Y-m-d H:i:s.u'),
                (int) $draft['allDay'], (int) $draft['annually'], $draft['timeZone'], $draft['detail'], $user['id'], $user['id'], $key]);
            if ($insert->rowCount() === 1) recordActivity($db, $user, 'calendar_event', (int) $db->lastInsertId(), 'created', null, $draft);
        } else {
            $query = $db->prepare('SELECT * FROM calendar_events WHERE id = ? AND deleted_at IS NULL FOR UPDATE');
            $query->execute([$body['id']]);
            $event = $query->fetch();
            if (!$event || (int) $event['version'] !== $body['version']) {
                throw new HttpError(409, 'Someone else changed this event. Load the latest version before saving again.', 'calendar_conflict');
            }
            $before = ['title' => $event['title'], 'startsAt' => activityTimestamp($event['starts_at']), 'allDay' => (bool) $event['all_day'], 'annually' => (bool) $event['annually'], 'timeZone' => $event['time_zone'], 'detail' => $event['detail']];
            if ($operation === 'remove' || $before !== $draft) {
                $after = $operation === 'remove' ? $before : $draft;
                $db->prepare('UPDATE calendar_events SET title = ?, starts_at = ?, all_day = ?, annually = ?, time_zone = ?, detail = ?,
                    updated_by = ?, updated_at = UTC_TIMESTAMP(6), version = version + 1, deleted_at = ' .
                    ($operation === 'remove' ? 'UTC_TIMESTAMP(6)' : 'NULL') . ' WHERE id = ?')
                    ->execute([$after['title'], (new DateTimeImmutable($after['startsAt']))->format('Y-m-d H:i:s.u'),
                        (int) $after['allDay'], (int) $after['annually'], $after['timeZone'], $after['detail'], $user['id'], $event['id']]);
                recordActivity($db, $user, 'calendar_event', (int) $event['id'], $operation === 'remove' ? 'removed' : 'edited', $before, $operation === 'remove' ? null : $after);
            }
        }
        $db->commit();
    } catch (Throwable $error) {
        $db->rollBack();
        throw $error;
    }
    return calendarSnapshot($db);
}
