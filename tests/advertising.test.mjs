import assert from 'node:assert/strict';
import test from 'node:test';
import { mkdtemp, readdir, rm, rmdir } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { resolve } from 'node:path';
import { startEnvironment, expectStatus } from './support/environment.mjs';
import { execFileSync } from 'node:child_process';

test('advertising HTTP permissions, CSRF, config CAS, private paths, metadata export and CORS', async () => {
  const directory = await mkdtemp(resolve(tmpdir(), 'allin-advert-auth-'));
  const originalDirectory = process.env.ADVERTISING_DIRECTORY;
  const originalSecret = process.env.ADVERTISING_SIGNING_SECRET;
  process.env.ADVERTISING_DIRECTORY = directory;
  process.env.ADVERTISING_SIGNING_SECRET = 'synthetic-auth-test-signing-secret-never-real';
  let env;
  try {
    env = await startEnvironment();
    expectStatus(await env.client().request('/advertising'), 401);
    const member = expectStatus(await env.member.request('/advertising'), 200);
    assert.equal(member.enabled, false); assert.equal(member.current, null);
    expectStatus(await env.member.request('/admin/advertising'), 403);
    expectStatus(await env.member.request('/admin/advertising/action', { action: 'prepare', version: 1 }), 403);
    expectStatus(await env.admin.request('/admin/advertising/action', { action: 'prepare', version: 1 }, { 'X-CSRF-Token': 'wrong' }), 403);
    expectStatus(await env.admin.request('/admin/advertising', undefined, { Origin: 'https://evil.invalid' }), 403);
    expectStatus(await env.admin.request('/advertising', undefined, { Origin: 'https://evil.invalid' }), 403);
    const initial = expectStatus(await env.admin.request('/admin/advertising'), 200);
    assert.equal(initial.provider, 'openai'); assert.equal(initial.config.enabled, false);
    assert.equal(Object.values(initial.config.budget).every((v) => v === 0), true);
    assert.equal(JSON.stringify(initial).includes('synthetic-auth-test-signing-secret'), false);
    assert.equal('lease' in initial || 'nonces' in initial, false);
    const body = { action: 'save-draft', version: initial.version, draftVersion: initial.draftVersion, config: structuredClone(initial.draft) };
    body.config.brand.tone = 'Synthetic quiet copy for isolated tests';
    const saved = expectStatus(await env.admin.request('/admin/advertising/action', body), 200);
    assert.equal(saved.draft.brand.tone, body.config.brand.tone); assert.notEqual(saved.config.brand.tone, body.config.brand.tone);
    expectStatus(await env.admin.request('/admin/advertising/action', body), 409);
    const applied = expectStatus(await env.admin.request('/admin/advertising/action', { action: 'apply', version: saved.version, draftVersion: saved.draftVersion, configRevision: saved.configRevision }), 200);
    assert.equal(applied.config.brand.tone, body.config.brand.tone); assert.equal(applied.config.enabled, false);
    const prepared = expectStatus(await env.admin.request('/admin/advertising/action', { action: 'prepare', version: applied.version }), 200);
    assert.equal(prepared.current, null); assert.equal(prepared.candidate, null); assert.equal(prepared.working, false);
    const exported = expectStatus(await env.admin.request('/admin/advertising/export'), 200);
    assert.deepEqual(Object.keys(exported).sort(), ['config', 'configRevision', 'draft', 'exportedAt', 'schema']);
    for (const path of ['/.env', '/src/advertising/store.php', '/.local/advertising/state.json', '/bin/advertising-tick.php']) expectStatus(await env.admin.request(path), 404);
    expectStatus(await env.client().request('/advertising/machine', {}, { Origin: '' }), 403);
    expectStatus(await env.client().request('/advertising/machine', {}, { Origin: 'https://evil.invalid' }), 403);
    const php = process.env.PHP_BINARY || (process.platform === 'win32' ? 'C:/xampp/php/php.exe' : 'php');
    const ids = JSON.parse(execFileSync(php, ['-d', 'extension=gd', 'tests/support/advertising-image-fixture.php', directory], { encoding: 'utf8', windowsHide: true }));
    expectStatus(await env.client().request('/advertising/image?id=' + ids.current), 401);
    const image = await env.member.request('/advertising/image?id=' + ids.current);
    assert.equal(image.status, 200); assert.equal(image.headers.get('content-type'), 'image/jpeg');
    assert.equal(image.headers.get('cache-control'), 'private, no-store'); assert.equal(image.headers.get('x-content-type-options'), 'nosniff');
    assert.equal(image.bytes[0], 255); assert.equal(image.bytes[1], 216);
    expectStatus(await env.member.request('/advertising/image?id=' + ids.candidate), 404);
    assert.equal((await env.admin.request('/advertising/image?id=' + ids.candidate)).status, 200);
    expectStatus(await env.member.request('/advertising/image?id=../state.json'), 404);
    expectStatus(await env.member.request('/advertising/image?id=' + ids.current, undefined, { Origin: 'null' }), 403);
  } finally {
    await env?.stop();
    if (originalDirectory === undefined) delete process.env.ADVERTISING_DIRECTORY; else process.env.ADVERTISING_DIRECTORY = originalDirectory;
    if (originalSecret === undefined) delete process.env.ADVERTISING_SIGNING_SECRET; else process.env.ADVERTISING_SIGNING_SECRET = originalSecret;
    for (const file of await readdir(directory)) {
      if (file === 'assets') {
        for (const image of await readdir(resolve(directory, 'assets'))) { assert.match(image, /^[a-f0-9]{48}\.jpg$/); await rm(resolve(directory, 'assets', image)); }
        await rmdir(resolve(directory, 'assets'));
      } else { assert.match(file, /^state\.(json|lock)$/); await rm(resolve(directory, file)); }
    }
    await rmdir(directory);
  }
});
