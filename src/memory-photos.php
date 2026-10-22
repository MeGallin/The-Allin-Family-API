<?php
declare(strict_types=1);

const MEMORY_PHOTO_WIDTHS = [160, 480, 960, 1600];

function memoryPhotoPath(array $config, string $key): string
{
    if (!preg_match('/^[a-f0-9]{64}$/D', $key)) throw new RuntimeException('Invalid stored photo key.');
    if (trim($config['MEMORY_PHOTO_DIRECTORY']) === '') throw new RuntimeException('Configure private photo storage.');
    return rtrim($config['MEMORY_PHOTO_DIRECTORY'], '/\\') . '/' . $key;
}

function memoryPhotoInfo(string $path, string $mime): ?array
{
    $info = @getimagesize($path);
    if (!$info) return null;
    $orientation = 1;
    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $exif = @exif_read_data($path);
        $orientation = (int) ($exif['Orientation'] ?? 1);
    }
    $swap = in_array($orientation, [5,6,7,8], true);
    return ['width' => $swap ? $info[1] : $info[0], 'height' => $swap ? $info[0] : $info[1],
        'orientation' => $orientation, 'pixels' => $info[0] * $info[1]];
}

function memoryPhotoFitsMemory(array $info, int $width): bool
{
    $limit = trim((string)ini_get('memory_limit'));
    if ($limit === '-1') return true;
    $unit = strtolower(substr($limit, -1));
    $bytes = (int)$limit * (['k'=>1024, 'm'=>1048576, 'g'=>1073741824][$unit] ?? 1);
    $scale = min(1, $width / $info['width']);
    $needed = $info['pixels'] * 12 + $info['pixels'] * $scale * $scale * 8 + 8 * 1048576;
    return memory_get_usage(true) + $needed < $bytes;
}

/** Bounded, lazy disk derivatives; raw uploads and embedded metadata stay untouched. */
function memoryPhotoPreview(array $config, string $key, string $mime, int $width): array
{
    $original = memoryPhotoPath($config, $key);
    $fallback = [$original, $mime];
    if (!function_exists('imagecreatetruecolor')) return $fallback;
    $webp = function_exists('imagewebp');
    $extension = $webp ? 'webp' : ($mime === 'image/png' ? 'png' : 'jpg');
    $resultMime = $webp ? 'image/webp' : ($extension === 'png' ? 'image/png' : 'image/jpeg');
    $cache = $original . '.preview-v1-w' . $width . '.' . $extension;
    if (is_file($cache) && filesize($cache) > 0) return filesize($cache) < filesize($original) ? [$cache, $resultMime] : $fallback;
    $lock = @fopen($original . '.preview.lock', 'c');
    if (!$lock) return $fallback;
    @chmod($original . '.preview.lock', 0600);
    $source = null; $output = null; $temporary = null;
    try {
        if (!flock($lock, LOCK_EX)) return $fallback;
        if (is_file($cache) && filesize($cache) > 0) return filesize($cache) < filesize($original) ? [$cache, $resultMime] : $fallback;
        $info = memoryPhotoInfo($original, $mime);
        if (!$info || !memoryPhotoFitsMemory($info, $width)) return $fallback;
        $decoder = ['image/jpeg'=>'imagecreatefromjpeg','image/png'=>'imagecreatefrompng','image/webp'=>'imagecreatefromwebp'][$mime] ?? '';
        if (!$decoder || !function_exists($decoder)) return $fallback;
        $source = @$decoder($original);
        if (!$source) return $fallback;
        $angle = [3=>180,5=>-90,6=>-90,7=>-90,8=>90][$info['orientation']] ?? 0;
        if ($angle) {
            $rotated = imagerotate($source, $angle, 0);
            if (!$rotated) return $fallback;
            imagedestroy($source); $source = $rotated;
        }
        if (in_array($info['orientation'], [2,5], true)) imageflip($source, IMG_FLIP_HORIZONTAL);
        if (in_array($info['orientation'], [4,7], true)) imageflip($source, IMG_FLIP_VERTICAL);
        $scale = min(1, $width / imagesx($source));
        $targetWidth = max(1, (int)round(imagesx($source) * $scale));
        $targetHeight = max(1, (int)round(imagesy($source) * $scale));
        $output = imagecreatetruecolor($targetWidth, $targetHeight);
        imagealphablending($output, false); imagesavealpha($output, true);
        imagefill($output, 0, 0, imagecolorallocatealpha($output, 0, 0, 0, 127));
        imagecopyresampled($output, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, imagesx($source), imagesy($source));
        $temporary = $cache . '.' . bin2hex(random_bytes(8)) . '.tmp';
        $written = $webp ? imagewebp($output, $temporary, 80) : ($extension === 'png' ? imagepng($output, $temporary, 6) : imagejpeg($output, $temporary, 82));
        if (!$written || !is_file($temporary) || filesize($temporary) === 0) return $fallback;
        @chmod($temporary, 0600);
        if (!rename($temporary, $cache)) return $fallback;
        return filesize($cache) < filesize($original) ? [$cache, $resultMime] : $fallback;
    } catch (Throwable $error) {
        error_log('Photo preview unavailable; serving its saved original.');
        return $fallback;
    } finally {
        if ($source) imagedestroy($source);
        if ($output) imagedestroy($output);
        if ($temporary && is_file($temporary)) @unlink($temporary);
        flock($lock, LOCK_UN); fclose($lock);
    }
}

