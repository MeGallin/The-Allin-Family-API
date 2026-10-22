<?php
declare(strict_types=1);
require_once __DIR__ . '/common.php';

function createReleasePlan(array $context, array $options): array
{
    $files = releaseMigrationFiles($options['migrations']);
    $ledger = releaseHistory($context, $files, isset($options['adopt-history']));
    $plan = [
        'id' => gmdate('Ymd-His') . '-' . bin2hex(random_bytes(8)), 'identity' => $context['identity'],
        'createdAt' => gmdate('c'), 'files' => $files, 'applied' => $ledger,
        'pending' => array_values(array_diff(array_keys($files), $ledger)),
        'allowDataChange' => isset($options['allow-data-change']),
        'migrations' => releaseReal($options['migrations']), 'site' => $context['site'],
    ];
    releaseSpecs($context['db']);
    releaseWrite($context['root'] . '/plan-' . $plan['id'] . '.json', $plan);
    return $plan;
}

function readReleasePlan(array $context, array $options): array
{
    releaseAssert(isset($options['plan'], $options['confirm-plan']), 'Supply --plan and its reviewed --confirm-plan SHA256.');
    $path = releaseReal($options['plan']);
    releaseAssert(releaseWithin($path, $context['root']), 'Plan must be inside private release storage.');
    releaseAssert(hash_file('sha256', $path) === $options['confirm-plan'], 'Plan checksum confirmation differs.');
    $plan = releaseJson($path);
    releaseAssert($plan['identity'] === $context['identity'] && $plan['site'] === $context['site'], 'Plan target changed.');
    releaseAssert($plan['migrations'] === releaseReal($options['migrations']), 'Migration bundle changed.');
    releaseAssert($plan['files'] === releaseMigrationFiles($options['migrations']), 'Migration SQL changed after planning.');
    releaseAssert($plan['applied'] === releaseHistory($context, $plan['files'], false), 'Migration history changed after planning.');
    releaseAssert($plan['pending'] !== [], 'There are no pending migrations.');
    releaseAssert($plan['pending'] === array_values(array_diff(array_keys($plan['files']), $plan['applied'])), 'Plan pending sequence is invalid.');
    return $plan;
}

