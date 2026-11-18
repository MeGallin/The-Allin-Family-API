<?php
declare(strict_types=1);

function advertImageBytes(string $encoded, string $expectedSize): array
{
    if (strlen($encoded) > 5592408 || !preg_match('/^[A-Za-z0-9+\/]*={0,2}$/D', $encoded)) throw new HttpError(422, 'Invalid image encoding.');
    $bytes = base64_decode($encoded, true);
    if ($bytes === false || strlen($bytes) < 4 || strlen($bytes) > 4194304 || substr($bytes, 0, 3) !== "\xff\xd8\xff" || substr($bytes, -2) !== "\xff\xd9") throw new HttpError(422, 'The generated image must be a JPEG within4MiB.');
    $info = @getimagesizefromstring($bytes);
    if (!$info || ($info[2] ?? null) !== IMAGETYPE_JPEG || ($info[0] . 'x' . $info[1]) !== $expectedSize) throw new HttpError(422, 'The generated image dimensions are not approved.');
    return [$bytes, $info[0], $info[1]];
}

function advertStoreImage(array $environment, array &$state, array $body, int $now): array
{
    $lease = advertLease($state, $body, $now);
    $grant = $lease['grants'][$body['grantId'] ?? ''] ?? null;
    if (!$state['config']['image']['enabled'] || !$grant || $grant['kind'] !== 'image' || !$grant['begun'] || !$grant['settled'] || isset($lease['image'])) throw new HttpError(409, 'Image upload is not current.');
    if (!is_string($body['data'] ?? null)) throw new HttpError(422, 'An encoded JPEG is required.');
    [$bytes, $width, $height] = advertImageBytes($body['data'], $state['config']['image']['size']);
    $directory = advertDirectory($environment) . '/assets';
    if (!is_dir($directory) && !mkdir($directory, 0700) && !is_dir($directory)) throw new RuntimeException('Image storage unavailable.');
    $id = bin2hex(random_bytes(24)); $temporary = $directory . '/' . $id . '.tmp';
    $file = fopen($temporary, 'xb');
    if (!$file) throw new RuntimeException('Image write failed.');
    try {
        @chmod($temporary, 0600);
        if (fwrite($file, $bytes) !== strlen($bytes) || !fflush($file) || (function_exists('fsync') && !fsync($file))) throw new RuntimeException('Image write failed.');
    } finally { fclose($file); }
    if (!rename($temporary, $directory . '/' . $id . '.jpg')) throw new RuntimeException('Image replacement failed.');
    $image = ['id' => $id, 'width' => $width, 'height' => $height, 'bytes' => strlen($bytes), 'mime' => 'image/jpeg'];
    $state['lease']['image'] = $image;
    return ['image' => $image];
}

function advertCleanAssets(string $directory, array $state, int $now): void
{
    $kept = [];
    foreach (['current', 'candidate', 'lease'] as $slot) if (isset($state[$slot]['image']['id'])) $kept[] = $state[$slot]['image']['id'];
    foreach (glob($directory . '/assets/*') ?: [] as $file) {
        if (!is_file($file) || is_link($file) || !preg_match('/^([a-f0-9]{48})\.(jpg|tmp)$/D', basename($file), $match)) continue;
        if (in_array($match[1], $kept, true)) continue;
        // Old published content is unlinked after its metadata swap. Unowned
        // interrupted staging has a short grace before later transaction sweep.
        $grace = $match[2] === 'tmp' ? 300 : 0;
        if ($now - (int)filemtime($file) >= $grace) @unlink($file);
    }
}

function advertServeImage(array $environment, string $id, bool $admin): void
{
    if (!preg_match('/^[a-f0-9]{48}$/D', $id)) throw new HttpError(404, 'Image unavailable.');
    $bytes = advertTransaction($environment, static function (array &$s) use ($environment, $id, $admin): string {
        advertHousekeeping($s, time());
        $allowed = $s['config']['enabled'] && ($s['current']['image']['id'] ?? null) === $id;
        if ($admin && ($s['candidate']['image']['id'] ?? null) === $id) $allowed = true;
        if (!$allowed) throw new HttpError(404, 'Image unavailable.');
        $file = advertDirectory($environment) . '/assets/' . $id . '.jpg';
        $bytes = @file_get_contents($file, false, null, 0, 4194305);
        if ($bytes === false || strlen($bytes) > 4194304) throw new HttpError(404, 'Image unavailable.');
        return $bytes;
    });
    header('Content-Type: image/jpeg'); header('Content-Length: ' . strlen($bytes));
    header('Cache-Control: private, no-store'); header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; sandbox");
    echo $bytes;
}
