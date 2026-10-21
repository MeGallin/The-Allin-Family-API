<?php
declare(strict_types=1);

// Liveness checks do not open MySQL; authenticated routes do.
function connectDatabase(array $configuration): PDO
{
    if ($configuration['DB_USER'] === '') {
        throw new RuntimeException('Database credentials have not been configured.');
    }

    if (!ctype_digit($configuration['DB_PORT']) ||
        preg_match('/[;\r\n]/', $configuration['DB_HOST'] . $configuration['DB_NAME'])) {
        throw new RuntimeException('Invalid database connection configuration.');
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $configuration['DB_HOST'],
        $configuration['DB_PORT'],
        $configuration['DB_NAME']
    );

    $db = new PDO($dsn, $configuration['DB_USER'], $configuration['DB_PASSWORD'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    // DATETIME defaults must use the same UTC clock as explicit audit/usage writes.
    $db->exec("SET time_zone = '+00:00'");
    return $db;
}
