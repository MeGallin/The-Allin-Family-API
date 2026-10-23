<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors', '0');
ini_set('zend.exception_ignore_args', '1');
require __DIR__ . '/backups/verify.php';
$ownedDatabase = false; $ownedUser = false; $server = null; $target = ''; $user = ''; $recovered = '';
try {
    $options = getopt('', ['recovered:']);
    releaseAssert(isset($options['recovered']), 'Supply private recovered folder.');
    $recovered = releaseReal($options['recovered']);
    $manifest = releaseJson($recovered . '/manifest.json');
    $local = loadConfiguration(dirname(__DIR__) . '/.env');
    releaseAssert($local['APP_ENV'] === 'local' && $local['DB_HOST'] === '127.0.0.1' && $local['DB_PORT'] === '3307', 'Restore rehearsal requires the isolated local MariaDB service.');
    $target = $manifest['database'] . '_restore'; releaseIdentifier($target);
    releaseAssert(strlen($target) <= 64 && $target !== $local['DB_NAME'], 'Unsafe rehearsal database identity.');
    $admin = parse_ini_file(dirname(__DIR__) . '/.local/mysql-admin.ini', false, INI_SCANNER_RAW);
    $server = new PDO('mysql:host=127.0.0.1;port=3307;charset=utf8mb4', 'root', $admin['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $exists = $server->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?'); $exists->execute([$target]);
    releaseAssert((int)$exists->fetchColumn() === 0, 'Rehearsal target already exists; it will not be modified.');
    $server->exec('CREATE DATABASE ' . releaseIdentifier($target) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'); $ownedDatabase = true;
    $user = 'allin_restore_' . bin2hex(random_bytes(8)); $password = bin2hex(random_bytes(32));
    $account = $server->quote($user) . "@'127.0.0.1'";
    $server->exec('CREATE USER ' . $account . ' IDENTIFIED BY ' . $server->quote($password)); $ownedUser = true;
    $server->exec('GRANT ALL PRIVILEGES ON ' . releaseIdentifier($target) . '.* TO ' . $account);
    $config = array_replace($local, ['DB_NAME' => $target, 'DB_USER' => $user, 'DB_PASSWORD' => $password]);
    $result = backupVerifyRestore($recovered, $config, 'C:/xampp/mysql/bin/mysql.exe');
    $result['environment'] = 'isolated-local-127.0.0.1:3307';
    $result['sourceSqlSha256'] = $manifest['databaseSha256'];
    $result['photoHashes'] = $manifest['photos'];
    releaseWrite($recovered . '/restore-verification.json', $result);
    echo json_encode(array_intersect_key($result, array_flip(['verifiedAt', 'tables', 'photoFiles', 'schemaMatches', 'rowsMatch', 'photosMatch', 'environment'])), JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $error) {
    if ($recovered !== '') releaseWrite($recovered . '/restore-error.json', ['failedAt' => gmdate('c'), 'type' => get_class($error), 'detail' => $error->getMessage()]);
    fwrite(STDERR, "ISOLATED RESTORE FAILED. Inspect private recovery diagnostics.\n");
    $failed = true;
} finally {
    // Only objects created by this invocation on the explicitly local service.
    if ($server && $ownedUser) $server->exec('DROP USER ' . $server->quote($user) . "@'127.0.0.1'");
    if ($server && $ownedDatabase) $server->exec('DROP DATABASE ' . releaseIdentifier($target));
}
if (isset($failed)) exit(1);
