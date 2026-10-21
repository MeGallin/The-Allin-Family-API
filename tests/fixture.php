<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bin/common.php';
$root = dirname(__DIR__);
$config = loadConfiguration($root . '/.env');
if ($config['APP_ENV'] !== 'local' || $config['DB_PORT'] !== '3307') {
    throw new RuntimeException('Fixtures require the isolated local database.');
}
$admin = parse_ini_file($root . '/.local/mysql-admin.ini', false, INI_SCANNER_RAW);
$db = new PDO('mysql:host=127.0.0.1;port=3307;charset=utf8mb4', 'root', $admin['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$file = $root . '/.local/auth-test.json';
$options = getopt('', ['create', 'cleanup', 'deactivate:']);
if (isset($options['cleanup'])) {
    if (!is_file($file)) exit;
    $fixture = json_decode(file_get_contents($file), true, 16, JSON_THROW_ON_ERROR);
    if ($fixture['DB_NAME'] !== 'theallinfamily_auth_test') throw new RuntimeException('Invalid fixture marker.');
    $db->exec('DROP DATABASE `theallinfamily_auth_test`');
    $db->exec("DROP USER IF EXISTS 'allinfamily_test'@'localhost'");
    unlink($file);
    echo "Removed disposable authentication fixture.\n";
    exit;
}
if (isset($options['deactivate'])) {
    if (!is_file($file) || $options['deactivate'] !== 'test_disabled') throw new RuntimeException('Invalid test account.');
    $db->exec("UPDATE theallinfamily_auth_test.users SET is_active = 0 WHERE username = 'test_disabled'");
    exit;
}
if (!isset($options['create'])) throw new RuntimeException('Choose a fixture action.');
if (is_file($file) || $db->query("SHOW DATABASES LIKE 'theallinfamily_auth_test'")->fetch()) {
    throw new RuntimeException('A test database already exists; inspect it before cleaning it up.');
}
$password = bin2hex(random_bytes(12));
$dbPassword = bin2hex(random_bytes(24));
$db->exec('CREATE DATABASE theallinfamily_auth_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$db->exec('USE theallinfamily_auth_test');
runMigrations($db);
$db->exec("CREATE USER 'allinfamily_test'@'localhost' IDENTIFIED BY " . $db->quote($dbPassword));
$db->exec("GRANT SELECT, INSERT, UPDATE, DELETE ON theallinfamily_auth_test.* TO 'allinfamily_test'@'localhost'");
foreach (['test_member', 'test_temporary', 'test_disabled', 'test_throttle'] as $username) {
    $db->prepare('INSERT INTO users (username, display_name, password_hash, must_change_password) VALUES (?, ?, ?, ?)')
        ->execute([$username, 'Test Member', hashFamilyPassword($password), $username === 'test_temporary' ? 1 : 0]);
}
writePrivateFile($file, json_encode([
    'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3307', 'DB_NAME' => 'theallinfamily_auth_test',
    'DB_USER' => 'allinfamily_test', 'DB_PASSWORD' => $dbPassword,
    'APP_ENV' => 'test', 'APP_KEY' => bin2hex(random_bytes(32)),
    'APP_ORIGIN' => 'http://127.0.0.1:5173', 'SESSION_COOKIE_SECURE' => '0',
    'SESSION_IDLE_SECONDS' => '2',
    'SESSION_DIRECTORY' => $root . '/.local/test-sessions',
    'TEST_PASSWORD' => $password,
], JSON_THROW_ON_ERROR));
echo "Created a disposable test database; family accounts are untouched.\n";
