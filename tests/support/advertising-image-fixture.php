<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__, 2) . '/src/bootstrap.php'; require dirname(__DIR__, 2) . '/src/advertising.php';
$directory = $argv[1] ?? '';
if (!preg_match('~^' . preg_quote(str_replace('\\', '/', sys_get_temp_dir()), '~') . '/allin-advert-auth-[a-zA-Z0-9]+$~D', str_replace('\\', '/', $directory))) throw new RuntimeException('Disposable image auth fixture only.');
$environment = ['APP_ENV' => 'test', 'ADVERTISING_DIRECTORY' => $directory];
$ids = advertTransaction($environment, static function (array &$s) use ($directory): array {
    if ($s['current'] !== null || $s['candidate'] !== null || $s['lease'] !== null) throw new RuntimeException('Empty disposable state required.');
    $canvas = imagecreatetruecolor(1024, 1024); ob_start(); imagejpeg($canvas, null, 75); $jpeg = ob_get_clean(); imagedestroy($canvas);
    if (!is_dir($directory . '/assets')) mkdir($directory . '/assets', 0700);
    $feature = array_values(array_filter($s['config']['features'], static fn(array $f): bool => $f['id'] === 'shopping'))[0];
    $payload = ['featureId' => 'shopping', 'ctaId' => 'shopping', 'format' => 'headline', 'headline' => 'Synthetic image auth fixture', 'message' => $feature['facts'][0]['text'], 'scenario' => '', 'items' => [], 'factIds' => ['fact-1']];
    $ids = [];
    foreach (['current', 'candidate'] as $slot) {
        $id = bin2hex(random_bytes(24)); file_put_contents($directory . '/assets/' . $id . '.jpg', $jpeg);
        $s[$slot] = ['id' => bin2hex(random_bytes(16)), 'payload' => $payload, 'featureHash' => advertFeatureHash($feature), 'configRevision' => $s['configRevision'], 'preparedAt' => time(), 'promotedAt' => $slot === 'current' ? time() : null, 'approved' => $slot === 'current',
            'image' => ['id' => $id, 'width' => 1024, 'height' => 1024, 'bytes' => strlen($jpeg), 'mime' => 'image/jpeg']];
        $ids[$slot] = $id;
    }
    $s['config']['enabled'] = true; $s['config']['paused'] = true; $s['version']++;
    return $ids;
});
echo json_encode($ids, JSON_THROW_ON_ERROR), PHP_EOL;
