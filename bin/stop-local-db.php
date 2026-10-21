<?php
declare(strict_types=1);
require __DIR__ . '/common.php';
$admin = parse_ini_file(dirname(__DIR__) . '/.local/mysql-admin.ini', false, INI_SCANNER_RAW);
$db = new PDO('mysql:host=127.0.0.1;port=3307', 'root', $admin['password']);
$db->exec('SHUTDOWN');
echo "Stopped the isolated family project database.\n";
