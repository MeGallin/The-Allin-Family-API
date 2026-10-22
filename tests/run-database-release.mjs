import { execFileSync } from 'node:child_process';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
execFileSync(process.env.PHP_BINARY || 'C:/xampp/php/php.exe', ['tests/database-release.php'], {
  cwd: root, stdio: 'inherit', windowsHide: true,
});