function verifyDatabaseBackup(array $context, array $options, string $directory, array $specs, array $data): void
{
    foreach (['dump', 'client'] as $utility) {
        releaseAssert(isset($options[$utility]) && is_file(releaseReal($options[$utility])), 'Missing database utility --' . $utility);
    }
    $defaults = $directory . '/client-options.ini';
    $dump = $directory . '/database.sql';
    $precreated = isset($options['restore-database']);
    $scratch = $precreated ? $options['restore-database'] : 'allin_restore_' . bin2hex(random_bytes(12));
    $created = false;
    try {
        releaseDefaults($context['adminConfig'], $defaults);
        releaseProcess([$options['dump'], '--defaults-file=' . $defaults, '--single-transaction', '--quick', '--skip-lock-tables', '--hex-blob', '--default-character-set=utf8mb4', '--result-file=' . $dump, $context['config']['DB_NAME']], $directory . '/utilities.log');
        releaseAssert(is_file($dump) && filesize($dump) > 0, 'Database dump is missing or empty.');
        @chmod($dump, 0600);
        if ($precreated) {
            validatePrecreatedRestore($context, $scratch);
        } else {
            $context['db']->exec('CREATE DATABASE ' . releaseIdentifier($scratch) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        }
        $created = true;
        releaseWrite($directory . '/restore-scratch.json', ['database' => $scratch, 'identity' => $context['identity'], 'precreated' => $precreated]);
        releaseProcess([$options['client'], '--defaults-file=' . $defaults, '--default-character-set=utf8mb4', '--database=' . $scratch], $directory . '/utilities.log', $dump);
        $scratchConfig = $context['adminConfig'];
        $scratchConfig['DB_NAME'] = $scratch;
        $restore = connectDatabase($scratchConfig);
        releaseAssert(releaseSpecs($restore) === $specs, 'Restored database schema differs from source.');
        releaseAssert(releaseData($restore, $specs) === $data, 'Restored database data differs from source.');
        releaseWrite($directory . '/database-verification.json', ['dumpSha256' => hash_file('sha256', $dump), 'restoreVerifiedAt' => gmdate('c'), 'tables' => $data]);
    } finally {
        if (is_file($defaults)) unlink($defaults);
        // Never drop an operator-provisioned database; remove only this restore's
        // known tables, after confirming it was empty before the import.
        if ($created) {
            if ($precreated) {
                releaseAssert($scratch === $context['config']['DB_NAME'] . '_restore', 'Refusing unsafe precreated scratch cleanup.');
                $scratchConfig = $context['adminConfig']; $scratchConfig['DB_NAME'] = $scratch;
                $cleanup = connectDatabase($scratchConfig);
                $tables = $cleanup->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
                releaseAssert(array_diff($tables, array_keys($specs)) === [], 'Unknown scratch tables found; manual cleanup required.');
                $cleanup->exec('SET FOREIGN_KEY_CHECKS=0');
                foreach ($tables as $table) $cleanup->exec('DROP TABLE ' . releaseIdentifier($table));
            } else {
                releaseAssert((bool)preg_match('/^allin_restore_[a-f0-9]{24}$/D', $scratch) && $scratch !== $context['config']['DB_NAME'], 'Refusing unsafe scratch cleanup.');
                $context['db']->exec('DROP DATABASE ' . releaseIdentifier($scratch));
            }
            unlink($directory . '/restore-scratch.json');
        }
    }
}

function validatePrecreatedRestore(array $context, string $scratch): void
{
    releaseAssert($scratch === $context['config']['DB_NAME'] . '_restore' && strlen($scratch) <= 64, 'Precreated restore database must be the exact target name plus _restore.');
    $config = $context['adminConfig']; $config['DB_NAME'] = $scratch;
    $db = connectDatabase($config);
    releaseAssert($db->query('SHOW TABLES')->fetchAll() === [], 'Precreated restore database is not empty.');
    releaseSpecs($db);
}

function applyReleasePlan(array $context, array $options): array
{
    $plan = readReleasePlan($context, $options);
    foreach (['dump', 'client'] as $utility) {
        releaseAssert(isset($options[$utility]) && is_file(releaseReal($options[$utility])), 'Missing database utility --' . $utility);
    }
    if (isset($options['restore-database'])) validatePrecreatedRestore($context, $options['restore-database']);
    $root = $context['root'];
    releaseAssert(!is_file($root . '/maintenance.json'), 'Maintenance already active; investigate the previous release.');
    // Refuse to rely on a maintenance gate that has not been deployed yet.
    releaseAssert(is_file($context['site'] . '/api/src/release-maintenance.php') &&
        str_contains(file_get_contents($context['site'] . '/api/index.php'), 'acquireFamilyRequestLease'), 'Deploy the maintenance-capable API before using this workflow.');
    $photos = releaseReal($context['config']['MEMORY_PHOTO_DIRECTORY']);
    releaseAssert(!releaseWithin($root, $photos) && !releaseWithin($photos, $root), 'Release storage and photos must be separate.');
    $directory = $root . '/release-' . $plan['id'];
    releaseAssert(!file_exists($directory), 'This release already has an attempt; inspect it rather than rerunning.');
    familyReleasePrivateDirectory($directory);
    $state = ['id' => $plan['id'], 'identity' => $context['identity'], 'phase' => 'pausing', 'plan' => $plan, 'startedAt' => gmdate('c')];
    releaseWrite($directory . '/status.json', $state);
    releaseWrite($root . '/maintenance.json', ['release' => $plan['id'], 'since' => gmdate('c')]);
    $lease = fopen($root . '/requests.lock', 'c+');
    try {
        releaseAssert($lease !== false, 'Cannot open request drain lease.');
        $deadline = microtime(true) + 15;
        while (!flock($lease, LOCK_EX | LOCK_NB)) {
            releaseAssert(microtime(true) < $deadline, 'Requests did not drain; maintenance stays enabled.');
            usleep(100000);
        }
        $state['phase'] = 'backing-up'; releaseWrite($directory . '/status.json', $state);
        $specs = releaseSpecs($context['db']);
        $data = releaseData($context['db'], $specs);
        $state['before'] = $data; $state['specs'] = $specs;
        releaseWrite($directory . '/status.json', $state);
        $siteHashes = releaseCopyTree($context['site'], $directory . '/site');
        $photoHashes = releaseCopyTree($photos, $directory . '/photos');
        familyReleasePrivateDirectory($directory . '/migrations');
        foreach ($plan['files'] as $name => $hash) {
            $sql = file_get_contents($plan['migrations'] . '/' . $name);
            releaseAssert(hash('sha256', $sql) === $hash, 'Migration changed while backing up.');
            writePrivateFile($directory . '/migrations/' . $name, $sql);
        }
        releaseWrite($directory . '/file-verification.json', ['site' => $siteHashes, 'photos' => $photoHashes]);
        verifyDatabaseBackup($context, $options, $directory, $specs, $data);
        // Recheck after restore rehearsal; any uncoordinated external writer aborts.
        releaseAssert(releaseData($context['db'], $specs) === $data, 'Source data changed during backup verification.');
        releaseAssert($plan['files'] === releaseMigrationFiles($options['migrations']), 'Migration files changed during backup.');
        $state['phase'] = 'migrating'; releaseWrite($directory . '/status.json', $state);
        foreach ($plan['pending'] as $name) {
            $state['currentMigration'] = $name; releaseWrite($directory . '/status.json', $state);
            $sql = file_get_contents($directory . '/migrations/' . $name);
            releaseAssert(hash('sha256', $sql) === $plan['files'][$name], 'Backed-up migration checksum differs.');
            executeMigrationSql($context['db'], $sql);
            $context['db']->prepare('INSERT INTO schema_migrations (name) VALUES (?)')->execute([$name]);
            $history = releaseJson($root . '/history.json');
            $history['checksums'][$name] = $plan['files'][$name];
            releaseWrite($root . '/history.json', $history);
        }
        $state['phase'] = 'verifying'; releaseWrite($directory . '/status.json', $state);
        // The ledger intentionally gains rows. All pre-existing application values
        // must survive additive migrations, including private ownership and votes.
        unset($specs['schema_migrations'], $data['schema_migrations']);
        $after = releaseData($context['db'], $specs);
        $state['existingDataPreserved'] = $after === $data;
        $state['after'] = $after;
        releaseAssert($state['existingDataPreserved'] || $plan['allowDataChange'], 'Existing data changed unexpectedly; inspect the backup and remain in maintenance.');
        releaseHistory($context, $plan['files'], false);
        $state['afterSpecs'] = releaseSpecs($context['db']);
        $state['afterFull'] = releaseData($context['db'], $state['afterSpecs']);
        $state['phase'] = 'awaiting-verification'; $state['completedAt'] = gmdate('c');
        releaseWrite($directory . '/status.json', $state);
        return $state;
    } catch (Throwable $error) {
        $state['failedDuring'] = $state['phase']; $state['phase'] = 'failed';
        $state['privateError'] = $error->getMessage();
        releaseWrite($directory . '/status.json', $state);
        throw new RuntimeException('Release failed. Maintenance remains enabled; inspect private release status.');
    } finally {
        if (is_resource($lease)) fclose($lease);
    }
}

function inspectRelease(array $context, string $id): array
{
    releaseAssert((bool)preg_match('/^\d{8}-\d{6}-[a-f0-9]{16}$/D', $id), 'Invalid release ID.');
    $state = releaseJson($context['root'] . '/release-' . $id . '/status.json');
    releaseAssert($state['identity'] === $context['identity'], 'Release belongs to another database.');
    return $state;
}

function resumeRelease(array $context, array $options): void
{
    releaseAssert(isset($options['release'], $options['verified']), 'Resume requires --release and explicit --verified after reviewing the release.');
    $state = inspectRelease($context, $options['release']);
    releaseAssert($state['phase'] === 'awaiting-verification', 'Failed/incomplete releases cannot be resumed automatically.');
    $marker = releaseJson($context['root'] . '/maintenance.json');
    releaseAssert($marker['release'] === $state['id'], 'Maintenance belongs to another release.');
    releaseHistory($context, releaseMigrationFiles($options['migrations']), false);
    releaseAssert(releaseSpecs($context['db']) === $state['afterSpecs'] && releaseData($context['db'], $state['afterSpecs']) === $state['afterFull'], 'Schema/data changed after migration verification.');
    $directory = $context['root'] . '/release-' . $state['id'];
    $verified = releaseJson($directory . '/database-verification.json');
    releaseAssert(is_file($directory . '/database.sql') && hash_file('sha256', $directory . '/database.sql') === $verified['dumpSha256'], 'Verified database backup has changed.');
    foreach (releaseJson($directory . '/file-verification.json') as $group => $files) {
        foreach ($files as $relative => $hash) {
            releaseAssert(is_file($directory . '/' . $group . '/' . $relative) && hash_file('sha256', $directory . '/' . $group . '/' . $relative) === $hash, 'Verified file backup has changed.');
        }
    }
    $state['phase'] = 'resumed'; $state['resumedAt'] = gmdate('c');
    releaseWrite($context['root'] . '/release-' . $state['id'] . '/status.json', $state);
    releaseAssert(unlink($context['root'] . '/maintenance.json'), 'Cannot remove maintenance marker.');
}
