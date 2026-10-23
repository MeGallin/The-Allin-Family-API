import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { expectStatus as ok } from '../support/environment.mjs'

export function travelTests(get) {
  describe('personal TfL preferences', () => {
    it('defaults off and never fetches a public feed until lines are selected', async () => {
      const prefs = ok(await get().owner.request('/profile/travel-preferences'), 200)
      assert.deepEqual(prefs.lines, [])
      assert.equal(prefs.version, 0)
      assert.equal(prefs.availableLines.length, 19)
      const disabled = ok(await get().owner.request('/travel'), 200)
      assert.equal(disabled.live.status, 'unconfigured')
      assert.equal(disabled.planned.data, null)
    })
    it('saves per account, ignores spoofed user IDs and persists across a new session', async () => {
      const {owner, member, users} = get()
      const saved = ok(await owner.request('/profile/travel-preferences', {version:0,lines:['victoria','central'],userId:users.member.id}),200)
      assert.deepEqual(saved.lines,['central','victoria'])
      assert.deepEqual(ok(await member.request('/profile/travel-preferences?userId='+users.owner.id),200).lines,[])
      const fresh = await get().client().login('owner')
      assert.deepEqual(ok(await fresh.request('/profile/travel-preferences'),200),saved)
      const entries=ok(await get().admin.request('/admin/activity?type=user_profile'),200).entries
      const activity=entries.find(entry=>entry.action==='travel_preferences_changed')
      assert.equal(activity.actor.id,users.owner.id)
      assert.deepEqual(activity.after.travelLines,['Central','Victoria'])
    })
    it('validates input, CSRF and optimistic versions without overwriting another save',async()=>{
      const {owner}=get()
      const current=ok(await owner.request('/profile/travel-preferences'),200)
      for(const lines of [['fake'],['central','central'],[1],{id:'central'},null]) {
        ok(await owner.request('/profile/travel-preferences',{version:current.version,lines}),422)
      }
      ok(await owner.request('/profile/travel-preferences',{version:current.version,lines:[]},{'X-CSRF-Token':''}),403)
      const winner=ok(await owner.request('/profile/travel-preferences',{version:current.version,lines:[]}),200)
      ok(await owner.request('/profile/travel-preferences',{version:current.version,lines:['central']}),409)
      assert.deepEqual(ok(await owner.request('/profile/travel-preferences'),200),winner)
    })
    it('requires authentication for preferences and feed',async()=>{
      const anonymous=get().client()
      for(const path of ['/profile/travel-preferences','/travel']) ok(await anonymous.request(path),401)
    })
  })
}
