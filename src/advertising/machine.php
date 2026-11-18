<?php
declare(strict_types=1);

function advertSignature(string $secret, string $time, string $nonce, string $method, string $path, string $raw): string
{
    return hash_hmac('sha256', implode("\n", [$time, $nonce, $method, $path, hash('sha256', $raw)]), $secret);
}

function advertMachineRequest(array $environment, string $raw, array $headers, ?int $instant = null, string $path = '/advertising/machine'): array
{
    $now = $instant ?? time(); $secret = $environment['ADVERTISING_SIGNING_SECRET'] ?? '';
    $timestamp = $headers['time'] ?? ''; $nonce = $headers['nonce'] ?? ''; $signature = $headers['signature'] ?? '';
    if (!in_array($path, ['/advertising/machine', '/advertising/machine/image'], true)) throw new HttpError(404, 'Unknown machine route.');
    if (strlen($raw) > ($path === '/advertising/machine/image' ? 6291456 : 32768)) throw new HttpError(413, 'The machine request is too large.');
    if (strlen($secret) < 32 || !preg_match('/^\d{10}$/D', $timestamp) || abs($now - (int)$timestamp) > 120
        || !preg_match('/^[a-f0-9]{32}$/D', $nonce) || !preg_match('/^[a-f0-9]{64}$/D', $signature)
        || !hash_equals(advertSignature($secret, $timestamp, $nonce, 'POST', $path, $raw), $signature)) throw new HttpError(401, 'Machine authentication failed.');
    try { $body = json_decode($raw, true, 20, JSON_THROW_ON_ERROR); } catch (JsonException $e) { throw new HttpError(400, 'Invalid machine JSON.'); }
    if (!is_array($body) || advertIsList($body)) throw new HttpError(400, 'Expected a machine object.');
    return advertTransaction($environment, static function (array &$s) use ($body, $nonce, $now, $timestamp, $path, $environment): array {
        $s['nonces'] = array_filter($s['nonces'], static fn(int $expires): bool => $expires >= $now);
        if (isset($s['nonces'][$nonce])) throw new HttpError(409, 'Machine request already used.', 'advert_replay');
        if (count($s['nonces']) >= 512) throw new HttpError(429, 'Machine request capacity reached.');
        // Persist nonce even when domain validation fails: transaction returns an
        // error envelope and throws only after the nonce has been committed.
        $s['nonces'][$nonce] = (int)$timestamp + 120;
        try {
            if ($path === '/advertising/machine/image') {
                if (($body['action'] ?? null) !== 'store-image') throw new HttpError(400, 'An image upload action is required.');
                return ['ok' => advertStoreImage($environment, $s, $body, $now)];
            }
            return ['ok' => advertMachineMutation($s, $body, $now)];
        }
        catch (HttpError $error) { return ['failure' => [$error->status, $error->getMessage(), $error->errorCode]]; }
    });
}

function advertDispatchMachine(array $environment, string $path = '/advertising/machine'): void
{
    $raw = file_get_contents('php://input', false, null, 0, $path === '/advertising/machine/image' ? 6291457 : 32769);
    if ($raw === false) throw new HttpError(400, 'Unable to read machine request.');
    $result = advertMachineRequest($environment, $raw, ['time' => $_SERVER['HTTP_X_AD_TIME'] ?? '', 'nonce' => $_SERVER['HTTP_X_AD_NONCE'] ?? '', 'signature' => $_SERVER['HTTP_X_AD_SIGNATURE'] ?? ''], null, $path);
    if (isset($result['failure'])) throw new HttpError(...$result['failure']);
    jsonResponse($result['ok']);
}

function advertNodeRequest(array $environment, string $path, array $body, int $timeout): ?array
{
    $url = rtrim($environment['ADVERTISING_NODE_URL'] ?? '', '/');
    $parts = parse_url($url);
    $local = in_array($environment['APP_ENV'] ?? '', ['local', 'test'], true);
    $valid = $local && $url === 'http://127.0.0.2:8000';
    if (!$valid) $valid = is_array($parts) && ($parts['scheme'] ?? '') === 'https' && preg_match('/^[a-z0-9-]+\.onrender\.com$/D', $parts['host'] ?? '') && !array_intersect(['port', 'user', 'pass', 'query', 'fragment'], array_keys($parts)) && empty($parts['path']);
    $secret = $environment['ADVERTISING_SIGNING_SECRET'] ?? '';
    if (!$valid || strlen($secret) < 32) throw new HttpError(503, 'The advertising engine connection is not configured.');
    if (!in_array($path, ['/v1/generate', '/v1/status'], true)) throw new RuntimeException('Unsupported engine route.');
    $raw = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $time = (string)time(); $nonce = bin2hex(random_bytes(16));
    $signature = advertSignature($secret, $time, $nonce, 'POST', $path, $raw);
    $context = stream_context_create(['http' => ['method' => 'POST', 'timeout' => $timeout, 'ignore_errors' => true, 'follow_location' => 0,
        'header' => "Content-Type: application/json\r\nX-Ad-Time: $time\r\nX-Ad-Nonce: $nonce\r\nX-Ad-Signature: $signature\r\nConnection: close\r\n", 'content' => $raw]]);
    $response = @file_get_contents($url . $path, false, $context, 0, 65537);
    if ($response === false || strlen($response) > 65536) return null;
    try { $decoded = json_decode($response, true, 24, JSON_THROW_ON_ERROR); } catch (JsonException $e) { return null; }
    return is_array($decoded) ? $decoded : null;
}

function advertRunJob(array $environment, bool $manual = false): array
{
    $job = advertClaimJob($environment, $manual);
    if (!$job) return ['started' => false];
    $result = null;
    try { $result = advertNodeRequest($environment, '/v1/generate', $job, advertJobTimeout($job['config'])); }
    finally { advertFinishJob($environment, $job, $result); }
    return ['started' => true, 'outcome' => $result['status'] ?? 'unknown'];
}
