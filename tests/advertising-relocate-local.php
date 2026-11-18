<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/advertising.php';
$env = loadConfiguration(dirname(__DIR__) . '/.env');
if ($env['APP_ENV'] !== 'local' || $env['ADVERTISING_DIRECTORY'] !== '') throw new RuntimeException('Only the prior implicit local default can be relocated.');
$old = dirname(__DIR__, 2) . '/.private-allin-advertising';
$new = advertDirectory($env);
if (!is_file($old . '/state.json')) { echo "No old state to relocate.\n"; exit; }
if (is_file($new . '/state.json')) throw new RuntimeException('Target state already exists; preserve and inspect both.');
$lock = fopen($old . '/state.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('Old state busy.');
try {
    $raw = file_get_contents($old . '/state.json');
    $state = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    if ($state['current'] !== null || $state['candidate'] !== null || $state['lease'] !== null || $state['config']['enabled'] || array_sum($state['config']['budget']) !== 0 || is_file($old . '/preview-owner.json')) throw new RuntimeException('Refusing automatic relocation of active content.');
    foreach (scandir($old) as $file) if (!in_array($file, ['.', '..', 'state.json', 'state.lock'], true)) throw new RuntimeException('Unexpected old storage file.');
    $destination = fopen($new . '/state.json', 'xb');
    if (!$destination) throw new RuntimeException('Unable to create private destination.');
    try { if (fwrite($destination, $raw) !== strlen($raw) || !fflush($destination)) throw new RuntimeException('Relocation write failed.'); }
    finally { fclose($destination); }
    @chmod($new . '/state.json', 0600);
    if (!hash_equals(hash('sha256', $raw), hash_file('sha256', $new . '/state.json'))) throw new RuntimeException('Relocation verification failed.');
    unlink($old . '/state.json');
} finally { flock($lock, LOCK_UN); fclose($lock); }
unlink($old . '/state.lock'); rmdir($old);
echo "Empty disabled zero-budget state relocated byte-for-byte outside the web root; old files removed.\n";
