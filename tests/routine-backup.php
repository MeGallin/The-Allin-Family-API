<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bin/backups/snapshot.php';
require dirname(__DIR__) . '/bin/backups/verify.php';
$api = dirname(__DIR__);
$local = loadConfiguration($api . '/.env');
releaseAssert($local['APP_ENV'] === 'local' && $local['DB_HOST'] === '127.0.0.1' && $local['DB_PORT'] === '3307', 'Backup tests require isolated local MariaDB at 127.0.0.1:3307.');
$admin = parse_ini_file($api . '/.local/mysql-admin.ini', false, INI_SCANNER_RAW);
$server = new PDO('mysql:host=127.0.0.1;port=3307;charset=utf8mb4', 'root', $admin['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$token = bin2hex(random_bytes(8));
$source = 'allin_backup_test_' . $token; $target = $source . '_restore';
$reader = 'bk_read_' . $token; $writer = 'bk_restore_' . $token;
$password = bin2hex(random_bytes(24));
$root = $api . '/.local/routine-backup-test-' . $token;
$checks = 0; $ownedDatabases = []; $ownedUsers = [];
function routineCheck(bool $condition, string $label): void { global $checks; releaseAssert($condition, $label); $checks++; echo "PASS: $label\n"; }
function routineReject(callable $action, string $label): void { $rejected = false; try { $action(); } catch (Throwable $error) { $rejected = true; } routineCheck($rejected, $label); }
try {
    foreach ([$source, $target] as $name) {
        $server->exec('CREATE DATABASE ' . releaseIdentifier($name) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $ownedDatabases[] = $name;
    }
    foreach ([$reader, $writer] as $user) {
        $server->exec('CREATE USER ' . $server->quote($user) . "@'127.0.0.1' IDENTIFIED BY " . $server->quote($password));
        $ownedUsers[] = $user;
    }
    $server->exec('GRANT SELECT ON ' . releaseIdentifier($source) . '.* TO ' . $server->quote($reader) . "@'127.0.0.1'");
    $server->exec('GRANT ALL PRIVILEGES ON ' . releaseIdentifier($target) . '.* TO ' . $server->quote($writer) . "@'127.0.0.1'");
    $config = array_replace($local, ['APP_ENV' => 'test', 'DB_NAME' => $source, 'DB_USER' => $reader, 'DB_PASSWORD' => $password,
        'DATABASE_RELEASE_DIRECTORY' => $root . '/private/releases', 'MEMORY_PHOTO_DIRECTORY' => $root . '/private/photos', 'SESSION_DIRECTORY' => $root . '/private/sessions']);
    foreach (['site/api', 'private/releases', 'private/photos/nested', 'private/backups', 'off-server'] as $folder) familyReleasePrivateDirectory($root . '/' . $folder);
    $ini = ''; foreach ($config as $key => $value) $ini .= $key . '="' . addcslashes((string)$value, '\\"') . '"' . "\n";
    writePrivateFile($root . '/site/api/.env', $ini);
    writePrivateFile($root . '/private/photos/original.bin', "synthetic original\0bytes");
    writePrivateFile($root . '/private/photos/nested/preview.bin', 'synthetic derivative');
    $sourceDb = connectDatabase(array_replace($config, ['DB_USER' => 'root', 'DB_PASSWORD' => $admin['password']]));
    $sourceDb->exec('CREATE TABLE users (id INT PRIMARY KEY, nickname VARCHAR(40)) ENGINE=InnoDB; CREATE TABLE notes (id INT PRIMARY KEY, owner_id INT, body LONGBLOB, amount DECIMAL(12,2) DEFAULT 1234.50, recorded DATETIME DEFAULT NULL, FOREIGN KEY(owner_id) REFERENCES users(id)) ENGINE=InnoDB;');
    $sourceDb->exec("INSERT INTO users VALUES(1,'Synthetic Dad'),(2,'Synthetic Member')");
    $sourceDb->prepare('INSERT INTO notes (id,owner_id,body) VALUES(?,?,?)')->execute([1,1,"Synthetic private note — Unicode ✓\nsecond line"]);
    $sourceDb->prepare('INSERT INTO notes (id,owner_id,body) VALUES(?,?,?)')->execute([2,2,"Quote ' slash \\ NUL\0 binary\xff ; DROP DATABASE must remain data"]);
    $sourceDb->exec("UPDATE notes SET amount=NULL,recorded='2026-10-23 12:34:56' WHERE id=2");
    $specs = releaseSpecs($sourceDb); $before = releaseData($sourceDb, $specs);
    $keyOptions = ['private_key_bits' => 3072, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
    if (is_file('C:/xampp/apache/conf/openssl.cnf')) $keyOptions['config'] = 'C:/xampp/apache/conf/openssl.cnf';
    $pair = openssl_pkey_new($keyOptions); releaseAssert($pair !== false, 'Disposable key generation failed.');
    openssl_pkey_export($pair, $privatePem, 'synthetic-key-only', $keyOptions);
    writePrivateFile($root . '/private/recipient.pem', openssl_pkey_get_details($pair)['key']);
    $settings = ['config' => $root . '/site/api/.env', 'database' => $source, 'siteRoot' => $root . '/site', 'directory' => $root . '/private/backups',
        'publicKey' => $root . '/private/recipient.pem', 'dump' => 'C:/xampp/mysql/bin/mysqldump.exe', 'dumpMethod' => getenv('ALLIN_BACKUP_DUMP_METHOD') ?: 'external'];
    $result = backupCapture($settings);
    routineCheck($result['tables'] === 2 && $result['photoFiles'] === 2, 'Read-only source credential captures every table and photo file');
    routineCheck(releaseData($sourceDb, $specs) === $before, 'Source database unchanged after capture');
    routineCheck(glob($root . '/private/backups/staging/*') === [], 'Plaintext snapshot and temporary credentials removed');
    $archive = $root . '/private/backups/exports/' . $result['file'];
    $receipt = releaseJson($archive . '.receipt.json');
    routineCheck($receipt['format'] === 'allin-family-transfer-v1' && $receipt['file'] === basename($archive)
        && $receipt['bytes'] === filesize($archive) && $receipt['sha256'] === hash_file('sha256', $archive)
        && $receipt['keyBytes'] === filesize($archive . '.key.json')
        && $receipt['keySha256'] === hash_file('sha256', $archive . '.key.json'), 'Completed transfer receipt verifies both encrypted files');
    $copy = $root . '/off-server/' . $result['file'];
    copy($archive, $copy); copy($archive . '.key.json', $copy . '.key.json');
    routineCheck(hash_file('sha256', $copy) === $result['sha256'], 'Simulated transport copy matches published archive');
    $manifest = backupOpen($copy, $copy . '.key.json', $privatePem, $root . '/recovered', 'synthetic-key-only');
    $restoreConfig = array_replace($config, ['DB_NAME' => $target, 'DB_USER' => $writer]);
    $verified = backupVerifyRestore($root . '/recovered', $restoreConfig, 'C:/xampp/mysql/bin/mysql.exe');
    routineCheck($verified['schemaMatches'] && $verified['rowsMatch'] && $verified['photosMatch'], 'Isolated SQL restore matches schema, row hashes and photo hashes');
    $scratch = connectDatabase($restoreConfig);
    $scratch->exec('ALTER TABLE users MODIFY nickname VARCHAR(40) COLLATE utf8mb4_bin');
    routineCheck(backupSchemaDetails($scratch, releaseSpecs($scratch)) !== $manifest['schemaDetails'], 'Semantic verification detects changed column collation');
    $scratch->exec('ALTER TABLE users MODIFY nickname VARCHAR(40) COLLATE utf8mb4_unicode_ci');
    $scratch->exec('CREATE INDEX fixture_extra_index ON users(nickname)');
    routineCheck(backupSchemaDetails($scratch, releaseSpecs($scratch)) !== $manifest['schemaDetails'], 'Semantic verification detects changed indexes');
    $scratch->exec('DROP INDEX fixture_extra_index ON users');
    routineCheck(backupSchemaDetails($scratch, releaseSpecs($scratch)) === $manifest['schemaDetails'], 'Exact schema semantics match again after fixture repairs');
    $scratch = null;
    routineCheck(file_get_contents($root . '/recovered/photos/original.bin') === "synthetic original\0bytes", 'Original binary photo recovered exactly');
    routineCheck(releaseData($sourceDb, $specs) === $before, 'Source database unchanged after restore rehearsal');
    routineCheck(!is_file($root . '/recovered/restore-connection.ini'), 'Temporary restore credentials removed');
    routineReject(fn() => backupVerifyRestore($root . '/recovered', $config, 'C:/xampp/mysql/bin/mysql.exe'), 'Original source database cannot be selected as restore target');
    routineReject(fn() => backupVerifyRestore($root . '/recovered', array_replace($restoreConfig, ['DB_USER' => 'root', 'DB_PASSWORD' => $admin['password']]), 'C:/xampp/mysql/bin/mysql.exe'), 'Broad administrator credentials rejected for restore');
    routineReject(fn() => backupVerifyRestore($root . '/recovered', $restoreConfig, 'C:/xampp/mysql/bin/mysql.exe'), 'Existing restored database cannot be overwritten');
    routineReject(fn() => backupCapture(array_replace($settings, ['database' => 'wrong'])), 'Wrong explicit source database rejected');
    routineReject(fn() => backupCapture(array_replace($settings, ['directory' => $root . '/site'])), 'Backup storage inside web root rejected');
    routineReject(fn() => backupCapture(array_replace($settings, ['directory' => $root . '/private/photos'])), 'Backup storage overlapping photos rejected');
    writePrivateFile($root . '/private/releases/maintenance.json', '{}');
    routineReject(fn() => backupCapture($settings), 'Migration maintenance defers routine backup');
    unlink($root . '/private/releases/maintenance.json');
    $held = fopen($root . '/private/releases/workflow.lock', 'c+'); flock($held, LOCK_EX);
    routineReject(fn() => backupCapture($settings), 'Concurrent workflow lock rejects another backup'); fclose($held);
    routineReject(fn() => backupCapture(array_replace($settings, ['dumpMethod' => 'external', 'dump' => 'C:/xampp/php/php.exe'])), 'Failed dump utility never publishes an accepted backup');
    routineCheck(glob($root . '/private/backups/staging/*') === [], 'Failed dump cleans private plaintext staging');
    $lease = fopen($root . '/private/releases/requests.lock', 'c+');
    routineCheck(flock($lease, LOCK_EX | LOCK_NB), 'Failed routine backup releases the application lease'); fclose($lease);
    routineCheck(!is_file($root . '/private/releases/maintenance.json'), 'Routine backup failure leaves no persistent maintenance marker');
    routineCheck(releaseData($sourceDb, $specs) === $before, 'All rejection paths leave source database unchanged');
    echo "Routine backup: $checks checks passed using disposable local data only.\n";
} finally {
    foreach (array_reverse($ownedDatabases) as $name) {
        releaseAssert($name === $source || $name === $target, 'Unexpected cleanup database.');
        releaseAssert((bool)preg_match('/^allin_backup_test_[a-f0-9]{16}(?:_restore)?$/D', $name), 'Unsafe cleanup database.');
        $server->exec('DROP DATABASE ' . releaseIdentifier($name));
    }
    foreach ($ownedUsers as $user) {
        releaseAssert($user === $reader || $user === $writer, 'Unsafe cleanup user.');
        $server->exec('DROP USER ' . $server->quote($user) . "@'127.0.0.1'");
    }
    if (is_dir($root)) {
        $resolved = releaseReal($root);
        releaseAssert(releaseWithin($resolved, releaseReal($api . '/.local')) && (bool)preg_match('/routine-backup-test-[a-f0-9]{16}$/D', $resolved), 'Unsafe test cleanup root.');
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            releaseAssert(!$file->isLink(), 'Unexpected test cleanup link.'); if ($file->isDir()) rmdir($file->getPathname()); else unlink($file->getPathname());
        }
        rmdir($resolved);
    }
}
