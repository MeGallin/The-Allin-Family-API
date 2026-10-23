<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors', '0');
ini_set('zend.exception_ignore_args', '1');
require __DIR__ . '/backups/bundle.php';
try {
    $options = getopt('', ['archive:', 'key-file:', 'private-key:', 'destination:']);
    foreach (['archive', 'key-file', 'private-key', 'destination'] as $field) releaseAssert(isset($options[$field]), 'Missing recovery path.');
    $key = file_get_contents(releaseReal($options['private-key']));
    backupOpen($options['archive'], $options['key-file'], $key, $options['destination']);
    echo "Encrypted SQL and photos recovered and hash-verified in a new private directory.\n";
} catch (Throwable $error) {
    fwrite(STDERR, "RECOVERY FAILED. No existing recovery folder is overwritten.\n");
    exit(1);
}
