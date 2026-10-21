<?php
declare(strict_types=1);
require __DIR__ . '/common.php';

$root = dirname(__DIR__);
$config = loadConfiguration($root . '/.env');
if ($config['APP_ENV'] !== 'local' || $config['DB_HOST'] !== '127.0.0.1' || $config['DB_PORT'] !== '3307') {
    throw new RuntimeException('This script is only for the isolated local database on 127.0.0.1:3307.');
}
if (!preg_match('/^[a-zA-Z0-9_]+$/', $config['DB_NAME'])) throw new RuntimeException('Invalid database name.');
$adminFile = $root . '/.local/mysql-admin.ini';
$admin = is_file($adminFile) ? parse_ini_file($adminFile, false, INI_SCANNER_RAW) : ['password' => ''];
$db = new PDO('mysql:host=127.0.0.1;port=3307;charset=utf8mb4', 'root', $admin['password'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5,
]);
$name = $config['DB_NAME'];
$db->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$db->exec("USE `$name`");
runMigrations($db);

$appPassword = $config['DB_USER'] === 'allinfamily_app' && $config['DB_PASSWORD'] !== ''
    ? $config['DB_PASSWORD'] : bin2hex(random_bytes(24));
if ($appPassword === '') throw new RuntimeException('An empty app database password is not permitted.');
$quotedPassword = $db->quote($appPassword);
$db->exec("CREATE USER IF NOT EXISTS 'allinfamily_app'@'localhost' IDENTIFIED BY $quotedPassword");
$db->exec("ALTER USER 'allinfamily_app'@'localhost' IDENTIFIED BY $quotedPassword");
$db->exec("GRANT SELECT, INSERT, UPDATE, DELETE ON `$name`.* TO 'allinfamily_app'@'localhost'");
$config['DB_USER'] = 'allinfamily_app';
$config['DB_PASSWORD'] = $appPassword;
$config['APP_KEY'] = strlen($config['APP_KEY']) >= 32 ? $config['APP_KEY'] : bin2hex(random_bytes(32));
$config['SESSION_COOKIE_SECURE'] = '0';
$content = "; Local server-only settings. Never commit this file.\n";
foreach ($config as $key => $value) {
    if ($key === 'SESSION_DIRECTORY') $value = str_replace('\\', '/', $value);
    $content .= $key . '="' . addcslashes($value, '\\"') . '"' . "\n";
}
writePrivateFile($root . '/.env', $content);

if (!is_file($adminFile)) {
    $rootPassword = bin2hex(random_bytes(24));
    writePrivateFile($adminFile, 'password="' . $rootPassword . '"' . "\n");
    try {
        $db->exec("ALTER USER 'root'@'localhost' IDENTIFIED BY " . $db->quote($rootPassword));
    } catch (Throwable $error) {
        unlink($adminFile);
        throw $error;
    }
}
echo "Local database ready. App credentials saved in the ignored API .env.\n";
