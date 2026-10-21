<?php
declare(strict_types=1);
require __DIR__ . '/common.php';
$options = getopt('', ['username:', 'name:', 'role:']);
$username = strtolower(trim((string) ($options['username'] ?? '')));
$name = trim((string) ($options['name'] ?? ''));
$role = (string) ($options['role'] ?? 'member');
if (!preg_match('/^[a-z0-9][a-z0-9._-]{2,63}$/', $username) ||
    $name === '' || strlen($name) > 120 || !in_array($role, ['member', 'admin'], true)) {
    fwrite(STDERR, "Usage: php bin/create-user.php --username=person --name=Person [--role=admin]\n");
    exit(1);
}
$config = loadConfiguration(dirname(__DIR__) . '/.env');
$db = connectDatabase($config);
$existing = $db->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
$existing->execute([$username]);
if ((int) $existing->fetchColumn() !== 0) {
    fwrite(STDERR, "Account already exists. No changes made.\n");
    exit(1);
}
$password = bin2hex(random_bytes(12));
$db->prepare('INSERT INTO users (username, display_name, password_hash, role) VALUES (?, ?, ?, ?)')
    ->execute([$username, $name, hashFamilyPassword($password), $role]);
$file = dirname(__DIR__) . '/.local/account-' . $username . '.txt';
writePrivateFile($file, "Name: $name\nUsername: $username\nTemporary password: $password\n\nSign in at http://127.0.0.1:5173 and choose your own password.\n");
echo "Account created: $username. Temporary password saved locally to .local/account-$username.txt\n";
