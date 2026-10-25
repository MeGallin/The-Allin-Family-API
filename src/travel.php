<?php
declare(strict_types=1);
require_once __DIR__ . '/public-feeds.php';

function travelLines(): array
{
    $groups = [
        'Tube' => ['bakerloo' => 'Bakerloo', 'central' => 'Central', 'circle' => 'Circle', 'district' => 'District',
            'hammersmith-city' => 'Hammersmith & City', 'jubilee' => 'Jubilee', 'metropolitan' => 'Metropolitan',
            'northern' => 'Northern', 'piccadilly' => 'Piccadilly', 'victoria' => 'Victoria', 'waterloo-city' => 'Waterloo & City'],
        'Overground' => ['liberty' => 'Liberty', 'lioness' => 'Lioness', 'mildmay' => 'Mildmay',
            'suffragette' => 'Suffragette', 'weaver' => 'Weaver', 'windrush' => 'Windrush'],
        'Other TfL rail' => ['dlr' => 'DLR', 'elizabeth' => 'Elizabeth line'],
    ];
    $lines = [];
    foreach ($groups as $group => $entries) foreach ($entries as $id => $name) $lines[] = compact('id', 'name', 'group');
    return $lines;
}

function travelPreferences(PDO $db, array $user): array
{
    $query = $db->prepare('SELECT lines_json, version FROM user_travel_preferences WHERE user_id = ?');
    $query->execute([$user['id']]);
    $row = $query->fetch();
    return ['lines' => $row ? json_decode($row['lines_json'], true, 16, JSON_THROW_ON_ERROR) : [],
        'version' => $row ? (int)$row['version'] : 0, 'availableLines' => travelLines()];
}

function validatedTravelLines(mixed $lines): array
{
    if (!is_array($lines) || array_values($lines) !== $lines || count($lines) > 19
        || count(array_filter($lines, 'is_string')) !== count($lines)
        || array_diff($lines, array_column(travelLines(), 'id')) || count(array_unique($lines)) !== count($lines)) {
        throw new HttpError(422, 'Choose valid TfL lines.');
    }
    sort($lines);
    return $lines;
}

