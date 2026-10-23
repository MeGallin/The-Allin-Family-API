<?php
declare(strict_types=1);

/** Only fixed public providers are fetched; no user-supplied URL or personal event data. */
function publicFeedJson(string $url): array
{
    if (!function_exists('curl_init')) throw new RuntimeException('Public feed transport unavailable.');
    $curl = curl_init($url);
    $body = '';
    curl_setopt_array($curl, [
        CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 4,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_USERAGENT => 'AllinFamily/1.0 (private family dashboard)',
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
            if (strlen($body) + strlen($chunk) > 1048576) return 0;
            $body .= $chunk;
            return strlen($chunk);
        },
    ]);
    try {
        if (curl_exec($curl) === false || curl_getinfo($curl, CURLINFO_RESPONSE_CODE) !== 200) {
            throw new RuntimeException('Public information provider unavailable.');
        }
        $value = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($value)) throw new RuntimeException('Invalid public information response.');
        return $value;
    } finally { curl_close($curl); }
}

/** Bounded stale fallback, non-blocking refresh lock and five-minute failure backoff. */
function cachedPublicFeed(string $directory, string $key, int $ttl, int $maxAge, callable $load): array
{
    $unavailable = ['status' => 'unavailable', 'updatedAt' => null, 'data' => null];
    if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) return $unavailable;
    // Canonicalise configured Windows paths (C://...) before LOCK_EX writes.
    // PHP can otherwise mistake them for stream URLs and silently skip caching.
    $directory = realpath($directory);
    if ($directory === false) return $unavailable;
    $path = $directory . '/' . hash('sha256', $key) . '.json';
    $read = static function () use ($path): array {
        $raw = @file_get_contents($path, false, null, 0, 1048577);
        if (!is_string($raw) || strlen($raw) > 1048576) return [];
        $value = json_decode($raw, true);
        return is_array($value) ? $value : [];
    };
    $result = static function (array $cached) use ($ttl, $maxAge, $unavailable): array {
        $at = $cached['fetchedAt'] ?? 0;
        $age = time() - (is_int($at) ? $at : 0);
        if ($age < 0 || $age > $maxAge || !isset($cached['data']) || !is_array($cached['data'])) return $unavailable;
        return ['status' => $age < $ttl ? 'ready' : 'stale', 'updatedAt' => gmdate('c', $at), 'data' => $cached['data']];
    };
    $cached = $read();
    $snapshot = $result($cached);
    if ($snapshot['status'] === 'ready' || ($cached['retryAfter'] ?? 0) > time()) return $snapshot;
    $lock = @fopen($path . '.lock', 'c');
    if (!$lock) return $snapshot;
    try {
        if (!flock($lock, LOCK_EX | LOCK_NB)) return $snapshot;
        $cached = $read();
        $snapshot = $result($cached);
        if ($snapshot['status'] === 'ready' || ($cached['retryAfter'] ?? 0) > time()) return $snapshot;
        try {
            $cached = ['fetchedAt' => time(), 'data' => $load()];
        } catch (Throwable $error) {
            // No upstream body, coordinates, credentials or family information in logs.
            error_log('Public feed refresh unavailable.');
            $cached['retryAfter'] = time() + 300;
        }
        $temporary = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        if (@file_put_contents($temporary, json_encode($cached, JSON_THROW_ON_ERROR), LOCK_EX) !== false) {
            @chmod($temporary, 0600);
            if (!@rename($temporary, $path)) @unlink($temporary);
        }
        return $result($cached);
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}

