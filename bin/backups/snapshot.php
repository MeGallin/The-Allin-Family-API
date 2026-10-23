<?php
declare(strict_types=1);
require_once __DIR__ . '/bundle.php';
require_once __DIR__ . '/dump.php';
require_once __DIR__ . '/schema.php';

/** Remove only a snapshot staging folder created by this workflow. */
function backupRemoveStaging(string $directory, string $stagingRoot): void
{
    if (!is_dir($directory)) return;
    $directory = releaseReal($directory); $stagingRoot = releaseReal($stagingRoot);
    releaseAssert(dirname($directory) === $stagingRoot && (bool)preg_match('/^snapshot-[a-f0-9]{24}$/D', basename($directory)), 'Unsafe backup staging cleanup.');
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
        releaseAssert(!$item->isLink() && releaseWithin($item->getPathname(), $directory), 'Unsafe backup staging entry.');
        releaseAssert($item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname()), 'Cannot remove private staging entry.');
    }
    releaseAssert(rmdir($directory), 'Cannot remove private staging folder.');
}

/** Read-only source capture; writes only private backup files and coordination locks. */
function backupCapture(array $settings): array
{
    foreach (['config', 'database', 'siteRoot', 'directory', 'publicKey'] as $field) {
        releaseAssert(isset($settings[$field]) && is_string($settings[$field]), 'Missing backup setting: ' . $field);
    }
    $site = releaseReal($settings['siteRoot']);
    $configPath = releaseReal($settings['config']);
    releaseAssert(releaseWithin($configPath, $site), 'Configuration does not belong to the selected site.');
    $config = loadConfiguration($configPath);
    releaseAssert($settings['database'] === $config['DB_NAME'], 'Explicit backup database does not match configuration.');
    $directory = releaseReal($settings['directory']);
    $photos = releaseReal($config['MEMORY_PHOTO_DIRECTORY']);
    $root = familyReleaseDirectory($config);
    familyReleasePrivateDirectory($root); $root = releaseReal($root);
    foreach ([$site, $photos, $root] as $protected) {
        releaseAssert(!releaseWithin($directory, $protected) && !releaseWithin($protected, $directory), 'Backup storage overlaps application or source storage.');
    }
    familyReleasePrivateDirectory($directory);
    $recipient = file_get_contents(releaseReal($settings['publicKey']));
    backupPublicKey($recipient);
    $method = $settings['dumpMethod'] ?? 'external';
    releaseAssert(in_array($method, ['external', 'pdo'], true), 'Unsupported backup dump method.');
    $dump = $method === 'external' ? releaseReal($settings['dump'] ?? '') : null;
    $db = connectDatabase($config);
    releaseAssert($db->query('SELECT DATABASE()')->fetchColumn() === $settings['database'], 'Connected to the wrong backup database.');
    $context = compact('root', 'config', 'db');
    $locks = releaseLocks($context); $lease = null;
    $stagingRoot = $directory . '/staging';
    familyReleasePrivateDirectory($stagingRoot);
    familyReleasePrivateDirectory($directory . '/exports');
    $staging = $stagingRoot . '/snapshot-' . bin2hex(random_bytes(12));
    $archive = $directory . '/exports/allin-' . gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(6)) . '.zip';
    try {
        releaseAssert(!file_exists($root . '/maintenance.json'), 'A database release is in maintenance; backup deferred.');
        $free = disk_free_space($directory);
        releaseAssert($free !== false && $free > 128 * 1024 * 1024, 'Insufficient free space for backup staging.');
        $lease = fopen($root . '/requests.lock', 'c+');
        releaseAssert(is_resource($lease), 'Cannot open backup request lease.');
        $deadline = microtime(true) + 15;
        while (!flock($lease, LOCK_EX | LOCK_NB)) {
            releaseAssert(microtime(true) < $deadline, 'Active requests did not drain; backup deferred.');
            usleep(100000);
        }
        // A short exclusive lease keeps SQL and photos consistent. No persistent
        // maintenance marker is created: a failed/read-only backup cannot leave
        // the family application stuck in maintenance after process exit.
        familyReleasePrivateDirectory($staging);
        $specs = releaseSpecs($db);
        $schemaDetails = backupSchemaDetails($db, $specs);
        $estimate = $db->query('SELECT COALESCE(SUM(DATA_LENGTH + INDEX_LENGTH),0) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchColumn();
        $photoBytes = 0;
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($photos, FilesystemIterator::SKIP_DOTS)) as $file) {
            releaseAssert(!$file->isLink() && $file->isFile(), 'Unsupported photo backup entry.');
            $photoBytes += $file->getSize();
        }
        releaseAssert($free > (int)$estimate * 4 + $photoBytes * 3 + 128 * 1024 * 1024, 'Insufficient space for a consistent encrypted backup.');
        $data = releaseData($db, $specs);
        $photoHashes = releaseCopyTree($photos, $staging . '/photos');
        $defaults = $staging . '/connection.ini';
        try {
            if ($method === 'pdo') {
                backupPdoDump($db, $specs, $staging . '/database.sql');
            } else {
                releaseDefaults($config, $defaults);
                releaseProcess([$dump, '--defaults-file=' . $defaults, '--single-transaction', '--quick', '--skip-lock-tables', '--skip-add-locks', '--skip-triggers', '--hex-blob', '--default-character-set=utf8mb4', '--result-file=' . $staging . '/database.sql', $config['DB_NAME']], $directory . '/utility.log');
            }
        } finally { if (is_file($defaults)) unlink($defaults); }
        releaseAssert($specs === releaseSpecs($db) && $schemaDetails === backupSchemaDetails($db, $specs) && $data === releaseData($db, $specs), 'Uncoordinated database writer detected; backup rejected.');
        $manifest = ['format' => 'allin-family-backup-v1', 'capturedAt' => gmdate('c'), 'database' => $settings['database'],
            'databaseSha256' => hash_file('sha256', $staging . '/database.sql'), 'schema' => $specs,
            'schemaDetails' => $schemaDetails, 'tables' => $data, 'photos' => $photoHashes];
        releaseWrite($staging . '/manifest.json', $manifest);
        fclose($lease); $lease = null;
        $result = backupSeal($staging, $archive, $recipient);
        $result['file'] = basename($archive); $result['capturedAt'] = $manifest['capturedAt'];
        $result['tables'] = count($specs); $result['photoFiles'] = count($photoHashes);
        // Publish this receipt last. Collectors ignore incomplete/orphaned pairs
        // and verify both ciphertext files before accepting an off-server copy.
        releaseWrite($archive . '.receipt.json', [
            'format' => 'allin-family-transfer-v1',
            'file' => basename($archive), 'capturedAt' => $manifest['capturedAt'],
            'bytes' => filesize($archive), 'sha256' => hash_file('sha256', $archive),
            'keyBytes' => filesize($archive . '.key.json'),
            'keySha256' => hash_file('sha256', $archive . '.key.json'),
        ]);
        // Capture success does NOT imply an off-server copy or a restore test.
        releaseWrite($directory . '/last-capture.json', $result);
        return $result;
    } finally {
        if (is_resource($lease)) fclose($lease);
        try { backupRemoveStaging($staging, $stagingRoot); }
        finally { releaseUnlock($context, $locks); }
    }
}
