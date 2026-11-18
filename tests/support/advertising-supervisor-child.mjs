import { createServer } from 'node:http';
import { existsSync, readFileSync, unlinkSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
const role = process.argv[2];
const port = Number(process.argv[3]);
if (role === 'readiness') {
  writeFileSync(resolve('readiness-entered.txt'), 'synthetic barrier');
  setInterval(() => { if (existsSync(resolve('readiness-release.txt'))) process.exit(0); }, 20);
}
else if (role === 'worker') setInterval(() => {}, 1000);
else createServer((req, res) => res.end('synthetic health')).listen(port, '127.0.0.1');
// Raw output is intentionally discarded by the supervisor, never retained in logs.
console.log('synthetic-sensitive-output-must-not-be-logged');
console.error('synthetic-sensitive-error-must-not-be-logged');
setInterval(() => {
  console.log('synthetic-sensitive-periodic-output'); console.error('synthetic-sensitive-periodic-error');
  const trigger = resolve(`${role}-exit.txt`);
  if (existsSync(trigger)) { const code=Number(readFileSync(trigger,'utf8')); unlinkSync(trigger); process.exit(code); }
}, 250);
