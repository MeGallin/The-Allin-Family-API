import assert from 'node:assert/strict';
import test from 'node:test';
import { spawn, execFileSync } from 'node:child_process';
import { once } from 'node:events';
import { mkdtemp, readdir, rm, rmdir, readFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { resolve } from 'node:path';
import { setTimeout as delay } from 'node:timers/promises';
const php = process.env.PHP_BINARY ?? (process.platform === 'win32' ? 'C:/xampp/php/php.exe' : 'php');

test('local foreground worker is singleton, bounded/status-safe and stops cleanly without enabling generation', async (t) => {
  const directory = await mkdtemp(resolve(tmpdir(), 'allin-worker-test-'));
  const environment = { ...process.env, APP_ENV: 'local', ADVERTISING_DIRECTORY: directory, ADVERTISING_NODE_URL: 'http://127.0.0.2:8000' };
  const command = (mode, extra = {}) => execFileSync(php, ['bin/advertising-worker.php', mode], { env: { ...environment, ...extra }, encoding: 'utf8', windowsHide: true });
  const worker = spawn(php, ['bin/advertising-worker.php', 'run'], { env: environment, stdio: ['ignore', 'pipe', 'pipe'], windowsHide: true });
  let output = ''; worker.stdout.on('data', (data) => { output += data; });
  t.after(async () => {
    if (worker.exitCode === null) { const exited = once(worker, 'exit'); worker.kill(); await exited; }
    for (const file of await readdir(directory)) { assert.match(file, /^(state\.(json|lock)|worker\.(json|lock)|worker-stop\.json)$/); await rm(resolve(directory, file)); }
    await rmdir(directory);
  });
  let status;
  for (let i = 0; i < 40; i++) { try { status = JSON.parse(command('status')); } catch {} if (status?.running && status.worker.lastTickAt) break; await delay(50); }
  assert.equal(status.running, true); assert.equal(status.worker.pid, worker.pid); assert.equal(status.worker.lastOutcome, 'disabled');
  assert.equal(Object.hasOwn(status.worker, 'token'), false);
  assert.throws(() => command('run'), /already running/);
  const stop = JSON.parse(command('stop')); assert.equal(stop.stopRequested, true);
  const exited = once(worker, 'exit'); const timer = setTimeout(() => worker.kill(), 8000);
  const [code] = await exited; clearTimeout(timer); assert.equal(code, 0);
  const after = JSON.parse(command('status')); assert.equal(after.running, false); assert.equal(after.worker.lastOutcome, 'stopped');
  const state = JSON.parse(await readFile(resolve(directory, 'state.json'), 'utf8'));
  assert.equal(state.config.enabled, false); assert.deepEqual(state.usage, []); assert.equal(state.lease, null); assert.equal(state.current, null);
  assert.match(output, /worker stopped/);
  assert.throws(() => command('status', { APP_ENV: 'production' }), /failed/);
  assert.throws(() => command('status', { ADVERTISING_NODE_URL: 'https://example.onrender.com' }), /failed/);
});
