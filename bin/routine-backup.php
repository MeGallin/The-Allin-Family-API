<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors', '0');
ini_set('zend.exception_ignore_args', '1');
require __DIR__ . '/backups/snapshot.php';
try {
    $options = getopt('', ['settings:']);
    releaseAssert(isset($options['settings']), 'Supply a private --settings file.');
    $result = backupCapture(releaseJson(releaseReal($options['settings'])));
    echo json_encode($result, JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $error) {
    // Utility logs remain private; SQL, configuration values and exceptions with
    // database query details must never appear in cron email or public output.
    fwrite(STDERR, "BACKUP FAILED. Inspect private backup status and utility logs.\n");
    exit(1);
}
