import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { execFileSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'
import { expectStatus as ok } from '../support/environment.mjs'

export function foodAlertTests(get) {
  describe('Home FSA food alerts', () => {
    it('validates notices, official links, persisted caching and bounded outage fallback without network access', () => {
      const output = execFileSync(process.env.PHP_BINARY || 'C:/xampp/php/php.exe', [
        fileURLToPath(new URL('../food-alerts-unit.php', import.meta.url)),
      ], { windowsHide: true, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] })
      assert.match(output, /Food alerts: 34 checks passed/)
    })
    it('requires a family session and accepts no writes', async () => {
      const anonymous = get().client()
      ok(await anonymous.request('/food-alerts'), 401)
      ok(await get().owner.request('/food-alerts', {}), 405)
    })
    it('recognises the Alerts section in admin usage without recording query details', () => {
      const usage = fileURLToPath(new URL('../../src/usage.php', import.meta.url)).replaceAll('\\', '/')
      const output = execFileSync(process.env.PHP_BINARY || 'C:/xampp/php/php.exe', [
        '-r', `require ${JSON.stringify(usage)}; echo json_encode([usagePage('/alerts'),usagePage('/alerts?private=ignored')]);`,
      ], { windowsHide: true, encoding: 'utf8' })
      assert.deepEqual(JSON.parse(output), ['/alerts', '/other'])
    })
  })
}
