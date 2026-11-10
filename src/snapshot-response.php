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
    $itemRevision = '';
    if ($domain === 'widget') {
        $items = $db->prepare("SELECT COALESCE(SUM(i.version),0) FROM where_is_it_items i JOIN family_widgets w ON w.id=i.widget_id WHERE w.owner_id=? OR w.visibility='family'");
        $items->execute([$user['id']]); $itemRevision = ':' . $items->fetchColumn();
    }
    $representation = $domain === 'widget' ? ':meals-v3:where-summary-v2:' . (new DateTimeImmutable('today',new DateTimeZone('Europe/London')))->format('Y-m-d') : '';
    $etag = '"' . hash('sha256', 'snapshot-v1:' . $domain . ':' . $user['id'] . ':' . $revision . ':' . $profiles['people'] . ':' . $profiles['revision'] . $representation . $itemRevision) . '"';
    header('ETag: ' . $etag);
    header('Cache-Control: no-store');
    if (trim($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
        http_response_code(304); return;
    }
    jsonResponse($snapshot());
}
