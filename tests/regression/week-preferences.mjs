import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { createHmac } from 'node:crypto'
import { readFileSync } from 'node:fs'
import { execFileSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'
import { expectStatus as ok } from '../support/environment.mjs'

export function weekPreferenceTests(get) {
  function token(user, name, expires = Math.floor(Date.now() / 1000) + 3600) {
    // The test-only fixture key never leaves this isolated test process.
    const config = JSON.parse(readFileSync(new URL('../../.local/regression/fixture.json', import.meta.url), 'utf8'))
    assert.equal(config.APP_ENV, 'test')
    assert.equal(config.DB_NAME, 'theallinfamily_regression_test')
    const payload = Buffer.from(JSON.stringify({ town: { id: 2647793, label: name, latitude: 51.23536, longitude: -0.57427, timeZone: 'Europe/London' }, user, expires })).toString('base64')
    return payload + '.' + createHmac('sha256', config.APP_KEY).update(payload).digest('hex')
  }
  describe('personal Week Ahead preferences', () => {
    it('normalises forecasts, filters holidays, caches results and backs off safely without external requests', () => {
      const result = execFileSync(process.env.PHP_BINARY || 'C:/xampp/php/php.exe', [fileURLToPath(new URL('../week-context-unit.php', import.meta.url))], { windowsHide: true, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] })
      assert.deepEqual(JSON.parse(result.trim()), { passed: 7, networkRequests: 0 })
    })
    it('defaults to Guildford without exposing coordinates or writing another account', async () => {
      for (const client of [get().owner, get().member]) {
        const initial = ok(await client.request('/profile/week-preferences'), 200)
        assert.equal(initial.town.label, 'Guildford, Surrey, United Kingdom')
        assert.equal(initial.region, 'england-and-wales')
        assert.equal(initial.version, 0)
        assert.equal('latitude' in initial.town, false)
      }
    })
    it('persists separate towns/regions across a new session and ignores spoofed owner fields', async () => {
      const { owner, member, users } = get()
      const first = ok(await owner.request('/profile/week-preferences', { version: 0, region: 'scotland', townToken: token(users.owner.id, 'Owner town'), userId: users.member.id }), 200)
      assert.equal(first.town.label, 'Owner town')
      const second = ok(await member.request('/profile/week-preferences', { version: 0, region: 'northern-ireland', townToken: token(users.member.id, 'Member town'), id: users.owner.id }), 200)
      assert.equal(second.town.label, 'Member town')
      const freshOwner = await get().client().login('owner')
      assert.deepEqual(ok(await freshOwner.request('/profile/week-preferences?userId=' + users.member.id), 200), first)
      assert.deepEqual(ok(await member.request('/profile/week-preferences?userId=' + users.owner.id), 200), second)
      const admin = ok(await get().admin.request('/profile/week-preferences?userId=' + users.owner.id), 200)
      assert.equal(admin.version, 0)
      const entries = ok(await get().admin.request('/admin/activity?days=0&type=user_profile'), 200).entries
      const changed = entries.find(entry => entry.action === 'week_preferences_changed' && entry.actor.id === users.owner.id)
      assert.equal(changed.after.town, 'Owner town')
      assert.equal(JSON.stringify(changed).includes('latitude'), false)
    })
    it('rejects expired/forged/other-user choices and stale saves without losing the winner', async () => {
      const { owner, member, users } = get()
      const before = ok(await owner.request('/profile/week-preferences'), 200)
      for (const bad of ['forged.signature', token(users.owner.id, 'Expired', 1), token(users.member.id, 'Not mine')]) {
        ok(await owner.request('/profile/week-preferences', { version: before.version, region: 'scotland', townToken: bad }), 422)
      }
      ok(await owner.request('/profile/week-preferences', { version: before.version, region: 'invalid' }), 422)
      const winner = ok(await owner.request('/profile/week-preferences', { version: before.version, region: '', townToken: null }), 200)
      ok(await owner.request('/profile/week-preferences', { version: before.version, region: 'scotland' }), 409)
      assert.deepEqual(ok(await owner.request('/profile/week-preferences'), 200), winner)
      assert.equal(ok(await member.request('/profile/week-preferences'), 200).town.label, 'Member town')
      const disabled = ok(await owner.request('/week-context'), 200)
      assert.equal(disabled.weather.data, null)
      assert.equal(disabled.holidays.data, null)
    })
    it('requires login and CSRF and keeps account IDs out of public profiles', async () => {
      const anonymous = get().client()
      for (const path of ['/profile/week-preferences', '/week-context', '/week-context/towns?q=Guildford']) ok(await anonymous.request(path), 401)
      const current = ok(await get().owner.request('/profile/week-preferences'), 200)
      ok(await get().owner.request('/profile/week-preferences', { version: current.version, region: 'scotland' }, { 'X-CSRF-Token': '' }), 403)
      const profiles = ok(await get().admin.request('/admin/users'), 200)
      assert.equal(JSON.stringify(profiles).includes('Member town'), false)
    })
  })
}
