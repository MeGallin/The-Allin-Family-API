<?php
declare(strict_types=1);
require __DIR__ . '/common.php';
require dirname(__DIR__) . '/src/memory-photos.php';
$config = loadConfiguration(dirname(__DIR__) . '/.env');
$db = connectDatabase($config);
$after = 0; $updated = 0; $unavailable = 0;
do {
    $query = $db->prepare('SELECT id,photo_key,photo_mime FROM memories WHERE id>? AND photo_key IS NOT NULL AND (photo_width IS NULL OR photo_height IS NULL) ORDER BY id LIMIT 100');
    $query->execute([$after]); $rows = $query->fetchAll();
    foreach ($rows as $row) {
        $after = (int)$row['id'];
        $info = memoryPhotoInfo(memoryPhotoPath($config, $row['photo_key']), $row['photo_mime']);
        if (!$info) { $unavailable++; continue; }
        $write = $db->prepare('UPDATE memories SET photo_width=?,photo_height=? WHERE id=? AND photo_key=?');
        $write->execute([$info['width'],$info['height'],$row['id'],$row['photo_key']]);
        $updated += $write->rowCount();
    }
} while (count($rows) === 100);
echo 'Photo dimensions added: ' . $updated . '; unavailable originals: ' . $unavailable . PHP_EOL;
