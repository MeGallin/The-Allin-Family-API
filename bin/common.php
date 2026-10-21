<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('zend.exception_ignore_args', '1');
require_once dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/database.php';
require_once dirname(__DIR__) . '/src/auth.php';

function runMigrations(PDO $db): void
{
    $db->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
        name VARCHAR(180) PRIMARY KEY, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB');
    foreach (glob(dirname(__DIR__) . '/migrations/*.sql') as $file) {
        $name = basename($file);
        $query = $db->prepare('SELECT COUNT(*) FROM schema_migrations WHERE name = ?');
        $query->execute([$name]);
        if ((int) $query->fetchColumn() !== 0) continue;
        $db->exec(file_get_contents($file));
        $db->prepare('INSERT INTO schema_migrations (name) VALUES (?)')->execute([$name]);
        echo 'Applied migration: ' . $name . PHP_EOL;
    }
}

function writePrivateFile(string $path, string $content): void
{
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
    if (file_put_contents($path, $content, LOCK_EX) === false) throw new RuntimeException('Unable to write local settings.');
    @chmod($path, 0600);
}
