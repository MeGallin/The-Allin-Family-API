<?php
declare(strict_types=1);

/** The caller authenticates first. Unchanged reads avoid rebuilding full collections. */
function familySnapshotResponse(PDO $db, array $user, string $domain, callable $snapshot): void
{
    if ($domain === 'personal_note') {
        $query = $db->prepare('SELECT COALESCE(MAX(id),0) FROM personal_note_history WHERE owner_id=?');
        $query->execute([$user['id']]);
    } else {
        $query = $db->prepare('SELECT COALESCE(MAX(id),0) FROM activity_events WHERE entity_type=?');
        $query->execute([$domain]);
    }
    $revision = (string)$query->fetchColumn();
    // Nickname/account edits affect projected author names and visible permissions.
    $profiles = $db->query('SELECT COUNT(*) AS people,COALESCE(SUM(profile_version),0) AS revision FROM users')->fetch();
    $etag = '"' . hash('sha256', 'snapshot-v1:' . $domain . ':' . $user['id'] . ':' . $revision . ':' . $profiles['people'] . ':' . $profiles['revision']) . '"';
    header('ETag: ' . $etag);
    header('Cache-Control: no-store');
    if (trim($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
        http_response_code(304); return;
    }
    jsonResponse($snapshot());
}