function removeMemoryPhoto(array $config, string $key): void
{
    $original = memoryPhotoPath($config, $key);
    $lock = @fopen($original . '.preview.lock', 'c');
    if (!$lock) return; // Retain files if coordination is unavailable; access still requires the current key.
    try {
        if (!flock($lock, LOCK_EX)) return;
        @unlink($original);
        foreach (MEMORY_PHOTO_WIDTHS as $width) foreach (['webp','png','jpg'] as $extension) {
            @unlink($original . '.preview-v1-w' . $width . '.' . $extension);
        }
    } finally {
        flock($lock, LOCK_UN); fclose($lock);
        @unlink($original . '.preview.lock');
    }
}

function serveMemoryPhoto(PDO $db, array $config): void
{
    $id = $_GET['id'] ?? ''; $key = $_GET['v'] ?? ''; $width = $_GET['width'] ?? '';
    if (!is_string($id) || !ctype_digit($id) || strlen($id) > 18 || !is_string($key) || !is_string($width) ||
        ($width !== '' && !in_array($width, array_map('strval', MEMORY_PHOTO_WIDTHS), true))) throw new HttpError(404, 'Photo not found.');
    $query = $db->prepare('SELECT photo_key, photo_mime FROM memories WHERE id = ? AND deleted_at IS NULL');
    $query->execute([$id]); $row = $query->fetch();
    if (!$row || !$row['photo_key'] || !hash_equals($row['photo_key'], $key)) throw new HttpError(404, 'Photo not found.');
    // Authentication and current photo ownership are checked on every request, including 304.
    session_write_close();
    $path = memoryPhotoPath($config, $row['photo_key']);
    if (!is_file($path)) throw new HttpError(404, 'Photo unavailable.');
    [$path, $mime] = $width === '' ? [$path, $row['photo_mime']] : memoryPhotoPreview($config, $key, $row['photo_mime'], (int)$width);
    $stream = @fopen($path, 'rb');
    if ($stream === false) throw new HttpError(404, 'Photo unavailable.');
    $etag = '"' . hash('sha256', $key . ':' . $width . ':' . $mime . ':' . filesize($path) . ':v1') . '"';
    header('Cache-Control: private, no-cache'); header('ETag: ' . $etag);
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; sandbox");
    if (trim($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
        http_response_code(304); fclose($stream); return;
    }
    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline; filename="family-photo"');
    header('Content-Length: ' . filesize($path));
    fpassthru($stream); fclose($stream);
}
