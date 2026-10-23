import { execFileSync } from 'node:child_process';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
for (const file of ['tests/backup-bundle.php', 'tests/routine-backup.php']) {
  execFileSync(process.env.PHP_BINARY || 'C:/xampp/php/php.exe', [file], {
    cwd: root, stdio: 'inherit', windowsHide: true,
  });
}
execFileSync(process.env.PHP_BINARY || 'C:/xampp/php/php.exe', ['tests/routine-backup.php'], {
  cwd: root, stdio: 'inherit', windowsHide: true,
  env: { ...process.env, ALLIN_BACKUP_DUMP_METHOD: 'pdo' },
});
