<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors', '0'); ini_set('zend.exception_ignore_args', '1');
require dirname(__DIR__) . '/src/bootstrap.php'; require dirname(__DIR__) . '/src/advertising.php';
set_exception_handler(static function (Throwable $e): void { fwrite(STDERR, "Local advertising worker command failed.\n"); exit(1); });
$environment = loadConfiguration(dirname(__DIR__) . '/.env');
if ($environment['APP_ENV'] !== 'local' || rtrim($environment['ADVERTISING_NODE_URL'], '/') !== 'http://127.0.0.2:8000') throw new RuntimeException('Local loopback configuration required.');
$mode = $argv[1] ?? 'status';
if (!in_array($mode, ['run', 'stop', 'status'], true)) throw new RuntimeException('Unknown worker command.');
$directory = advertDirectory($environment);
$lockPath = $directory . '/worker.lock'; $metadataPath = $directory . '/worker.json'; $stopPath = $directory . '/worker-stop.json';

function advertisingWorkerWrite(string $path, array $data): void
{
    $raw = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $temporary = $path . '.tmp';
    if (file_put_contents($temporary, $raw, LOCK_EX) !== strlen($raw)) throw new RuntimeException('Worker metadata unavailable.');
    @chmod($temporary, 0600);
    if (!rename($temporary, $path)) throw new RuntimeException('Worker metadata unavailable.');
}
function advertisingWorkerRead(string $path): array
{
    if (!is_file($path)) return [];
    $raw = file_get_contents($path, false, null, 0, 8193);
    if ($raw === false || strlen($raw) > 8192) throw new RuntimeException('Worker metadata unavailable.');
    $data = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($data)) throw new RuntimeException('Worker metadata unavailable.');
    return $data;
}
$workerLock = fopen($lockPath, 'c');
if (!$workerLock) throw new RuntimeException('Worker lock unavailable.');
@chmod($lockPath, 0600);
$acquired = flock($workerLock, LOCK_EX | LOCK_NB);
$metadata = advertisingWorkerRead($metadataPath);
if ($mode !== 'run') {
    if ($acquired) flock($workerLock, LOCK_UN);
    fclose($workerLock);
    $running = !$acquired;
    if ($mode === 'stop' && $running) {
        if (!is_string($metadata['token'] ?? null)) throw new RuntimeException('Worker owner unavailable.');
        advertisingWorkerWrite($stopPath, ['token' => $metadata['token']]);
    }
    $stop = advertisingWorkerRead($stopPath);
    $stopRequested = $running && ($stop['token'] ?? null) === ($metadata['token'] ?? null) && isset($metadata['token']);
    unset($metadata['token']);
    echo json_encode(['running' => $running, 'stopRequested' => $stopRequested, 'worker' => $metadata], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), PHP_EOL;
    exit;
}
if (!$acquired) { fclose($workerLock); fwrite(STDERR, "A local advertising worker is already running.\n"); exit(1); }
$token = bin2hex(random_bytes(16));
$metadata = ['token' => $token, 'pid' => getmypid(), 'startedAt' => time(), 'lastTickAt' => null, 'lastOutcome' => 'starting'];
advertisingWorkerWrite($metadataPath, $metadata);
$previousOutcome = null;
echo "Local advertising worker running; checks every5seconds. Use stop for a graceful exit after any active bounded job.\n";
try {
    while (true) {
        $stop = advertisingWorkerRead($stopPath);
        if (($stop['token'] ?? null) === $token) break;
        try {
            advertRunJob($environment);
            $snapshot = advertGetAdmin($environment);
            $outcome = !$snapshot['config']['enabled'] ? 'disabled' : ($snapshot['config']['paused'] ? 'paused' : $snapshot['lastOutcome']);
            $metadata['configRevision'] = $snapshot['configRevision'];
        } catch (HttpError $error) {
            $outcome = $error->errorCode === 'advert_budget' ? 'budget_blocked' : 'tick_blocked';
        } catch (Throwable $error) { $outcome = 'tick_failed'; }
        $metadata['lastTickAt'] = time(); $metadata['lastOutcome'] = $outcome;
        advertisingWorkerWrite($metadataPath, $metadata);
        if ($previousOutcome !== $outcome) { echo 'Advertising worker: ' . $outcome . PHP_EOL; $previousOutcome = $outcome; }
        // Short sleeps allow stop to be noticed promptly between sequential jobs.
        for ($step = 0; $step < 10; $step++) {
            usleep(500000);
            if ((advertisingWorkerRead($stopPath)['token'] ?? null) === $token) break;
        }
    }
} finally {
    $metadata['lastOutcome'] = 'stopped'; $metadata['stoppedAt'] = time();
    advertisingWorkerWrite($metadataPath, $metadata);
    if ((advertisingWorkerRead($stopPath)['token'] ?? null) === $token) unlink($stopPath);
    flock($workerLock, LOCK_UN); fclose($workerLock);
}
echo "Local advertising worker stopped.\n";
