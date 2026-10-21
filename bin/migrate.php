<?php
declare(strict_types=1);
require __DIR__ . '/common.php';
$root = dirname(__DIR__);
$config = loadConfiguration($root . '/.env');
if (isset(getopt('', ['local'])['local'])) {
    if ($config['APP_ENV'] !== 'local' || $config['DB_HOST'] !== '127.0.0.1' || $config['DB_PORT'] !== '3307') {
        throw new RuntimeException('Local migration credentials can only be used for the isolated local instance.');
    }
    $admin = parse_ini_file($root . '/.local/mysql-admin.ini', false, INI_SCANNER_RAW);
    $config['DB_USER'] = 'root';
    $config['DB_PASSWORD'] = $admin['password'];
}
runMigrations(connectDatabase($config));
