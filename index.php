<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('zend.exception_ignore_args', '1');
require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/src/database.php';
require __DIR__ . '/src/auth.php';

set_exception_handler(static function (Throwable $error): void {
    if ($error instanceof HttpError) {
        jsonResponse(['error' => $error->getMessage(), 'code' => $error->errorCode], $error->status);
    } else {
        error_log(get_class($error) . ': ' . $error->getMessage());
        jsonResponse(['error' => 'The service is temporarily unavailable. Please try again.'], 500);
    }
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

$routes = [
    '/health' => ['GET', 'HEAD'], '/auth/session' => ['GET'], '/auth/login' => ['POST'],
    '/auth/logout' => ['POST'], '/auth/password' => ['POST'], '/family' => ['GET'],
];
if (!isset($routes[$path])) throw new HttpError(404, 'Not found.');
if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', $routes[$path], true)) {
    header('Allow: ' . implode(', ', $routes[$path]));
    throw new HttpError(405, 'Method not allowed.');
}
if ($path === '/health') {
    jsonResponse(['status' => 'ok', 'service' => $configuration['APP_NAME']]);
    exit;
}

startFamilySession($configuration);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') requireCsrf($configuration);
if ($path === '/auth/logout') {
    $_SESSION = [];
    session_destroy();
    $cookie = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 3600, 'path' => '/', 'secure' => $cookie['secure'],
        'httponly' => true, 'samesite' => 'Lax',
    ]);
    jsonResponse(['ok' => true]);
    exit;
}

$db = connectDatabase($configuration);
if ($path === '/auth/session') jsonResponse(sessionResponse(currentFamilyUser($db)));
if ($path === '/auth/login') jsonResponse(loginFamilyUser($db, $configuration, requestJson()));
if ($path === '/auth/password') jsonResponse(changeFamilyPassword($db, $configuration, requestJson()));
if ($path === '/family') {
    $user = requireFamilyUser($db);
    jsonResponse(['message' => 'Welcome to your private family space.', 'user' => publicFamilyUser($user)]);
}