function saveTravelPreferences(PDO $db, array $user, array $body): array
{
    $lines = validatedTravelLines($body['lines'] ?? null);
    if (!is_int($body['version'] ?? null) || $body['version'] < 0) throw new HttpError(422, 'Reload your TfL preferences before saving.');
    $db->beginTransaction();
    try {
        $lock = $db->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
        $lock->execute([$user['id']]);
        $before = travelPreferences($db, $user);
        if ($before['version'] !== $body['version']) throw new HttpError(409, 'Your TfL choices changed elsewhere. Reload them before saving.', 'travel_preferences_conflict');
        if ($before['lines'] !== $lines || $before['version'] === 0) {
            $db->prepare('INSERT INTO user_travel_preferences (user_id, lines_json, version, updated_at)
                VALUES (?, ?, ?, UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE lines_json = VALUES(lines_json),
                version = VALUES(version), updated_at = VALUES(updated_at)')
                ->execute([$user['id'], json_encode($lines, JSON_THROW_ON_ERROR), $before['version'] + 1]);
            $names = array_column(travelLines(), 'name', 'id');
            recordActivity($db, $user, 'user_profile', (int)$user['id'], 'travel_preferences_changed',
                ['travelLines' => array_map(static fn($id) => $names[$id], $before['lines'])],
                ['travelLines' => array_map(static fn($id) => $names[$id], $lines)]);
        }
        $db->commit();
        return travelPreferences($db, $user);
    } catch (Throwable $error) { $db->rollBack(); throw $error; }
}

function travelText(mixed $value, int $limit): string
{
    if (!is_string($value) || strlen($value) > $limit || preg_match('//u', $value) !== 1) throw new RuntimeException('Invalid TfL text.');
    return trim(strip_tags(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
}

function travelInstant(mixed $value): DateTimeImmutable
{
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $value)) throw new RuntimeException('Invalid TfL validity.');
    $date = new DateTimeImmutable($value);
    $errors = DateTimeImmutable::getLastErrors();
    if ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) throw new RuntimeException('Invalid TfL date.');
    return $date;
}

/** A complete allowlisted network response is required; malformed/partial data is never an all-clear. */
function travelNotices(array $raw, bool $planned, int $now): array
{
    $catalog = array_column(travelLines(), null, 'id');
    $seen = []; $notices = [];
    foreach ($raw as $line) {
        if (!is_array($line) || !is_string($line['id'] ?? null) || !isset($catalog[$line['id']])
            || isset($seen[$line['id']]) || !is_array($line['lineStatuses'] ?? null) || !$line['lineStatuses']) throw new RuntimeException('Invalid TfL line response.');
        $id = $line['id']; $seen[$id] = true;
        foreach ($line['lineStatuses'] as $status) {
            if (!is_array($status) || !is_int($status['statusSeverity'] ?? null)
                || !is_array($status['validityPeriods'] ?? null)) throw new RuntimeException('Invalid TfL status.');
            $severity = $status['statusSeverity'];
            if (in_array($severity, [10, 18], true)) continue;
            if ($planned && ($status['disruption']['category'] ?? null) !== 'PlannedWork') continue;
            $heading = travelText($status['statusSeverityDescription'] ?? null, 150);
            if ($heading === '') throw new RuntimeException('Missing TfL status.');
            $description = travelText($status['reason'] ?? $status['disruption']['description'] ?? '', 20000);
            $advice = travelText($status['disruption']['additionalInfo'] ?? '', 20000);
            $periods = $status['validityPeriods'];
            if (!$periods && $planned) continue;
            if (!$periods) $periods = [null];
            foreach ($periods as $period) {
                $start = $end = null;
                if ($period !== null) {
                    if (!is_array($period)) throw new RuntimeException('Invalid TfL period.');
                    $start = travelInstant($period['fromDate'] ?? null);
                    $end = travelInstant($period['toDate'] ?? null);
                    if ($end <= $start) throw new RuntimeException('Reversed TfL interval.');
                    if ($end->getTimestamp() <= $now || (!$planned && $start->getTimestamp() > $now)) continue;
                }
                $startsAt = $start ? $start->format('c') : null;
                $endsAt = $end ? $end->format('c') : null;
                $key = hash('sha256', json_encode([$id, $heading, $description, $startsAt, $endsAt], JSON_THROW_ON_ERROR));
                $notices[$key] = ['id' => $key, 'lineId' => $id, 'line' => $catalog[$id]['name'],
                    'status' => $heading, 'severity' => $severity, 'description' => $description,
                    'advice' => $advice, 'planned' => $planned, 'startsAt' => $startsAt, 'endsAt' => $endsAt];
            }
        }
    }
    if (count($seen) !== count($catalog)) throw new RuntimeException('Incomplete TfL response.');
    return array_values($notices);
}

function travelSnapshot(array $config, array $preferences): array
{
    $off = ['status' => 'unconfigured', 'updatedAt' => null, 'data' => null];
    $today = new DateTimeImmutable('today', new DateTimeZone('Europe/London'));
    $through = $today->modify('+30 days')->format('Y-m-d');
    if (!$preferences['lines']) return ['lines' => [], 'live' => $off, 'planned' => $off, 'through' => $through];
    $directory = dirname($config['SESSION_DIRECTORY']) . '/public-feeds';
    $ids = implode(',', array_column(travelLines(), 'id'));
    // National network cache, independent of the user's choices. No personal data goes to TfL.
    $live = cachedPublicFeed($directory, 'tfl-live-v1', 30, 90, static fn() => travelNotices(
        publicFeedJson('https://api.tfl.gov.uk/Line/' . $ids . '/Status?detail=false'), false, time()));
    $planned = cachedPublicFeed($directory, 'tfl-planned-v1:' . $today->format('Y-m-d'), 1800, 3600,
        static fn() => travelNotices(publicFeedJson('https://api.tfl.gov.uk/Line/' . $ids . '/Status/'
            . $today->format('Y-m-d') . '/to/' . $through . '?detail=false'), true, time()));
    $filter = static function (array $feed) use ($preferences): array {
        $feed['data'] = $feed['data'] === null ? null : array_values(array_filter($feed['data'],
            static fn($notice) => in_array($notice['lineId'], $preferences['lines'], true)
                && ($notice['endsAt'] === null || strtotime($notice['endsAt']) > time())));
        return $feed;
    };
    return ['lines' => $preferences['lines'], 'live' => $filter($live), 'planned' => $filter($planned), 'through' => $through];
}
