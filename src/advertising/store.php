<?php
declare(strict_types=1);

function advertInitialState(): array
{
    $config = advertDefaultConfig();
    return ['schema' => 3, 'version' => 1, 'configRevision' => 1, 'draftVersion' => 1,
        'config' => $config, 'draft' => $config, 'current' => null, 'candidate' => null,
        'lease' => null, 'fence' => 0, 'blockedUntil' => 0, 'nextEligibleAt' => null, 'retryAt' => 0, 'attempts' => 0,
        'lastSeenAt' => 0, 'lastOutcome' => 'disabled', 'usage' => [], 'audit' => [], 'nonces' => [], 'rotationCredits' => []];
}

function advertKnownWebRoot(string $source): string
{
    $source = str_replace('\\', '/', $source);
    foreach (['/htdocs/', '/public_html/'] as $segment) {
        $at = stripos($source, $segment);
        if ($at !== false) return substr($source, 0, $at + strlen($segment) - 1);
    }
    return '';
}

function advertDirectory(array $environment): string
{
    $directory = $environment['ADVERTISING_DIRECTORY'] ?? '';
    if ($directory === '') {
        if (!in_array($environment['APP_ENV'] ?? '', ['local', 'test'], true)) throw new HttpError(503, 'Private advertising storage is not configured.');
        $localRoot = getenv('LOCALAPPDATA');
        if (!$localRoot) throw new HttpError(503, 'Configure private advertising storage outside the web root.');
        $directory = rtrim($localRoot, '/\\') . '/TheAllinFamily/Advertising';
    }
    if (!preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~', $directory)) throw new RuntimeException('Advertising storage must be absolute.');
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('Advertising storage unavailable.');
    $real = str_replace('\\', '/', (string)realpath($directory));
    // CLI cron has no DOCUMENT_ROOT; derive common deployment web roots from
    // the API source path so it cannot initialise public_html state either.
    $knownWebRoot = advertKnownWebRoot(dirname(__DIR__, 2));
    foreach ([dirname(__DIR__, 2), $_SERVER['DOCUMENT_ROOT'] ?? '', $knownWebRoot] as $public) {
        if ($public === '' || !realpath($public)) continue;
        $root = rtrim(str_replace('\\', '/', (string)realpath($public)), '/');
        if (strtolower($real) === strtolower($root) || str_starts_with(strtolower($real), strtolower($root) . '/')) throw new RuntimeException('Advertising storage must be outside the public root.');
    }
    @chmod($directory, 0700);
    return $directory;
}

function advertTransaction(array $environment, callable $operation): mixed
{
    $directory = advertDirectory($environment);
    $lock = fopen($directory . '/state.lock', 'c');
    if (!$lock) throw new HttpError(503, 'Advertising storage unavailable.');
    @chmod($directory . '/state.lock', 0600);
    $until = microtime(true) + 2;
    while (!flock($lock, LOCK_EX | LOCK_NB)) {
        if (microtime(true) >= $until) { fclose($lock); throw new HttpError(503, 'Advertising is busy. Try again shortly.'); }
        usleep(10000);
    }
    $temporary = null;
    try {
        $file = $directory . '/state.json';
        if (is_file($file)) {
            $raw = file_get_contents($file, false, null, 0, 524289);
            if ($raw === false || strlen($raw) > 524288) throw new RuntimeException('Advertising state exceeds its limit.');
            $state = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($state) || !in_array($state['schema'] ?? null, [1, 2, 3], true) || !is_int($state['version'] ?? null)) throw new RuntimeException('Invalid advertising state.');
            if ($state['schema'] === 1) {
                // Add disabled image defaults without changing current copy,
                // existing budgets/counters, candidate revision or admin draft.
                foreach (['config', 'draft'] as $slot) {
                    $state[$slot]['image'] = advertDefaultConfig()['image'];
                    $state[$slot]['budget']['dailyImages'] = 0; $state[$slot]['budget']['monthlyImages'] = 0;
                }
                $state['schema'] = 2;
            }
            if ($state['schema'] === 2) {
                // Explicit additive composition upgrade. Do not rewrite existing
                // payloads, image references, usage, revision or pause controls.
                foreach (['config', 'draft'] as $slot) {
                    if (!array_key_exists('complexity', $state[$slot]['image'])) $state[$slot]['image']['complexity'] = advertDefaultConfig()['image']['complexity'];
                    if (!array_key_exists('layouts', $state[$slot]['image'])) $state[$slot]['image']['layouts'] = advertDefaultConfig()['image']['layouts'];
                    if (!array_key_exists('maxHeadlineWords', $state[$slot]['image'])) $state[$slot]['image']['maxHeadlineWords'] = advertDefaultConfig()['image']['maxHeadlineWords'];
                }
                $state['schema'] = 3;
            }
            if (!array_key_exists('rotationCredits', $state)) $state['rotationCredits'] = [];
            if (!is_array($state['rotationCredits'])) throw new RuntimeException('Invalid rotation metadata.');
        } else $state = advertInitialState();
        $result = $operation($state);
        $encoded = json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($encoded) > 524288) throw new RuntimeException('Advertising state exceeds its limit.');
        $temporary = $directory . '/state-' . bin2hex(random_bytes(12)) . '.tmp';
        $stream = fopen($temporary, 'xb');
        if (!$stream) throw new RuntimeException('Advertising state write failed.');
        try {
            @chmod($temporary, 0600);
            if (fwrite($stream, $encoded) !== strlen($encoded) || !fflush($stream) || (function_exists('fsync') && !fsync($stream))) throw new RuntimeException('Advertising state write failed.');
        } finally { fclose($stream); }
        if (!rename($temporary, $file)) throw new RuntimeException('Advertising state replacement failed.');
        $temporary = null;
        // Only orphan temporary state payloads, under the same exclusive stable lock.
        foreach (glob($directory . '/state-*.tmp') ?: [] as $orphan) if (is_file($orphan)) unlink($orphan);
        advertCleanAssets($directory, $state, time()); // Only after durable metadata commit.
        return $result;
    } finally {
        if ($temporary !== null && is_file($temporary)) unlink($temporary);
        flock($lock, LOCK_UN); fclose($lock);
    }
}

function advertAudit(array &$state, string $event, int $now, ?int $actorId = null): void
{
    $state['audit'][] = ['event' => $event, 'at' => $now, 'revision' => $state['configRevision'], 'fence' => $state['fence'], 'actorId' => $actorId];
    $state['audit'] = array_slice($state['audit'], -80);
    $state['version']++;
}
