<?php
declare(strict_types=1);

function loadConfiguration(string $path): array
{
    $values = [];
    if (is_file($path)) {
        $values = @parse_ini_file($path, false, INI_SCANNER_RAW);
        if ($values === false) {
            throw new RuntimeException('Unable to parse the API environment file.');
        }
    }

    $defaults = [
        'APP_NAME' => 'The Allin Family API',
        'APP_ENV' => 'local',
        'DB_HOST' => '127.0.0.1',
        'DB_PORT' => '3306',
        'DB_NAME' => 'theallinfamily',
        'DB_USER' => '',
        'DB_PASSWORD' => '',
    ];

    foreach ($defaults as $key => $default) {
        $environment = getenv($key);
        $defaults[$key] = $environment !== false ? $environment : (string) ($values[$key] ?? $default);
    }

    return $defaults;
}

function jsonResponse(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
        echo json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
