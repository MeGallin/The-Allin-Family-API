<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__, 2) . '/src/bootstrap.php';
require dirname(__DIR__, 2) . '/src/advertising.php';
$directory = $argv[1] ?? '';
if (!preg_match('~^' . preg_quote(str_replace('\\', '/', sys_get_temp_dir()), '~') . '/allin-advert-integration-[a-f0-9]{24}$~D', str_replace('\\', '/', $directory))) throw new RuntimeException('Use only a disposable advertising fixture.');
$environment = ['APP_ENV' => 'test', 'ADVERTISING_DIRECTORY' => $directory, 'ADVERTISING_SIGNING_SECRET' => 'synthetic-integration-signing-secret-never-real'];
$body = json_decode(file_get_contents('php://stdin'), true, 24, JSON_THROW_ON_ERROR);
$now = $body['now'] ?? time();
try {
    if ($body['action'] === 'init') {
        advertTransaction($environment, static function (array &$s) use ($now): void {
            $s = advertInitialState(); $s['config']['enabled'] = true;
            $s['config']['prices']['checkedAt'] = gmdate('Y-m-d', $now);
            $s['config']['schedule']['mode'] = 'interval'; $s['config']['schedule']['intervalSeconds'] = 60;
            $s['config']['schedule']['preparationLeadSeconds'] = 60;
            if ($GLOBALS['body']['images'] ?? false) { $s['config']['image']['enabled'] = true; $s['config']['image']['size'] = '1024x1024'; }
            foreach ($s['config']['budget'] as &$value) $value = 10000000; unset($value);
            foreach ($s['config']['features'] as &$f) $f['enabled'] = in_array($f['id'], ['shopping', 'jobs'], true); unset($f);
            $s['draft'] = $s['config'];
        }); $result = ['ok' => true];
    } elseif ($body['action'] === 'claim') $result = advertClaimJob($environment, true, $now);
    elseif ($body['action'] === 'finish') { advertFinishJob($environment, $body['job'], $body['result'], $now); $result = ['ok' => true]; }
    elseif ($body['action'] === 'snapshot') $result = advertGetAdmin($environment);
    elseif ($body['action'] === 'admin') {
        $snapshot = advertGetAdmin($environment);
        $result = advertAdminAction($environment, ['version' => $snapshot['version'], 'action' => $body['command'], 'candidateId' => $snapshot['candidate']['id'] ?? null], $now, 42);
    } else throw new RuntimeException('Unknown fixture action.');
    echo json_encode($result, JSON_THROW_ON_ERROR), PHP_EOL;
} catch (HttpError $e) { echo json_encode(['error' => $e->getMessage(), 'status' => $e->status], JSON_THROW_ON_ERROR), PHP_EOL; }
