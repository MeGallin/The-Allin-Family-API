<?php
declare(strict_types=1);

function hashFamilyPassword(string $password): string
{
    if (!defined('PASSWORD_ARGON2ID')) throw new RuntimeException('PHP must support Argon2id.');
    return password_hash($password, PASSWORD_ARGON2ID, [
        'memory_cost' => 19456, 'time_cost' => 2, 'threads' => 1,
    ]);
}

function validatePassword(string $password): void
{
    $length = preg_match_all('/./us', $password);
    if ($length === false || $length < 15 || $length > 128 || strlen($password) > 512) {
        throw new HttpError(422, 'Use a password or passphrase of 15 to 128 characters.');
    }
}

function startFamilySession(array $config): void
{
    if (strlen($config['APP_KEY']) < 32) throw new RuntimeException('Configure APP_KEY before using accounts.');
    $secure = $config['SESSION_COOKIE_SECURE'] === '1';
    if (!$secure && (!in_array($config['APP_ENV'], ['local', 'test'], true) ||
        !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true))) {
        throw new RuntimeException('Insecure cookies are permitted only for loopback development.');
    }
    $directory = $config['SESSION_DIRECTORY'];
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create session storage.');
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.gc_maxlifetime', $config['SESSION_LIFETIME_SECONDS']);
    session_save_path($directory);
    session_name('allin_family_session');
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/', 'secure' => $secure,
        'httponly' => true, 'samesite' => 'Lax',
    ]);
    if (!session_start()) throw new RuntimeException('Unable to start a session.');
    $now = time();
    if (isset($_SESSION['user_id']) && (
        $now - (int) ($_SESSION['last_seen'] ?? 0) >= (int) $config['SESSION_IDLE_SECONDS'] ||
        $now - (int) ($_SESSION['started_at'] ?? 0) >= (int) $config['SESSION_LIFETIME_SECONDS']
    )) clearFamilySession();
    if (isset($_SESSION['user_id'])) $_SESSION['last_seen'] = $now;
    if (!isset($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

function clearFamilySession(): void
{
    $_SESSION = [];
    session_regenerate_id(true);
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

function requireCsrf(array $config): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '' && $origin !== rtrim($config['APP_ORIGIN'], '/')) {
        throw new HttpError(403, 'The request origin is not permitted.');
    }
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if ($token === '' || !hash_equals($_SESSION['csrf'], $token)) {
        throw new HttpError(403, 'Refresh the page and try again.', 'csrf_failed');
    }
}

function currentFamilyUser(PDO $db): ?array
{
    if (!isset($_SESSION['user_id'])) return null;
    $statement = $db->prepare('SELECT * FROM users WHERE id = ?');
    $statement->execute([$_SESSION['user_id']]);
    $user = $statement->fetch();
    if (!$user || !(bool) $user['is_active'] ||
        (int) $user['auth_version'] !== (int) ($_SESSION['auth_version'] ?? 0)) {
        clearFamilySession();
        return null;
    }
    return $user;
}

function publicFamilyUser(array $user): array
{
    return [
        'id' => (int) $user['id'], 'username' => $user['username'],
        'displayName' => $user['display_name'], 'role' => $user['role'],
        'mustChangePassword' => (bool) $user['must_change_password'],
    ];
}

function sessionResponse(?array $user): array
{
    return ['user' => $user ? publicFamilyUser($user) : null, 'csrfToken' => $_SESSION['csrf']];
}

function requireFamilyUser(PDO $db, bool $allowTemporaryPassword = false): array
{
    $user = currentFamilyUser($db);
    if (!$user) throw new HttpError(401, 'Please sign in.', 'unauthenticated');
    if (!$allowTemporaryPassword && (bool) $user['must_change_password']) {
        throw new HttpError(403, 'Choose your own password to continue.', 'password_change_required');
    }
    return $user;
}

function limitAuthAttempts(PDO $db, array $config, string $action, string $username): void
{
    $now = time();
    $window = intdiv($now, 900);
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $buckets = [
        [$action . '|ip|' . $ip, 50], [$action . '|user|' . $username, 15],
        [$action . '|account|' . $username . '|' . $ip, 5],
    ];
    $db->prepare('DELETE FROM auth_rate_limits WHERE expires_at < ?')->execute([$now]);
    foreach ($buckets as [$key, $limit]) {
        $hash = hash_hmac('sha256', $key . '|' . $window, $config['APP_KEY']);
        $db->prepare('INSERT INTO auth_rate_limits (bucket_hash, expires_at) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE attempt_count = attempt_count + 1')->execute([$hash, ($window + 1) * 900]);
        $statement = $db->prepare('SELECT attempt_count FROM auth_rate_limits WHERE bucket_hash = ?');
        $statement->execute([$hash]);
        if ((int) $statement->fetchColumn() > $limit) {
            header('Retry-After: ' . (($window + 1) * 900 - $now));
            throw new HttpError(429, 'Too many attempts. Please try again later.', 'rate_limited');
        }
    }
}

function clearSuccessfulAttempts(PDO $db, array $config, string $action, string $username): void
{
    $window = intdiv(time(), 900);
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    foreach ([$action . '|user|' . $username, $action . '|account|' . $username . '|' . $ip] as $key) {
        $hash = hash_hmac('sha256', $key . '|' . $window, $config['APP_KEY']);
        $db->prepare('DELETE FROM auth_rate_limits WHERE bucket_hash = ?')->execute([$hash]);
    }
}

function establishFamilySession(array $user): void
{
    session_regenerate_id(true);
    $_SESSION = [
        'user_id' => (int) $user['id'], 'auth_version' => (int) $user['auth_version'],
        'started_at' => time(), 'last_seen' => time(), 'csrf' => bin2hex(random_bytes(32)),
    ];
}

function loginFamilyUser(PDO $db, array $config, array $body): array
{
    $username = strtolower(trim(is_string($body['username'] ?? null) ? $body['username'] : ''));
    $password = is_string($body['password'] ?? null) ? $body['password'] : '';
    if (strlen($username) > 64 || strlen($password) > 512) {
        throw new HttpError(401, 'The username or password is incorrect.');
    }
    limitAuthAttempts($db, $config, 'login', $username);
    $user = false;
    if (preg_match('/^[a-z0-9][a-z0-9._-]{2,63}$/', $username)) {
        $statement = $db->prepare('SELECT * FROM users WHERE username = ?');
        $statement->execute([$username]);
        $user = $statement->fetch();
    }
    // Keep password work present for unknown accounts too.
    $dummyHash = hashFamilyPassword('This account does not exist.');
    $valid = password_verify($password, $user ? $user['password_hash'] : $dummyHash);
    if (!$user || !$valid || !(bool) $user['is_active']) {
        throw new HttpError(401, 'The username or password is incorrect.');
    }
    clearSuccessfulAttempts($db, $config, 'login', $username);
    establishFamilySession($user);
    return sessionResponse($user);
}

function changeFamilyPassword(PDO $db, array $config, array $body): array
{
    $user = requireFamilyUser($db, true);
    limitAuthAttempts($db, $config, 'password', $user['username']);
    $current = is_string($body['currentPassword'] ?? null) ? $body['currentPassword'] : '';
    $password = is_string($body['newPassword'] ?? null) ? $body['newPassword'] : '';
    if (strlen($current) > 512 || !password_verify($current, $user['password_hash'])) {
        throw new HttpError(401, 'Your current password is incorrect.', 'password_incorrect');
    }
    validatePassword($password);
    if ($current === $password) throw new HttpError(422, 'Choose a different password.');
    $statement = $db->prepare('UPDATE users SET password_hash = ?, must_change_password = 0,
        auth_version = auth_version + 1 WHERE id = ? AND auth_version = ? AND is_active = 1');
    $statement->execute([hashFamilyPassword($password), $user['id'], $user['auth_version']]);
    if ($statement->rowCount() !== 1) {
        clearFamilySession();
        throw new HttpError(401, 'Please sign in again.', 'unauthenticated');
    }
    $user['auth_version']++;
    $user['must_change_password'] = 0;
    clearSuccessfulAttempts($db, $config, 'password', $user['username']);
    establishFamilySession($user);
    return sessionResponse($user);
}
