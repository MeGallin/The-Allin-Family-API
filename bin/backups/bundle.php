<?php
declare(strict_types=1);
// Private CLI-only helpers. This file is never part of an application upload.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/release/common.php';

function backupRelative(string $path): string
{
    releaseAssert($path !== '' && strlen($path) < 512 && (bool)preg_match('~^[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+)*$~D', $path), 'Unsafe backup entry name.');
    foreach (explode('/', $path) as $part) releaseAssert($part !== '.' && $part !== '..', 'Unsafe backup entry name.');
    return $path;
}

function backupPublicKey(string $pem): array
{
    $key = openssl_pkey_get_public($pem);
    releaseAssert($key !== false, 'Invalid backup recipient key.');
    $details = openssl_pkey_get_details($key);
    releaseAssert($details !== false && $details['type'] === OPENSSL_KEYTYPE_RSA && $details['bits'] >= 3072, 'Backup recipient must use RSA with at least 3072 bits.');
    return [$key, hash('sha256', $details['key'])];
}

/** Encrypt every ZIP entry; only a public recipient key is needed on hosting. */
function backupSeal(string $source, string $destination, string $publicPem): array
{
    releaseAssert(class_exists(ZipArchive::class), 'PHP ZIP support is required.');
    [$recipient, $fingerprint] = backupPublicKey($publicPem);
    $source = releaseReal($source);
    $parent = releaseReal(dirname($destination));
    releaseAssert((bool)preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*\.zip$/D', basename($destination)), 'Invalid backup filename.');
    $destination = $parent . '/' . basename($destination);
    releaseAssert(!releaseWithin($destination, $source) && !releaseWithin($source, $parent), 'Backup output must be separate from snapshot staging.');
    releaseAssert(!file_exists($destination) && !file_exists($destination . '.key.json'), 'Backup output already exists.');
    $manifest = releaseJson($source . '/manifest.json');
    releaseAssert(($manifest['format'] ?? '') === 'allin-family-backup-v1', 'Invalid snapshot manifest.');
    $entries = ['manifest.json' => hash_file('sha256', $source . '/manifest.json'), 'database.sql' => $manifest['databaseSha256'] ?? ''];
    foreach ($manifest['photos'] ?? [] as $path => $hash) $entries['photos/' . backupRelative($path)] = $hash;
    $password = bin2hex(random_bytes(32));
    $temporary = $destination . '.' . bin2hex(random_bytes(8)) . '.partial';
    $zip = new ZipArchive(); $opened = false; $published = false;
    try {
        releaseAssert($zip->open($temporary, ZipArchive::CREATE | ZipArchive::EXCL) === true, 'Cannot create encrypted backup.');
        $opened = true;
        foreach ($entries as $path => $hash) {
            backupRelative($path);
            $file = releaseReal($source . '/' . $path);
            releaseAssert(releaseWithin($file, $source) && is_file($file) && hash_file('sha256', $file) === $hash, 'Snapshot file verification failed.');
            releaseAssert($zip->addFile($file, $path), 'Cannot add snapshot file.');
            releaseAssert($zip->setEncryptionName($path, ZipArchive::EM_AES_256, $password), 'AES-256 ZIP encryption is unavailable.');
        }
        releaseAssert($zip->close(), 'Cannot finish encrypted backup.'); $opened = false;
        releaseAssert(chmod($temporary, 0600), 'Cannot protect encrypted backup file.');
        $sha256 = hash_file('sha256', $temporary);
        // OAEP wraps the random archive password and its digest. The recovery key
        // stays off the hosting server and is never included in the ZIP.
        $secret = json_encode(['password' => $password, 'sha256' => $sha256], JSON_THROW_ON_ERROR);
        releaseAssert(openssl_public_encrypt($secret, $sealed, $recipient, OPENSSL_PKCS1_OAEP_PADDING), 'Cannot seal archive key.');
        releaseWrite($destination . '.key.json', ['format' => 'allin-family-key-v1', 'recipient' => $fingerprint, 'sealed' => base64_encode($sealed)]);
        releaseAssert(rename($temporary, $destination), 'Cannot publish encrypted backup.');
        $published = true;
        return ['sha256' => $sha256, 'bytes' => filesize($destination), 'recipient' => $fingerprint];
    } finally {
        if ($opened) $zip->close();
        if (is_file($temporary)) unlink($temporary);
        if (!$published && is_file($destination . '.key.json')) unlink($destination . '.key.json');
        $password = ''; $secret = '';
    }
}

/** Recover into a NEW isolated directory, never an existing site/photo folder. */
function backupOpen(string $archive, string $keyFile, string $privatePem, string $destination, string $passphrase = ''): array
{
    $archive = releaseReal($archive); $keyFile = releaseReal($keyFile);
    $parent = releaseReal(dirname($destination));
    releaseAssert((bool)preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/D', basename($destination)), 'Invalid restore folder name.');
    $destination = $parent . '/' . basename($destination);
    releaseAssert(!file_exists($destination) && !is_link($destination), 'Restore destination must not exist.');
    releaseAssert(!releaseWithin($archive, $destination), 'Restore must be separate from the backup.');
    $private = openssl_pkey_get_private($privatePem, $passphrase);
    releaseAssert($private !== false, 'Cannot unlock recovery key.');
    $details = openssl_pkey_get_details($private);
    $envelope = releaseJson($keyFile);
    releaseAssert(($envelope['format'] ?? '') === 'allin-family-key-v1' && ($envelope['recipient'] ?? '') === hash('sha256', $details['key']), 'Backup recipient does not match recovery key.');
    $sealed = base64_decode($envelope['sealed'] ?? '', true);
    releaseAssert($sealed !== false && openssl_private_decrypt($sealed, $secret, $private, OPENSSL_PKCS1_OAEP_PADDING), 'Cannot decrypt archive key.');
    $secret = json_decode($secret, true, 16, JSON_THROW_ON_ERROR);
    releaseAssert(isset($secret['sha256'], $secret['password']) && hash_equals($secret['sha256'], hash_file('sha256', $archive)), 'Backup archive integrity check failed.');
    $zip = new ZipArchive();
    releaseAssert($zip->open($archive) === true, 'Cannot open backup archive.');
    $created = [];
    try {
        $zip->setPassword($secret['password']);
        $manifestData = $zip->getFromName('manifest.json');
        releaseAssert(is_string($manifestData), 'Cannot decrypt snapshot manifest.');
        $manifest = json_decode($manifestData, true, 512, JSON_THROW_ON_ERROR);
        releaseAssert(($manifest['format'] ?? '') === 'allin-family-backup-v1', 'Invalid snapshot manifest.');
        $entries = ['manifest.json' => hash('sha256', $manifestData), 'database.sql' => $manifest['databaseSha256'] ?? ''];
        foreach ($manifest['photos'] ?? [] as $path => $hash) $entries['photos/' . backupRelative($path)] = $hash;
        releaseAssert($zip->numFiles === count($entries), 'Unexpected or duplicate archive entries.');
        $seen = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $path = backupRelative($stat['name']);
            releaseAssert(isset($entries[$path]) && !isset($seen[$path]), 'Unexpected or duplicate archive entries.');
            releaseAssert(($stat['encryption_method'] ?? -1) === ZipArchive::EM_AES_256, 'Backup contains an unencrypted entry.');
            releaseAssert($zip->getExternalAttributesIndex($i, $platform, $attributes), 'Cannot inspect archive entry type.');
            $type = ($attributes >> 16) & 0170000;
            releaseAssert($platform !== ZipArchive::OPSYS_UNIX || $type === 0 || $type === 0100000, 'Backup contains a non-regular entry.');
            $seen[$path] = true;
        }
        familyReleasePrivateDirectory($destination);
        // Names, types and exact manifest membership have all been validated.
        // libzip extracts encrypted entries without buffering a whole SQL dump
        // in PHP memory (older PHP cannot stream encrypted getStream entries).
        foreach (array_keys($entries) as $path) $created[] = $destination . '/' . $path;
        releaseAssert($zip->extractTo($destination, array_keys($entries)), 'Cannot decrypt snapshot entries.');
        foreach ($entries as $path => $hash) {
            $target = $destination . '/' . $path;
            releaseAssert(is_file($target) && !is_link($target), 'Missing or unsafe recovered file.');
            // Secure any nested photo folders created by libzip.
            for ($folder = dirname($target); releaseWithin($folder, $destination); $folder = dirname($folder)) {
                familyReleasePrivateDirectory($folder);
                if ($folder === $destination) break;
            }
            releaseAssert(chmod($target, 0600), 'Cannot protect recovered snapshot.');
            releaseAssert(hash_equals($hash, hash_file('sha256', $target)), 'Recovered snapshot hash mismatch.');
        }
        return $manifest;
    } catch (Throwable $error) {
        // Remove only files created by this invocation; never recurse or touch
        // another restore, the source archive or an application directory.
        foreach ($created as $file) if (is_file($file)) unlink($file);
        throw $error;
    } finally { $zip->close(); $secret = []; }
}
