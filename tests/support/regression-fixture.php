<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/bin/common.php';
$root = dirname(__DIR__, 2);
$local = loadConfiguration($root . '/.env');
if ($local['APP_ENV'] !== 'local' || $local['DB_HOST'] !== '127.0.0.1' || $local['DB_PORT'] !== '3307' || $local['DB_NAME'] === 'theallinfamily_regression_test') {
    throw new RuntimeException('Regression fixtures require the separate local server at 127.0.0.1:3307.');
}
$requestedRun = $argv[2] ?? '';
if (!preg_match('/^[a-f0-9]{48}$/D', $requestedRun)) throw new RuntimeException('Supply the exact regression run ownership token.');
$suite = $argv[3] ?? 'regression';
if (!in_array($suite, ['regression','sudoku','daily-word'], true)) throw new RuntimeException('Unknown fixture suite.');
if ($suite !== 'regression' && $local['DB_NAME'] !== 'theallinfamily') throw new RuntimeException('Sudoku QA requires the expected development config, never another fixture.');
$suffix = substr($requestedRun, 0, 12);
$directory = $root . ($suite !== 'regression' ? '/.local/'.$suite.'-regression-' . $suffix : '/.local/regression');
$file = $directory . '/fixture.json';
if (is_link($root . '/.local') || is_link($directory) || (is_dir($directory) && dirname((string)realpath($directory)) !== realpath($root . '/.local'))) throw new RuntimeException('Unsafe fixture root directory.');
$name = $suite !== 'regression' ? 'allin_'.($suite==='sudoku'?'sudoku':'word').'_test_' . $suffix : 'theallinfamily_regression_test';
$account = $suite !== 'regression' ? 'allin_'.($suite==='sudoku'?'sd':'dw').'_' . $suffix : 'allinfamily_regression';
$admin = parse_ini_file($root . '/.local/mysql-admin.ini', false, INI_SCANNER_RAW);
$db = new PDO('mysql:host=127.0.0.1;port=3307;charset=utf8mb4', 'root', $admin['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$mode = $argv[1] ?? '';
if ($mode === 'cleanup') {
    if (!is_file($file)) throw new RuntimeException('Missing fixture ownership marker; refusing cleanup.');
    $config = json_decode(file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
    if (!hash_equals($config['TEST_RUN_ID'], $requestedRun)) throw new RuntimeException('This fixture belongs to another run.');
    if ($config['DB_NAME'] !== $name || $config['DB_USER'] !== $account || $config['APP_ENV'] !== 'test' || $config['SESSION_DIRECTORY'] !== $directory . '/sessions' || $config['MEMORY_PHOTO_DIRECTORY'] !== $directory . '/photos') throw new RuntimeException('Invalid fixture configuration.');
    $marker = $db->query('SELECT run_id FROM `' . $name . '`.regression_fixture_marker')->fetchColumn();
    if (!hash_equals($config['TEST_RUN_ID'], (string)$marker)) throw new RuntimeException('Fixture ownership mismatch.');
    // Exact disposable directories only. Refuse unknown files and symlinks; no recursive deletion.
    $files = [];
    foreach (['sessions' => '/^sess_[A-Za-z0-9,-]+$/D', 'photos' => '/^[a-f0-9]{64}(?:\.preview\.lock|\.preview-v1-w(?:160|480|960|1600)\.(?:webp|jpg|png))?$/D'] as $folder => $pattern) {
        $path = $directory . '/' . $folder;
        if (!is_dir($path)) continue;
        if (is_link($path) || dirname((string)realpath($path)) !== realpath($directory)) throw new RuntimeException('Unsafe fixture directory.');
        foreach (scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $target = $path . '/' . $entry;
            if (!preg_match($pattern, $entry) || !is_file($target) || is_link($target) || dirname((string)realpath($target)) !== realpath($path)) throw new RuntimeException('Unexpected fixture file; inspect before cleanup.');
            $files[] = $target;
        }
    }
    $db->exec('DROP DATABASE `' . $name . '`');
    $db->exec("DROP USER IF EXISTS '$account'@'localhost'");
    foreach ($files as $target) if (!unlink($target)) throw new RuntimeException('Unable to remove fixture file.');
    foreach (['sessions','photos'] as $folder) if (is_dir($directory . '/' . $folder)) rmdir($directory . '/' . $folder);
    unlink($file);
    if($db->query("SHOW DATABASES LIKE '$name'")->fetch() || $db->query("SELECT 1 FROM mysql.user WHERE User='$account'")->fetch())throw new RuntimeException('Disposable database/account cleanup incomplete.');
    echo "Removed disposable regression database, account, sessions and photos.\n";
    exit;
}
if ($mode !== 'create') throw new RuntimeException('Use create or cleanup.');
if (is_file($file) || $db->query("SHOW DATABASES LIKE '$name'")->fetch() || $db->query("SELECT 1 FROM mysql.user WHERE User='$account'")->fetch()) throw new RuntimeException('A regression fixture already exists. Inspect it before running cleanup.');
$run = $requestedRun;
$password = bin2hex(random_bytes(24));
$config = [
    'DB_HOST'=>'127.0.0.1','DB_PORT'=>'3307','DB_NAME'=>$name,'DB_USER'=>$account,'DB_PASSWORD'=>bin2hex(random_bytes(24)),
    'APP_ENV'=>'test','APP_KEY'=>bin2hex(random_bytes(32)),'APP_ORIGIN'=>'http://127.0.0.1:5173','SESSION_COOKIE_SECURE'=>'0',
    'SESSION_IDLE_SECONDS'=>'1800','SESSION_DIRECTORY'=>$directory.'/sessions','MEMORY_PHOTO_DIRECTORY'=>$directory.'/photos',
    'TEST_RUN_ID'=>$run,'TEST_PASSWORD'=>$password,'TEST_USERS'=>[],
];
$db->exec('CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$db->exec('USE `' . $name . '`');
$db->exec('CREATE TABLE regression_fixture_marker (run_id VARCHAR(48) PRIMARY KEY)');
$db->prepare('INSERT INTO regression_fixture_marker VALUES (?)')->execute([$run]);
// Persist ownership before migrations so a failed migration remains recoverable.
writePrivateFile($file, json_encode($config, JSON_THROW_ON_ERROR));
runMigrations($db);
$db->exec("CREATE USER '$account'@'localhost' IDENTIFIED BY " . $db->quote($config['DB_PASSWORD']));
$db->exec("GRANT SELECT,INSERT,UPDATE,DELETE ON `$name`.* TO '$account'@'localhost'");
foreach (['admin','owner','member'] as $role) {
    $username = 'regression_' . $role;
    $db->prepare('INSERT INTO users (username,display_name,password_hash,must_change_password,role) VALUES (?,?,?,0,?)')->execute([$username, 'Regression ' . $role, hashFamilyPassword($password), $role === 'admin' ? 'admin' : 'member']);
    $config['TEST_USERS'][$role] = ['id'=>(int)$db->lastInsertId(), 'username'=>$username];
}
writePrivateFile($file, json_encode($config, JSON_THROW_ON_ERROR));
echo "Created isolated regression fixture; family database was not opened.\n";
