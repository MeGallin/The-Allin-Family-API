<?php
declare(strict_types=1);
// Optional LOCAL-only dedicated listener avoids PHP built-in server callback deadlock.
ini_set('display_errors', '0');
ini_set('zend.exception_ignore_args', '1');
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/advertising.php';
set_exception_handler(static function (Throwable $e): void {
    jsonResponse(['error' => $e instanceof HttpError ? $e->getMessage() : 'Machine service unavailable.'], $e instanceof HttpError ? $e->status : 503);
});
$environment = loadConfiguration(dirname(__DIR__) . '/.env');
if (isset($_SERVER['HTTP_ORIGIN']) || !in_array($environment['APP_ENV'], ['local', 'test'], true) || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)
    || !in_array($_SERVER['REQUEST_URI'] ?? '', ['/advertising/machine', '/advertising/machine/image'], true) || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') throw new HttpError(404, 'Not found.');
advertDispatchMachine($environment, $_SERVER['REQUEST_URI']);
