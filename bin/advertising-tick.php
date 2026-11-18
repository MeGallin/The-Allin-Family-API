<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors', '0');
ini_set('zend.exception_ignore_args', '1');
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/advertising.php';
try {
    $environment = loadConfiguration(dirname(__DIR__) . '/.env');
    $result = advertRunJob($environment);
    echo json_encode($result, JSON_THROW_ON_ERROR), PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, "Advertising tick failed; review the administration status.\n");
    exit(1);
}
