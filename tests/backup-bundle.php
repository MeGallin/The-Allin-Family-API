<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bin/backups/bundle.php';
$root = dirname(__DIR__) . '/.local/backup-bundle-test-' . bin2hex(random_bytes(8));
familyReleasePrivateDirectory($root);
$checks = 0;
function backupCheck(bool $condition, string $label): void {
    global $checks;
    releaseAssert($condition, $label); $checks++; echo "PASS: $label\n";
}
function backupReject(callable $action, string $label): void {
    $failed = false;
    try { $action(); } catch (Throwable $error) { $failed = true; }
    backupCheck($failed, $label);
}
try {
    familyReleasePrivateDirectory($root . '/snapshot/photos');
    familyReleasePrivateDirectory($root . '/exports');
    writePrivateFile($root . '/snapshot/database.sql', "-- synthetic fixture only\nCREATE TABLE sample (id INT PRIMARY KEY);\n");
    writePrivateFile($root . '/snapshot/photos/photo.bin', "synthetic photo\0binary bytes");
    $manifest = ['format' => 'allin-family-backup-v1', 'capturedAt' => gmdate('c'),
        'databaseSha256' => hash_file('sha256', $root . '/snapshot/database.sql'),
        'photos' => ['photo.bin' => hash_file('sha256', $root . '/snapshot/photos/photo.bin')]];
    releaseWrite($root . '/snapshot/manifest.json', $manifest);
    $options = ['private_key_bits' => 3072, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
    if (is_file('C:/xampp/apache/conf/openssl.cnf')) $options['config'] = 'C:/xampp/apache/conf/openssl.cnf';
    $pair = openssl_pkey_new($options);
    releaseAssert($pair !== false, 'Unable to create disposable test key.');
    openssl_pkey_export($pair, $privatePem, 'test-only-passphrase', $options);
    $publicPem = openssl_pkey_get_details($pair)['key'];
    $archive = $root . '/exports/test.zip';
    $result = backupSeal($root . '/snapshot', $archive, $publicPem);
    backupCheck($result['bytes'] > 0 && $result['sha256'] === hash_file('sha256', $archive), 'Encrypted bundle checksum recorded');
    $zip = new ZipArchive(); $zip->open($archive);
    backupCheck(@$zip->getFromName('database.sql') === false, 'Database cannot be read without the archive key');
    backupCheck(@$zip->getFromName('manifest.json') === false, 'Manifest content is encrypted too');
    $zip->close();
    $restored = backupOpen($archive, $archive . '.key.json', $privatePem, $root . '/restored', 'test-only-passphrase');
    backupCheck($restored === $manifest, 'Manifest round trip verified');
    backupCheck(hash_file('sha256', $root . '/restored/database.sql') === $manifest['databaseSha256'], 'Recovered database dump is byte-identical');
    backupCheck(hash_file('sha256', $root . '/restored/photos/photo.bin') === $manifest['photos']['photo.bin'], 'Recovered binary photo is byte-identical');
    backupReject(fn() => backupOpen($archive, $archive . '.key.json', $privatePem, $root . '/restored', 'test-only-passphrase'), 'Existing restore folder cannot be overwritten');
    backupReject(fn() => backupOpen($archive, $archive . '.key.json', $privatePem, $root . '/bad-key', 'wrong'), 'Wrong recovery passphrase rejected');
    backupReject(fn() => backupSeal($root . '/snapshot', $archive, $publicPem), 'Existing backup cannot be overwritten');
    backupReject(fn() => backupSeal($root . '/snapshot', $root . '/snapshot/inside.zip', $publicPem), 'Archive cannot be written inside snapshot source');
    backupReject(fn() => backupSeal($root . '/snapshot', $root . '/exports/invalid.zip', 'invalid'), 'Invalid recipient rejected');
    foreach (['../outside', '/absolute', 'photos/../../outside', 'C:/outside', 'photos\\outside', 'photos//outside'] as $path) {
        backupReject(fn() => backupRelative($path), 'Unsafe archive path rejected: ' . $path);
    }
    $tampered = $root . '/exports/tampered.zip';
    copy($archive, $tampered); file_put_contents($tampered, 'changed', FILE_APPEND);
    backupReject(fn() => backupOpen($tampered, $archive . '.key.json', $privatePem, $root . '/tampered-restore', 'test-only-passphrase'), 'Tampered archive rejected before extraction');
    backupCheck(!is_dir($root . '/tampered-restore'), 'Integrity failure creates no restore directory');
    file_put_contents($root . '/snapshot/database.sql', 'changed');
    backupReject(fn() => backupSeal($root . '/snapshot', $root . '/exports/changed.zip', $publicPem), 'Changed snapshot rejected before publication');
    backupCheck(!is_file($root . '/exports/changed.zip') && !is_file($root . '/exports/changed.zip.key.json'), 'Failed publication leaves no accepted backup pair');
    echo "Backup bundle: $checks checks passed. No application database was opened.\n";
} finally {
    // Disposable test root is fixed beneath API .local, with a random suffix.
    $resolved = releaseReal($root);
    releaseAssert(releaseWithin($resolved, releaseReal(dirname(__DIR__) . '/.local')) && (bool)preg_match('/backup-bundle-test-[a-f0-9]{16}$/D', $resolved), 'Unsafe test cleanup root.');
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) { releaseAssert(!$item->isLink(), 'Unexpected link during test cleanup.'); if ($item->isDir()) rmdir($item->getPathname()); else unlink($item->getPathname()); }
    rmdir($resolved);
}
