<?php
declare(strict_types=1);

// Only section names are stored: never note IDs, titles, search terms or query strings.
function usagePage($page): string
{
    $pages = ['/', '/calendar', '/memories', '/lists', '/polls', '/notes', '/profile', '/admin'];
    $page = is_string($page) ? (rtrim($page, '/') ?: '/') : null;
    return in_array($page, $pages, true) ? $page : '/other';
}

function beginPlatformSession(PDO $db, array $user, bool $login, $page = '/'): void
{
    $key = bin2hex(random_bytes(32));
    $db->prepare('INSERT INTO platform_sessions
        (tracking_key,user_id,started_at,login_at,last_seen_at,last_signal_at,last_page)
        VALUES (?, ?, UTC_TIMESTAMP(), IF(? = 1, UTC_TIMESTAMP(), NULL), UTC_TIMESTAMP(), UTC_TIMESTAMP(), ?)')
        ->execute([$key, $user['id'], $login ? 1 : 0, usagePage($page)]);
    $_SESSION['usage_key'] = $key;
}

function endPlatformSession(PDO $db, string $reason, $page = null): void
{
    $key = $_SESSION['usage_key'] ?? null;
    if (!is_string($key)) return;
    $explicit = $reason === 'logout';
    // Server time bounds each active interval; a missing heartbeat is never counted as time online.
    $db->prepare('UPDATE platform_sessions SET
        active_seconds = active_seconds + IF(? = 1 AND was_active = 1 AND
            TIMESTAMPDIFF(SECOND,last_signal_at,UTC_TIMESTAMP()) BETWEEN 0 AND 60,
            TIMESTAMPDIFF(SECOND,last_signal_at,UTC_TIMESTAMP()),0),
        last_page = IF(? = 1, ?, last_page),
        ended_at = IF(? = 1, UTC_TIMESTAMP(), last_seen_at), end_reason = ?, was_active = 0,
        last_seen_at = IF(? = 1, UTC_TIMESTAMP(), last_seen_at)
        WHERE tracking_key = ? AND user_id = ? AND ended_at IS NULL')
        ->execute([$explicit ? 1 : 0, $explicit && $page !== null ? 1 : 0, usagePage($page),
            $explicit ? 1 : 0, $reason, $explicit ? 1 : 0, $key, $_SESSION['user_id'] ?? 0]);
    unset($_SESSION['usage_key']);
}

function recordPlatformPresence(PDO $db, array $user, array $body): array
{
    if (!is_bool($body['active'] ?? null) || !is_string($body['page'] ?? null)) {
        throw new HttpError(422, 'Provide a page and activity status.');
    }
    // Sessions already open when tracking was introduced are not invented logins.
    if (!isset($_SESSION['usage_key'])) {
        if (!$body['active']) return ['ok' => true];
        beginPlatformSession($db, $user, false, $body['page']);
    }
    $db->prepare('UPDATE platform_sessions SET
        active_seconds = active_seconds + IF(was_active = 1 AND
            TIMESTAMPDIFF(SECOND,last_signal_at,UTC_TIMESTAMP()) BETWEEN 0 AND 60,
            TIMESTAMPDIFF(SECOND,last_signal_at,UTC_TIMESTAMP()),0),
        last_seen_at = IF(? = 1, UTC_TIMESTAMP(), last_seen_at),
        last_page = IF(? = 1, ?, last_page), last_signal_at = UTC_TIMESTAMP(), was_active = ?
        WHERE tracking_key = ? AND user_id = ? AND ended_at IS NULL')
        ->execute([$body['active'] ? 1 : 0, $body['active'] ? 1 : 0, usagePage($body['page']),
            $body['active'] ? 1 : 0, $_SESSION['usage_key'], $user['id']]);
    return ['ok' => true];
}

function platformSessionView(array $row): array
{
    $gap = max(0, time() - strtotime($row['last_seen_at'] . ' UTC'));
    return [
        'id' => (string)$row['id'], 'userId' => (int)$row['user_id'],
        'name' => familyDisplayName($row),
        'startedAt' => activityTimestamp($row['started_at']),
        'loginAt' => $row['login_at'] === null ? null : activityTimestamp($row['login_at']),
        'lastSeenAt' => activityTimestamp($row['last_seen_at']),
        'lastPage' => usagePage($row['last_page']), 'activeSeconds' => (int)$row['active_seconds'],
        'endedAt' => $row['ended_at'] === null ? null : activityTimestamp($row['ended_at']),
        'status' => $row['end_reason'] ?? ($gap <= 60 ? 'active' :
            ($gap <= 120 ? 'recent' : 'inactive')),
    ];
}

function adminUsageSnapshot(PDO $db, array $user, array $query): array
{
    requireFamilyAdmin($user);
    $days = familyReportingPeriod($query, true);
    $person = $query['user'] ?? '';
    $before = $query['before'] ?? '';
    foreach ([$person,$before] as $value) {
        if (!is_string($value) || ($value !== '' && (!ctype_digit($value) || strlen($value) > 18 || (int)$value < 1))) {
            throw new HttpError(422, 'Choose a valid usage filter.');
        }
    }
    $bounds = familyReportingBounds($days);
    $since = $bounds['since'];
    $until = $bounds['until'];
    $statement = $db->prepare('SELECT u.id,u.username,u.display_name,u.nickname,u.is_active,
        COUNT(s.login_at) AS logins, COALESCE(SUM(s.active_seconds),0) AS seconds,
        MAX(s.last_seen_at) AS period_last_seen,
        (SELECT MAX(login_at) FROM platform_sessions WHERE user_id=u.id) AS last_login,
        (SELECT MAX(last_seen_at) FROM platform_sessions WHERE user_id=u.id) AS last_seen
        FROM users u LEFT JOIN platform_sessions s ON s.user_id=u.id AND s.started_at >= ? AND s.started_at < ?
        GROUP BY u.id,u.username,u.display_name,u.nickname,u.is_active ORDER BY u.display_name');
    $statement->execute([$since, $until]);
    $people = array_map(static fn(array $row): array => [
        'id' => (int)$row['id'], 'name' => familyDisplayName($row), 'username' => $row['username'],
        'enabled' => (bool)$row['is_active'], 'logins' => (int)$row['logins'],
        'activeSeconds' => (int)$row['seconds'],
        'usedInPeriod' => $row['period_last_seen'] !== null,
        'lastLoginAt' => $row['last_login'] === null ? null : activityTimestamp($row['last_login']),
        'lastSeenAt' => $row['last_seen'] === null ? null : activityTimestamp($row['last_seen']),
    ], $statement->fetchAll());
    $where = 's.started_at >= ? AND s.started_at < ?'; $args = [$since, $until];
    if ($person !== '') { $where .= ' AND s.user_id = ?'; $args[] = $person; }
    if ($before !== '') { $where .= ' AND s.id < ?'; $args[] = $before; }
    $statement = $db->prepare('SELECT s.*,u.display_name,u.nickname FROM platform_sessions s
        JOIN users u ON u.id=s.user_id WHERE '.$where.' ORDER BY s.id DESC LIMIT 26');
    $statement->execute($args);
    $rows = $statement->fetchAll(); $more = count($rows) > 25; $rows = array_slice($rows,0,25);
    return ['people'=>$people,'sessions'=>array_map('platformSessionView',$rows),
        'nextCursor'=>$more ? (string)$rows[count($rows)-1]['id'] : null,
        'generatedAt'=>gmdate('Y-m-d\TH:i:s\Z')];
}
