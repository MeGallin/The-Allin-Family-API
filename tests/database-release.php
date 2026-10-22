<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bin/release/workflow.php';
$api = dirname(__DIR__);
$local = loadConfiguration($api . '/.env');
releaseAssert($local['APP_ENV'] === 'local' && $local['DB_HOST'] === '127.0.0.1' && $local['DB_PORT'] === '3307', 'Release tests require isolated local MariaDB on 127.0.0.1:3307.');
$admin = parse_ini_file($api . '/.local/mysql-admin.ini', false, INI_SCANNER_RAW);
$server = new PDO('mysql:host=127.0.0.1;port=3307;charset=utf8mb4', 'root', $admin['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$scratch = $api . '/.local/release-tests-' . bin2hex(random_bytes(8));
familyReleasePrivateDirectory($scratch);
$owned = []; $passed = 0;
function check(bool $condition, string $label): void { global $passed; releaseAssert($condition, $label); $passed++; echo "PASS: $label\n"; }
function rejects(callable $action, string $pattern, string $label): void {
    $caught = '';
    try { $action(); } catch (Throwable $error) { $caught = $error->getMessage(); }
    check((bool)preg_match($pattern, $caught), $label . ($caught === '' ? ' (no rejection)' : ''));
}
function fixture(): array {
    global $server, $scratch, $owned, $admin, $api;
    $name = 'allin_release_test_' . bin2hex(random_bytes(8));
    $server->exec('CREATE DATABASE ' . releaseIdentifier($name) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $owned[] = $name;
    $root = $scratch . '/' . $name;
    foreach (['site/api/src', 'private/photos', 'private/releases', 'migrations'] as $relative) familyReleasePrivateDirectory($root . '/' . $relative);
    writePrivateFile($root . '/site/api/index.php', '<?php /* acquireFamilyRequestLease */');
    copy($api . '/src/release-maintenance.php', $root . '/site/api/src/release-maintenance.php');
    writePrivateFile($root . '/site/index.html', '<h1>Previous application</h1>');
    writePrivateFile($root . '/private/photos/family-photo.bin', "synthetic photo\0bytes");
    $config = array_replace(loadConfiguration($api . '/.env'), [
        'APP_ENV' => 'test', 'DB_NAME' => $name, 'DB_USER' => 'runtime_fixture_only', 'DB_PASSWORD' => '',
        'APP_KEY' => bin2hex(random_bytes(32)),
        'DATABASE_RELEASE_DIRECTORY' => $root . '/private/releases', 'MEMORY_PHOTO_DIRECTORY' => $root . '/private/photos',
        'SESSION_DIRECTORY' => $root . '/private/sessions',
    ]);
    $ini = '';
    foreach ($config as $key => $value) $ini .= $key . '="' . addcslashes($value, '\\"') . '"' . "\n";
    writePrivateFile($root . '/site/api/.env', $ini);
    writePrivateFile($root . '/private/deploy.ini', 'DB_USER="root"' . "\n" . 'DB_PASSWORD="' . addcslashes($admin['password'], '\\"') . '"' . "\n");
    $initial = 'CREATE TABLE users (id INT PRIMARY KEY, nickname VARCHAR(40)) ENGINE=InnoDB;'
        . 'CREATE TABLE entries (id INT PRIMARY KEY, owner_id INT NOT NULL, body TEXT NOT NULL, photo VARCHAR(120), FOREIGN KEY (owner_id) REFERENCES users(id)) ENGINE=InnoDB;'
        . 'CREATE TABLE schema_migrations (name VARCHAR(180) PRIMARY KEY, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB;';
    writePrivateFile($root . '/migrations/001_initial.sql', $initial);
    writePrivateFile($root . '/migrations/002_priority.sql', 'ALTER TABLE entries ADD COLUMN important TINYINT NOT NULL DEFAULT 0;');
    $dbConfig = array_replace($config, ['DB_USER' => 'root', 'DB_PASSWORD' => $admin['password']]);
    $db = connectDatabase($dbConfig);
    $db->exec($initial);
    $db->exec("INSERT INTO schema_migrations(name) VALUES ('001_initial.sql'); INSERT INTO users VALUES (1,'Dad'),(2,'Other member');");
    $db->prepare('INSERT INTO entries VALUES (?,?,?,?)')->execute([1, 1, "Synthetic private note — Unicode ✓\nsecond line", 'family-photo.bin']);
    $db->prepare('INSERT INTO entries VALUES (?,?,?,?)')->execute([2, 2, 'Other owner remains private', null]);
    $options = ['config' => $root . '/site/api/.env', 'credentials' => $root . '/private/deploy.ini', 'database' => $name,
        'site-root' => $root . '/site', 'migrations' => $root . '/migrations', 'adopt-history' => false,
        'dump' => 'C:/xampp/mysql/bin/mysqldump.exe', 'client' => 'C:/xampp/mysql/bin/mysql.exe'];
    $context = releaseConnect($options);
    $plan = createReleasePlan($context, $options);
    $options['plan'] = $context['root'] . '/plan-' . $plan['id'] . '.json';
    $options['confirm-plan'] = hash_file('sha256', $options['plan']);
    return [$context, $options, $plan];
}
function lockedApply(array $context, array $options): array {
    $locks = releaseLocks($context);
    try { return applyReleasePlan($context, $options); } finally { releaseUnlock($context, $locks); }
}
try {
    [$context, $options, $plan] = fixture();
    check($plan['pending'] === ['002_priority.sql'], 'Plan lists only pending migration');
    check(!is_file($context['root'] . '/maintenance.json') && count(releaseLedger($context['db'])) === 1, 'Planning does not pause service or change database');
    $bad = $options; $bad['database'] = 'wrong_target';
    rejects(fn() => releaseConnect($bad), '/does not match/', 'Explicit wrong database rejected before connection');
    $bad = $options; $bad['confirm-plan'] = str_repeat('0', 64);
    rejects(fn() => lockedApply($context, $bad), '/checksum/', 'Plan confirmation is required');
    file_put_contents($options['migrations'] . '/002_priority.sql', "\n--changed", FILE_APPEND);
    rejects(fn() => lockedApply($context, $options), '/SQL changed/', 'Changed pending migration rejected before maintenance');
    writePrivateFile($options['migrations'] . '/002_priority.sql', 'ALTER TABLE entries ADD COLUMN important TINYINT NOT NULL DEFAULT 0;');
    file_put_contents($options['migrations'] . '/001_initial.sql', "\n--changed", FILE_APPEND);
    rejects(fn() => createReleasePlan($context, $options), '/Previously applied/', 'Historical SQL checksum drift rejected');
    [$context, $options, $plan] = fixture();
    $beforeSpecs = releaseSpecs($context['db']);
    $beforeData = releaseData($context['db'], $beforeSpecs);
    $state = lockedApply($context, $options);
    check($state['phase'] === 'awaiting-verification' && $state['existingDataPreserved'], 'Successful migration waits in maintenance with old data preserved');
    $directory = $context['root'] . '/release-' . $state['id'];
    check(is_file($directory . '/database-verification.json') && filesize($directory . '/database.sql') > 0, 'Database dump actually restored and schema/data verified');
    check(file_get_contents($directory . '/photos/family-photo.bin') === "synthetic photo\0bytes", 'Private photo backup retains exact bytes');
    check(hash_file('sha256', $directory . '/site/api/.env') === hash_file('sha256', $options['config']), 'Matching application/configuration backed up');
    check(!is_file($directory . '/client-options.ini') && !is_file($directory . '/restore-scratch.json'), 'Temporary utility credentials and restore database cleaned');
    check(count(releaseLedger($context['db'])) === 2 && (int)$context['db']->query('SELECT COUNT(*) FROM entries')->fetchColumn() === 2, 'Migration ledger advances without replacing saved entries');
    rejects(fn() => acquireFamilyRequestLease($context['config']), '/maintenance/', 'Maintenance blocks API lease including background reads/writes');
    rejects(fn() => resumeRelease($context, ['release' => $state['id']]), '/explicit/', 'Resume requires explicit verification');
    rejects(fn() => lockedApply($context, $options), '/history changed|Maintenance already/', 'Old plan cannot be blindly rerun');
    $resume = array_replace($options, ['release' => $state['id'], 'verified' => false]);
    $originalDump = file_get_contents($directory . '/database.sql');
    file_put_contents($directory . '/database.sql', "\n-- corruption", FILE_APPEND);
    rejects(fn() => resumeRelease($context, $resume), '/backup has changed/', 'Resume rejects a backup altered after verification');
    writePrivateFile($directory . '/database.sql', $originalDump);
    $context['db']->exec('UPDATE entries SET important=1 WHERE id=1');
    rejects(fn() => resumeRelease($context, $resume), '/changed after migration/', 'Resume checks new columns as well as original data');
    $context['db']->exec('UPDATE entries SET important=0 WHERE id=1');
    resumeRelease($context, $resume);
    $lease = acquireFamilyRequestLease($context['config']); fclose($lease);
    check(!is_file($context['root'] . '/maintenance.json'), 'Explicit verified resume reopens API');
    $next = createReleasePlan($context, $options);
    check($next['pending'] === [], 'Applied migration is skipped on future plans');

    [$context, $options, $plan] = fixture();
    $options['dump'] = 'C:/does-not-exist.exe';
    rejects(fn() => lockedApply($context, $options), '/does not exist/', 'Missing dump utility rejected before maintenance');
    check(!is_file($context['root'] . '/maintenance.json'), 'Invalid utility path does not interrupt family use');
    $options['dump'] = PHP_BINARY;
    rejects(fn() => lockedApply($context, $options), '/Maintenance remains/', 'Failed dump process leaves maintenance enabled');
    check(count(releaseLedger($context['db'])) === 1, 'Backup failure applies no migration');
    rejects(fn() => resumeRelease($context, array_replace($options, ['release' => $plan['id'], 'verified' => false])), '/Failed\/incomplete/', 'Failed release cannot auto-resume');

    [$context, $options, $plan] = fixture();
    $options['client'] = PHP_BINARY;
    rejects(fn() => lockedApply($context, $options), '/Maintenance remains/', 'Restore utility failure prevents migration');
    check(count(releaseLedger($context['db'])) === 1, 'Unverified backup never permits migration');

    [$context, $options, $plan] = fixture();
    writePrivateFile($options['migrations'] . '/002_priority.sql', 'ALTER TABLE entries ADD COLUMN important TINYINT; THIS IS INVALID SQL;');
    $plan = createReleasePlan($context, $options);
    $options['plan'] = $context['root'] . '/plan-' . $plan['id'] . '.json'; $options['confirm-plan'] = hash_file('sha256', $options['plan']);
    rejects(fn() => lockedApply($context, $options), '/Maintenance remains/', 'Partial DDL failure stays in maintenance');
    $partial = inspectRelease($context, $plan['id']);
    check(count(releaseLedger($context['db'])) === 1, 'Failed DDL is not falsely recorded as applied');
    check($context['db']->query("SHOW COLUMNS FROM entries LIKE 'important'")->fetch() !== false, 'Earlier DDL remains after later failure');
    rejects(fn() => lockedApply($context, $options), '/Maintenance already/', 'Partial migration cannot be blindly retried');

    [$context, $options, $plan] = fixture();
    writePrivateFile($options['migrations'] . '/002_priority.sql', 'DELETE FROM entries WHERE id=1;');
    $plan = createReleasePlan($context, $options);
    $options['plan'] = $context['root'] . '/plan-' . $plan['id'] . '.json'; $options['confirm-plan'] = hash_file('sha256', $options['plan']);
    rejects(fn() => lockedApply($context, $options), '/Maintenance remains/', 'Unexpected existing data loss fails verification');
    $status = inspectRelease($context, $plan['id']);
    check($status['failedDuring'] === 'verifying' && !$status['existingDataPreserved'], 'Failed preservation check is recorded privately');

    [$context, $options, $plan] = fixture();
    $locks = releaseLocks($context);
    try { rejects(fn() => releaseLocks($context), '/Another database release/', 'Concurrent release rejected by filesystem lock'); }
    finally { releaseUnlock($context, $locks); }
    $name = 'allin_release_' . substr(hash('sha256', $context['config']['DB_NAME']), 0, 48);
    $context['db']->prepare('SELECT GET_LOCK(?,0)')->execute([$name]);
    $other = releaseConnect($options);
    try { rejects(fn() => releaseLocks($other), '/database lock/', 'Separate process/storage cannot bypass database lock'); }
    finally { $context['db']->prepare('SELECT RELEASE_LOCK(?)')->execute([$name]); }

    // Exercise the real CLI entrypoint, including failure exit status.
    $command = [PHP_BINARY, $api . '/bin/database-release.php', '--action=plan'];
    foreach ($options as $key => $value) if (is_string($value) && !in_array($key, ['plan', 'confirm-plan'], true)) $command[] = '--' . $key . '=' . $value;
    releaseProcess($command, $context['root'] . '/cli.log');
    check(str_contains(file_get_contents($context['root'] . '/cli.log'), 'confirmPlan'), 'Real CLI emits reviewable plan without credentials');
    rejects(fn() => releaseProcess([PHP_BINARY, $api . '/bin/database-release.php', '--action=invalid'], $context['root'] . '/invalid-cli.log'), '/utility failed/', 'Invalid CLI action exits nonzero');
    rejects(fn() => releaseProcess([PHP_BINARY, '-r', 'sleep(3);'], $context['root'] . '/timeout.log', null, 1), '/timed out/', 'Stalled database utility is terminated');

    // Real HTTP requests use the application's actual front controller. Runtime
    // credentials deliberately cannot connect: a maintenance response must occur first.
    $probe = stream_socket_server('tcp://127.0.0.1:0');
    $address = stream_socket_get_name($probe, false); fclose($probe);
    $environment = array_replace(getenv(), $context['config']);
    $pipes = [];
    $http = proc_open([PHP_BINARY, '-S', $address, $api . '/router.php'], [0 => ['pipe', 'r'], 1 => ['file', $context['root'] . '/http.log', 'a'], 2 => ['file', $context['root'] . '/http.log', 'a']], $pipes, $api, $environment, ['bypass_shell' => true]);
    try {
        $ready = false;
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $body = @file_get_contents('http://' . $address . '/health');
            if ($body !== false) { $ready = true; break; }
            usleep(100000);
        }
        check($ready, 'Real API health works before maintenance without opening database');
        releaseWrite($context['root'] . '/maintenance.json', ['release' => 'synthetic-http-check']);
        foreach (['/health', '/auth/session', '/auth/presence', '/shopping/add', '/notes'] as $path) {
            $response = file_get_contents('http://' . $address . $path, false, stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 3, 'method' => str_contains($path, 'presence') || str_contains($path, 'add') ? 'POST' : 'GET']]));
            check(str_contains($http_response_header[0], '503') && str_contains($response, 'maintenance') && in_array('Retry-After: 60', $http_response_header, true), 'HTTP maintenance blocks ' . $path . ' before auth/session/database writes');
        }
        unlink($context['root'] . '/maintenance.json');
        check(file_get_contents('http://' . $address . '/health') !== false, 'HTTP health recovers after maintenance ends');
    } finally {
        proc_terminate($http); fclose($pipes[0]); proc_close($http);
    }

    [$context, $options, $plan] = fixture();
    $workerPath = $context['root'] . '/request-worker.php';
    $workerOptions = $context['root'] . '/worker-options.json';
    releaseWrite($workerOptions, $options);
    $workflow = var_export($api . '/bin/release/workflow.php', true);
    writePrivateFile($workerPath, '<?php require ' . $workflow . '; $c=releaseConnect(releaseJson($argv[1])); $lease=acquireFamilyRequestLease($c["config"]); file_put_contents($c["root"]."/worker-ready", "ready"); usleep(600000); $c["db"]->exec("INSERT INTO entries VALUES (3,1,\'Existing in-flight request\',NULL)");');
    $pipes = [];
    $worker = proc_open([PHP_BINARY, $workerPath, $workerOptions], [0 => ['pipe', 'r'], 1 => ['file', $context['root'] . '/worker.log', 'a'], 2 => ['file', $context['root'] . '/worker.log', 'a']], $pipes, $api, null, ['bypass_shell' => true]);
    try {
        for ($attempt = 0; $attempt < 100 && !is_file($context['root'] . '/worker-ready'); $attempt++) usleep(10000);
        check(is_file($context['root'] . '/worker-ready'), 'In-flight worker acquired the real API lease');
        $state = lockedApply($context, $options);
        check($state['before']['entries']['rows'] === 3 && $state['existingDataPreserved'], 'Backup waits for in-flight write and includes its final saved data');
    } finally { proc_terminate($worker); fclose($pipes[0]); proc_close($worker); }

    [$context, $options, $plan] = fixture();
    $lease = fopen($context['root'] . '/requests.lock', 'c+'); flock($lease, LOCK_SH);
    try { rejects(fn() => lockedApply($context, $options), '/Maintenance remains/', 'Undrained requests time out safely'); }
    finally { fclose($lease); }
    $state = inspectRelease($context, $plan['id']);
    check($state['failedDuring'] === 'pausing' && count(releaseLedger($context['db'])) === 1, 'Drain timeout starts neither backup nor migration');

    [$context, $options, $plan] = fixture();
    $command = [PHP_BINARY, $api . '/bin/database-release.php', '--action=apply'];
    foreach ($options as $key => $value) if (is_string($value)) $command[] = '--' . $key . '=' . $value;
    releaseProcess($command, $context['root'] . '/apply-cli.log');
    $state = inspectRelease($context, $plan['id']);
    check($state['phase'] === 'awaiting-verification', 'Real CLI completes backup, restore and migration');
    $command[2] = '--action=resume'; $command[] = '--release=' . $plan['id']; $command[] = '--verified';
    releaseProcess($command, $context['root'] . '/resume-cli.log');
    check(!is_file($context['root'] . '/maintenance.json'), 'Real CLI verified resume removes maintenance');

    [$context, $options, $plan] = fixture();
    $context['db']->exec('CREATE TABLE unsupported_fixture (id INT PRIMARY KEY) ENGINE=MyISAM');
    rejects(fn() => createReleasePlan($context, $options), '/InnoDB/', 'Unsupported engine rejected before a release');

    [$context, $options, $plan] = fixture();
    $restoreName = $options['database'] . '_restore';
    $server->exec('CREATE DATABASE ' . releaseIdentifier($restoreName) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'); $owned[] = $restoreName;
    $options['restore-database'] = $restoreName;
    $bad = $options; $bad['restore-database'] = $options['database'];
    rejects(fn() => lockedApply($context, $bad), '/exact target name/', 'Production target cannot be used as restore scratch');
    $server->exec('CREATE TABLE ' . releaseIdentifier($restoreName) . '.occupied (id INT PRIMARY KEY) ENGINE=InnoDB');
    rejects(fn() => lockedApply($context, $options), '/not empty/', 'Nonempty precreated scratch is never overwritten');
    check(!is_file($context['root'] . '/maintenance.json'), 'Invalid precreated scratch rejected before maintenance');
    $server->exec('DROP TABLE ' . releaseIdentifier($restoreName) . '.occupied');
    $state = lockedApply($context, $options);
    check($state['phase'] === 'awaiting-verification', 'cPanel precreated scratch completes actual restore verification');
    $scratchConfig = $context['adminConfig']; $scratchConfig['DB_NAME'] = $restoreName;
    check(connectDatabase($scratchConfig)->query('SHOW TABLES')->fetchAll() === [], 'Precreated scratch remains present and empty after guarded cleanup');
    echo "PASS: $passed database-release checks. Only disposable local databases were used.\n";
} finally {
    foreach ($owned as $name) {
        releaseAssert((bool)preg_match('/^allin_release_test_[a-f0-9]{16}(_restore)?$/D', $name), 'Refusing unsafe fixture cleanup.');
        $server->exec('DROP DATABASE ' . releaseIdentifier($name));
    }
    // Retain secret-free test summary externally; delete only this owned private fixture tree.
    $real = realpath($scratch);
    releaseAssert($real !== false && releaseWithin($real, $api . '/.local') && str_starts_with(basename($real), 'release-tests-'), 'Refusing unsafe fixture file cleanup.');
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
        releaseAssert(!$entry->isLink(), 'Fixture contains unexpected link.');
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($real);
}
