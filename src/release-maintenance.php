<?php
declare(strict_types=1);

function familyReleaseDirectory(array $config): string
{
    return $config['DATABASE_RELEASE_DIRECTORY'] !== ''
        ? $config['DATABASE_RELEASE_DIRECTORY']
        : dirname($config['SESSION_DIRECTORY']) . '/database-releases';
}

function familyReleasePrivateDirectory(string $path): void
{
    if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
        throw new RuntimeException('Cannot create private release directory.');
    }
    if (is_link($path)) throw new RuntimeException('Release directory must not be a symbolic link.');
    if (PHP_OS_FAMILY !== 'Windows' && !chmod($path, 0700)) throw new RuntimeException('Cannot secure private release directory.');
}

// Hold this lease until the response finishes, including session/analytics writes.
// The CLI takes the exclusive lease and drains requests before taking a backup.
function acquireFamilyRequestLease(array $config)
{
    $root = familyReleaseDirectory($config);
    familyReleasePrivateDirectory($root);
    if (is_link($root . '/requests.lock')) throw new RuntimeException('Maintenance lease must not be a symbolic link.');
    $lease = fopen($root . '/requests.lock', 'c+');
    if ($lease === false) throw new RuntimeException('Cannot open maintenance lease.');
    @chmod($root . '/requests.lock', 0600);
    if (!flock($lease, LOCK_SH | LOCK_NB) || file_exists($root . '/maintenance.json')) {
        fclose($lease);
        throw new HttpError(503, 'Family maintenance is in progress. Please try again shortly.', 'maintenance');
    }
    register_shutdown_function(static function () use ($lease): void { if (is_resource($lease)) fclose($lease); });
    return $lease;
}
