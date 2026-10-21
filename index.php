<?php
declare(strict_types=1);

ini_set('display_errors', '0');
require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/src/database.php';

set_exception_handler(static function (Throwable $error): void {
    error_log((string) $error);
    jsonResponse(['error' => 'An internal server error occurred.'], 500);
});

$configuration = loadConfiguration(__DIR__ . '/.env');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (!is_string($path)) {
    jsonResponse(['error' => 'Invalid request path.'], 400);
    exit;
}

// Support an Apache subdirectory, including the existing XAMPP project path.
$mount = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php')), '/');
if ($mount !== '' && $mount !== '.' && ($path === $mount || str_starts_with($path, $mount . '/'))) {
    $path = substr($path, strlen($mount)) ?: '/';
}

if ($path !== '/health') {
    jsonResponse(['error' => 'Not found.'], 404);
    exit;
}

if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    jsonResponse(['error' => 'Method not allowed.'], 405);
    exit;
}

// Public liveness only. No accounts, configuration, secrets or family data.
jsonResponse(['status' => 'ok', 'service' => $configuration['APP_NAME']]);
