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
        'APP_ORIGIN' => 'http://127.0.0.1:5173',
        'APP_KEY' => '',
        'SESSION_COOKIE_SECURE' => '1',
        'SESSION_IDLE_SECONDS' => '1800',
        'SESSION_LIFETIME_SECONDS' => '43200',
        'SESSION_DIRECTORY' => dirname(__DIR__) . '/.local/sessions',
        'DB_HOST' => '127.0.0.1',
        'DB_PORT' => '3306',
        'DB_NAME' => 'theallinfamily',
        'DB_USER' => '',
        'DB_PASSWORD' => '',
        'MEMORY_PHOTO_DIRECTORY' => dirname(__DIR__) . '/.local/memory-photos',
    ];

    foreach ($defaults as $key => $default) {
        $environment = getenv($key);
        $defaults[$key] = $environment !== false ? $environment : (string) ($values[$key] ?? $default);
    }

    return $defaults;
}

final class HttpError extends RuntimeException
{
    public int $status;
    public string $errorCode;

    public function __construct(int $status, string $message, string $errorCode = 'request_failed')
    {
        parent::__construct($message);
        $this->status = $status;
        $this->errorCode = $errorCode;
    }
}

function requestJson(int $maxBytes = 8192): array
{
    if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') {
        throw new HttpError(415, 'Send the request as JSON.');
    }
    $raw = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
    if ($raw === false || strlen($raw) > $maxBytes) throw new HttpError(413, 'The request is too large.');
    try {
        $body = json_decode($raw, false, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        throw new HttpError(400, 'Invalid JSON request.');
    }
    if (!is_object($body)) throw new HttpError(400, 'Expected a JSON object.');
    return (array) $body;
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
