<?php
declare(strict_types=1);
require __DIR__ . '/common.php';
$username = strtolower((string) (getopt('', ['username:'])['username'] ?? ''));
if (!preg_match('/^[a-z0-9][a-z0-9._-]{2,63}$/', $username)) {
    fwrite(STDERR, "Usage: php bin/reset-password.php --username=person\n"); exit(1);
}
$db = connectDatabase(loadConfiguration(dirname(__DIR__) . '/.env'));
$password = bin2hex(random_bytes(12));
$statement = $db->prepare('UPDATE users SET password_hash = ?, must_change_password = 1,
    auth_version = auth_version + 1 WHERE username = ?');
$statement->execute([hashFamilyPassword($password), $username]);
if ($statement->rowCount() !== 1) { fwrite(STDERR, "Account not found.\n"); exit(1); }
writePrivateFile(dirname(__DIR__) . '/.local/account-' . $username . '.txt',
    "Username: $username\nTemporary password: $password\n\nChoose a new password on next login.\n");
echo "Password reset; other sessions revoked. Temporary password saved in .local/account-$username.txt\n";
