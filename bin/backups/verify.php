<?php
declare(strict_types=1);
require_once __DIR__ . '/bundle.php';
require_once __DIR__ . '/schema.php';

/** Refuse credentials with any data privilege beyond the isolated restore DB. */
function backupRestoreGrants(PDO $db, string $target): void
{
    $scoped = false;
    foreach ($db->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN) as $grant) {
        releaseAssert(stripos($grant, 'WITH GRANT OPTION') === false, 'Restore credentials must not grant privileges.');
        if (preg_match('/^GRANT USAGE ON \*\.\* TO /i', $grant)) continue;
        releaseAssert((bool)preg_match('/^GRANT .+ ON `([^`]+)`\.\* TO /i', $grant, $match), 'Restore credentials have global, role or unsupported privileges.');
        releaseAssert(str_replace('\\_', '_', $match[1]) === $target, 'Restore credentials have access outside the scratch database.');
        $scoped = true;
    }
    releaseAssert($scoped, 'Restore credentials have no scratch database grant.');
}

/** Import ONLY into the exact source name + _restore, empty and separately scoped. */
function backupVerifyRestore(string $recovered, array $restoreConfig, string $client): array
{
    $recovered = releaseReal($recovered);
    $manifest = releaseJson($recovered . '/manifest.json');
    releaseAssert(($manifest['format'] ?? '') === 'allin-family-backup-v1', 'Invalid restore manifest.');
    $source = $manifest['database'] ?? '';
    releaseIdentifier($source);
    releaseAssert($restoreConfig['DB_NAME'] === $source . '_restore' && strlen($restoreConfig['DB_NAME']) <= 64, 'Restore database must be the exact source name plus _restore.');
    $db = connectDatabase($restoreConfig);
    backupRestoreGrants($db, $restoreConfig['DB_NAME']);
    releaseAssert($db->query('SELECT DATABASE()')->fetchColumn() === $restoreConfig['DB_NAME'], 'Connected to wrong scratch database.');
    releaseAssert($db->query('SHOW TABLES')->fetchAll() === [], 'Scratch restore database must be empty.');
    releaseAssert(hash_file('sha256', $recovered . '/database.sql') === $manifest['databaseSha256'], 'Recovered SQL dump changed.');
    foreach ($manifest['photos'] as $path => $hash) {
        $photo = releaseReal($recovered . '/photos/' . backupRelative($path));
        releaseAssert(releaseWithin($photo, $recovered . '/photos') && hash_file('sha256', $photo) === $hash, 'Recovered photo verification failed.');
    }
    $defaults = $recovered . '/restore-connection.ini';
    releaseAssert(!file_exists($defaults), 'Restore credential staging already exists.');
    try {
        releaseDefaults($restoreConfig, $defaults);
        releaseProcess([releaseReal($client), '--defaults-file=' . $defaults, '--binary-mode', '--database=' . $restoreConfig['DB_NAME']], $recovered . '/restore-utility.log', $recovered . '/database.sql');
    } finally { if (is_file($defaults)) unlink($defaults); }
    $specs = releaseSpecs($db);
    if ($specs !== $manifest['schema']) releaseWrite($recovered . '/restore-schema-comparison.json', ['expected' => $manifest['schema'], 'actual' => $specs]);
    if (isset($manifest['schemaDetails'])) {
        $actualDetails = backupSchemaDetails($db, $specs);
        if ($actualDetails !== $manifest['schemaDetails']) releaseWrite($recovered . '/restore-schema-details.json', ['expected' => $manifest['schemaDetails'], 'actual' => $actualDetails]);
        releaseAssert(array_keys($specs) === array_keys($manifest['schema']) && $actualDetails === $manifest['schemaDetails'], 'Restored schema metadata differs from the snapshot.');
    } else {
        releaseAssert($specs === $manifest['schema'], 'Restored database schema differs from the snapshot.');
    }
    releaseAssert(releaseData($db, $specs) === $manifest['tables'], 'Restored rows differ from the snapshot.');
    $result = ['verifiedAt' => gmdate('c'), 'database' => $restoreConfig['DB_NAME'], 'tables' => count($specs), 'photoFiles' => count($manifest['photos']), 'schemaMatches' => true, 'rowsMatch' => true, 'photosMatch' => true];
    releaseWrite($recovered . '/restore-verification.json', $result);
    return $result;
}
