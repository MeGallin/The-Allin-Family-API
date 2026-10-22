<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/common.php';
require_once dirname(__DIR__, 2) . '/src/release-maintenance.php';

function releaseAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
function releaseIdentifier(string $value): string
{
    releaseAssert((bool)preg_match('/^[a-zA-Z0-9_]+$/D', $value), 'Invalid database identifier.');
    return '`' . $value . '`';
}
function releaseJson(string $path): array
{
    return json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
}
function releaseWrite(string $path, array $value): void
{
    $tmp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
    writePrivateFile($tmp, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    if (!rename($tmp, $path)) throw new RuntimeException('Cannot persist release state.');
}
function releaseWithin(string $path, string $root): bool
{
    $path = str_replace('\\', '/', $path);
    $root = rtrim(str_replace('\\', '/', $root), '/');
    return strcasecmp($path, $root) === 0 || strncasecmp($path, $root . '/', strlen($root) + 1) === 0;
}
function releaseReal(string $path): string
{
    $real = realpath($path);
    releaseAssert($real !== false, 'Required path does not exist: ' . $path);
    // Reject links in every existing ancestor, not just the final entry.
    for ($cursor = $path; dirname($cursor) !== $cursor; $cursor = dirname($cursor)) {
        releaseAssert(!is_link($cursor), 'Symbolic links are not allowed in release paths.');
    }
    return $real;
}
function releaseConnect(array $options): array
{
    foreach (['config', 'credentials', 'database', 'site-root', 'migrations'] as $key) {
        releaseAssert(isset($options[$key]) && is_string($options[$key]), 'Missing --' . $key);
    }
    $configPath = releaseReal($options['config']);
    $config = loadConfiguration($configPath);
    releaseAssert($config['DB_NAME'] === $options['database'], 'Explicit database does not match configuration.');
    releaseIdentifier($config['DB_NAME']);
    $site = releaseReal($options['site-root']);
    releaseAssert(releaseWithin($configPath, $site), 'Runtime configuration must belong to the site being backed up.');
    $root = familyReleaseDirectory($config);
    familyReleasePrivateDirectory($root);
    $root = releaseReal($root);
    releaseAssert(!releaseWithin($root, $site) && !releaseWithin($site, $root), 'Release storage must be separate from the web root.');
    $credentialPath = releaseReal($options['credentials']);
    releaseAssert(!releaseWithin($credentialPath, $site), 'Deployment credentials must be outside the web root.');
    $credentials = parse_ini_file($credentialPath, false, INI_SCANNER_RAW);
    releaseAssert(is_array($credentials) && isset($credentials['DB_USER'], $credentials['DB_PASSWORD']), 'Invalid deployment credential file.');
    releaseAssert(count(array_diff(array_keys($credentials), ['DB_USER', 'DB_PASSWORD'])) === 0, 'Credential file must contain only DB_USER and DB_PASSWORD.');
    releaseAssert($credentials['DB_USER'] !== $config['DB_USER'], 'Use separate deployment credentials, not the runtime user.');
    $adminConfig = array_replace($config, $credentials);
    $db = connectDatabase($adminConfig);
    $identity = $db->query('SELECT DATABASE() AS db, @@hostname AS host, @@port AS port')->fetch();
    releaseAssert($identity['db'] === $options['database'], 'Connected to the wrong database.');
    $identity['connectionHost'] = $config['DB_HOST'];
    $identity['connectionPort'] = $config['DB_PORT'];
    $id = hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));
    return compact('configPath', 'config', 'adminConfig', 'site', 'root', 'db', 'identity', 'id');
}
function releaseLocks(array $context): array
{
    $file = fopen($context['root'] . '/workflow.lock', 'c+');
    releaseAssert($file !== false, 'Cannot open database release lock.');
    if (!flock($file, LOCK_EX | LOCK_NB)) { fclose($file); throw new RuntimeException('Another database release is running.'); }
    @chmod($context['root'] . '/workflow.lock', 0600);
    $name = 'allin_release_' . substr(hash('sha256', $context['config']['DB_NAME']), 0, 48);
    $query = $context['db']->prepare('SELECT GET_LOCK(?, 0)');
    $query->execute([$name]);
    if ((int)$query->fetchColumn() !== 1) { fclose($file); throw new RuntimeException('Another migration process holds the database lock.'); }
    return [$file, $name];
}
function releaseUnlock(array $context, array $locks): void
{
    $context['db']->prepare('SELECT RELEASE_LOCK(?)')->execute([$locks[1]]);
    fclose($locks[0]);
}
function releaseMigrationFiles(string $directory): array
{
    $directory = releaseReal($directory);
    $files = glob($directory . '/*.sql');
    sort($files, SORT_STRING);
    $result = [];
    $previous = 0;
    foreach ($files as $path) {
        $name = basename($path);
        releaseAssert((bool)preg_match('/^(\d{3})_[a-z0-9_]+\.sql$/D', $name, $match), 'Invalid migration filename.');
        releaseAssert((int)$match[1] === $previous + 1, 'Migrations must have contiguous, unique numbers starting at 001.');
        $previous++;
        releaseReal($path);
        releaseAssert(is_file($path) && is_readable($path), 'Migration is not a readable SQL file.');
        $hash = hash_file('sha256', $path);
        releaseAssert(is_string($hash), 'Cannot checksum migration.');
        $result[$name] = $hash;
    }
    releaseAssert($result !== [], 'No migration files found.');
    return $result;
}
function releaseLedger(PDO $db): array
{
    return $db->query('SELECT name FROM schema_migrations ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
}
function releaseHistory(array $context, array $files, bool $adopt): array
{
    $ledger = releaseLedger($context['db']);
    releaseAssert($ledger === array_slice(array_keys($files), 0, count($ledger)), 'Database migration history is unknown or out of order.');
    $path = $context['root'] . '/history.json';
    if (!is_file($path)) {
        releaseAssert($adopt, 'Existing history needs one reviewed --adopt-history baseline.');
        releaseWrite($path, ['identity' => $context['identity'], 'checksums' => array_intersect_key($files, array_flip($ledger)), 'adoptedAt' => gmdate('c')]);
    }
    $history = releaseJson($path);
    releaseAssert($history['identity'] === $context['identity'], 'History belongs to a different server/database.');
    releaseAssert(array_keys($history['checksums']) === $ledger, 'Database ledger differs from pinned history; investigate before retrying.');
    foreach ($history['checksums'] as $name => $hash) {
        releaseAssert(($files[$name] ?? null) === $hash, 'Previously applied migration was changed: ' . $name);
    }
    return $ledger;
}
function releaseSpecs(PDO $db): array
{
    $unsupported = $db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND (TABLE_TYPE <> 'BASE TABLE' OR ENGINE <> 'InnoDB')")->fetchColumn();
    releaseAssert((int)$unsupported === 0, 'Only InnoDB base tables are currently supported.');
    foreach (['ROUTINES' => 'ROUTINE_SCHEMA', 'EVENTS' => 'EVENT_SCHEMA', 'TRIGGERS' => 'TRIGGER_SCHEMA'] as $table => $column) {
        releaseAssert((int)$db->query("SELECT COUNT(*) FROM information_schema.$table WHERE $column=DATABASE()")->fetchColumn() === 0, 'Routines, events and triggers require a separately reviewed backup workflow.');
    }
    $specs = [];
    foreach ($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $name) {
        $quoted = releaseIdentifier($name);
        $columns = $db->query('SHOW COLUMNS FROM ' . $quoted)->fetchAll();
        $primary = array_values(array_map(static fn(array $column): string => $column['Field'], array_filter($columns, static fn(array $column): bool => $column['Key'] === 'PRI')));
        releaseAssert($primary !== [], 'Every table needs a primary key for verification.');
        $specs[$name] = ['columns' => array_column($columns, 'Field'), 'primary' => $primary, 'schema' => $db->query('SHOW CREATE TABLE ' . $quoted)->fetch()['Create Table']];
    }
    ksort($specs);
    return $specs;
}
function releaseData(PDO $db, array $specs): array
{
    $result = [];
    foreach ($specs as $name => $spec) {
        $fields = implode(',', array_map('releaseIdentifier', $spec['columns']));
        $order = implode(',', array_map('releaseIdentifier', $spec['primary']));
        $buffered = $db->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
        $db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        try {
            $query = $db->query('SELECT ' . $fields . ' FROM ' . releaseIdentifier($name) . ' ORDER BY ' . $order);
            $hash = hash_init('sha256'); $count = 0;
            while ($row = $query->fetch(PDO::FETCH_NUM)) { hash_update($hash, serialize($row)); $count++; }
            $query->closeCursor();
        } finally { $db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered); }
        $result[$name] = ['rows' => $count, 'sha256' => hash_final($hash)];
    }
    return $result;
}
function releaseCopyTree(string $source, string $destination): array
{
    $source = releaseReal($source);
    familyReleasePrivateDirectory($destination);
    $hashes = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $file) {
        releaseAssert(!$file->isLink(), 'Backup source contains a symbolic link.');
        $relative = substr($file->getPathname(), strlen($source) + 1);
        $target = $destination . '/' . $relative;
        if ($file->isDir()) { familyReleasePrivateDirectory($target); continue; }
        releaseAssert($file->isFile(), 'Unsupported backup source entry.');
        $hash = hash_file('sha256', $file->getPathname());
        releaseAssert(copy($file->getPathname(), $target), 'File backup failed.');
        @chmod($target, 0600);
        releaseAssert(hash_file('sha256', $target) === $hash && hash_file('sha256', $file->getPathname()) === $hash, 'File changed during backup.');
        $hashes[str_replace('\\', '/', $relative)] = $hash;
    }
    return $hashes;
}
function releaseProcess(array $command, string $log, ?string $input = null, int $timeout = 900): void
{
    $pipes = [];
    $process = proc_open($command, [0 => ['file', $input ?? (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'), 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, null, null, ['bypass_shell' => true]);
    releaseAssert(is_resource($process), 'Cannot start database utility.');
    @chmod($log, 0600);
    $deadline = microtime(true) + $timeout;
    do {
        $status = proc_get_status($process);
        if (!$status['running']) break;
        if (microtime(true) >= $deadline) {
            proc_terminate($process); proc_close($process);
            throw new RuntimeException('Database utility timed out; inspect the private utility log.');
        }
        usleep(100000);
    } while (true);
    $closed = proc_close($process);
    $exit = $status['exitcode'] >= 0 ? $status['exitcode'] : $closed;
    releaseAssert($exit === 0, 'Database utility failed; inspect the private utility log.');
}
function releaseDefaults(array $config, string $path): void
{
    $text = "[client]\nprotocol=tcp\n";
    foreach (['host' => 'DB_HOST', 'port' => 'DB_PORT', 'user' => 'DB_USER', 'password' => 'DB_PASSWORD'] as $option => $key) {
        $escaped = str_replace(["\\", '"', "\n", "\r"], ["\\\\", '\\"', '\\n', '\\r'], $config[$key]);
        $text .= $option . '="' . $escaped . '"' . "\n";
    }
    writePrivateFile($path, $text);
}
