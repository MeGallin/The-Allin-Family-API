<?php
declare(strict_types=1);
require __DIR__ . '/common.php';
require dirname(__DIR__) . '/src/memory-photos.php';
if (!function_exists('imagecreatetruecolor')) throw new RuntimeException('Enable the installed GD extension when running this command.');
$config = loadConfiguration(dirname(__DIR__) . '/.env');
$db = connectDatabase($config);
$after = 0; $photos = 0; $originalBytes = 0;
$deliveredBytes = array_fill_keys(MEMORY_PHOTO_WIDTHS, 0);
do {
    $query = $db->prepare('SELECT id,photo_key,photo_mime FROM memories WHERE id>? AND photo_key IS NOT NULL AND deleted_at IS NULL ORDER BY id LIMIT 100');
    $query->execute([$after]); $rows = $query->fetchAll();
    foreach ($rows as $row) {
        $after = (int)$row['id'];
        $original = memoryPhotoPath($config, $row['photo_key']);
        if (!is_file($original)) continue;
        $photos++; $originalBytes += filesize($original);
        foreach (MEMORY_PHOTO_WIDTHS as $width) {
            [$path] = memoryPhotoPreview($config, $row['photo_key'], $row['photo_mime'], $width);
            $deliveredBytes[$width] += filesize($path);
        }
    }
} while (count($rows) === 100);
echo json_encode(['photos'=>$photos,'originalBytes'=>$originalBytes,'previewBytesByWidth'=>$deliveredBytes], JSON_THROW_ON_ERROR) . PHP_EOL;
