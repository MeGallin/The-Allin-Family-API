<?php
declare(strict_types=1);
// Local operational metadata only. Never emits configuration, content or credentials.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors', '0');
ini_set('zend.exception_ignore_args', '1');
set_exception_handler(static function (Throwable $error): void { fwrite(STDERR, "Local advertising preflight failed.\n"); exit(1); });
$api = dirname(__DIR__, 2);
require $api . '/src/bootstrap.php';
require $api . '/src/advertising.php';
require __DIR__ . '/start-policy.php';
$environment = loadConfiguration($api . '/.env');
if ($environment['APP_ENV'] !== 'local' || rtrim($environment['ADVERTISING_NODE_URL'], '/') !== 'http://127.0.0.2:8000'
    || strlen($environment['ADVERTISING_SIGNING_SECRET']) < 32) throw new RuntimeException('Local configuration required.');
if (($argv[1] ?? '') === 'engine') {
    $status = advertNodeRequest($environment, '/v1/status', [], 8);
    if (($status['ok'] ?? false) !== true || ($status['providerConfigured'] ?? false) !== true) throw new RuntimeException('Engine unavailable.');
    echo "{\"ready\":true}\n";
    exit;
}
// Read under the stable lock; do not initialise/migrate/rewrite durable advert state.
$directory = advertDirectory($environment);
$lock = fopen($directory . '/state.lock', 'r');
if (!$lock) throw new RuntimeException('Existing state required.');
$until = microtime(true) + 2;
while (!flock($lock, LOCK_SH | LOCK_NB)) {
    if (microtime(true) >= $until) throw new RuntimeException('State busy.');
    usleep(10000);
}
try {
    $raw = file_get_contents($directory . '/state.json', false, null, 0, 524289);
    if ($raw === false || strlen($raw) > 524288) throw new RuntimeException('State unavailable.');
    $state = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    $safeToArm = advertisingSupervisorSafeToArm($state, time());
    echo json_encode(['local' => true, 'safeToArm' => $safeToArm, 'directory' => $directory], JSON_THROW_ON_ERROR), "\n";
} finally { flock($lock, LOCK_UN); fclose($lock); }
