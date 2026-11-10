import { before, after, it } from 'node:test'
import assert from 'node:assert/strict'
import { startEnvironment, expectStatus as ok, key, memoryForm } from './support/environment.mjs'

let env
before(async () => { env = await startEnvironment() })
after(async () => { await env?.stop() })
const install = async (visibility = 'family', extra = {}) => {
  const body = { kind: 'where-is-it', title: 'QA collection', visibility, requestKey: key(), ...extra }
  const state = ok(await env.owner.request('/widgets/add', body), 200)
  return { body, widget: state.widgets.find(widget => widget.kind === 'where-is-it' && widget.visibility === visibility) }
}
const draft = (widget, extra = {}) => ({ widgetId: widget.id, title: 'QA item', location: 'Hall cupboard · top shelf', note: 'Private marker\nSecond line', removePhoto: false, requestKey: key(), ...extra })
const write = (actor, action, body, photo) => actor.request('/where-is-it/' + action, memoryForm(body, photo))

it('requires authentication/CSRF, isolates personal items and prevents dinner actions on collections', async () => {
  ok(await env.client().request('/where-is-it'), 401)
  const { widget } = await install('private')
  const body = draft(widget)
  ok(await env.owner.request('/where-is-it/add', memoryForm(body), { 'X-CSRF-Token': '' }), 403)
  const item = ok(await write(env.owner, 'add', body), 200).items.find(item => item.widgetId === widget.id)
  assert.equal(item.note, body.note)
  for (const actor of [env.member, env.admin]) {
    assert.equal(ok(await actor.request('/where-is-it'), 200).items.some(row => row.id === item.id), false)
    ok(await write(actor, 'edit', { ...draft(widget), id: item.id, version: item.version }), 404)
    ok(await write(actor, 'remove', { ...draft(widget), id: item.id, version: item.version }), 404)
  }
  for (const action of ['spin', 'publish', 'respond', 'eaten', 'remove-meal']) ok(await env.owner.request('/widgets/' + action, { id: widget.id, version: widget.version, requestKey: key() }), 422)
  const activity = JSON.stringify(ok(await env.admin.request('/admin/activity'), 200))
  assert.equal(activity.includes('Private marker'), false)
})

it('allows family member CRUD, preserves exact receipts after edits/removal and rejects stale writes', async () => {
  const { widget, body: installation } = await install()
  assert.equal(ok(await env.owner.request('/widgets/add', installation), 200).widgets.filter(row => row.kind === 'where-is-it' && row.visibility === 'family').length, 1)
  const body = draft(widget)
  const first = ok(await write(env.member, 'add', body), 200)
  const item = first.items.find(item => item.widgetId === widget.id)
  const repeated = ok(await write(env.member, 'add', body), 200)
  assert.deepEqual(repeated.receipt, first.receipt)
  assert.equal(repeated.items.filter(row => row.id === item.id).length, 1)
  ok(await write(env.member, 'add', { ...body, title: 'Changed intent' }), 409)
  const edit = { ...draft(widget), id: item.id, version: item.version, location: 'Garage' }
  const changed = ok(await write(env.member, 'edit', edit), 200)
  const latest = changed.items.find(row => row.id === item.id)
  ok(await write(env.owner, 'edit', { ...edit, requestKey: key() }), 409)
  ok(await write(env.owner, 'remove', { ...edit, requestKey: key() }), 409)
  assert.deepEqual(ok(await write(env.member, 'add', body), 200).receipt, first.receipt)
  assert.deepEqual(ok(await write(env.member, 'edit', edit), 200).receipt, changed.receipt)
  const remove = { widgetId: widget.id, id: latest.id, version: latest.version, requestKey: key() }
  const removed = ok(await write(env.member, 'remove', remove), 200)
  assert.equal(removed.items.some(row => row.id === item.id), false)
  assert.deepEqual(ok(await write(env.member, 'remove', remove), 200).receipt, removed.receipt)
  assert.deepEqual(ok(await write(env.member, 'edit', edit), 200).receipt, changed.receipt)
  assert.equal(ok(await write(env.member, 'add', body), 200).items.some(row => row.id === item.id), false)
  ok(await env.member.request('/widgets/edit', { ...installation, id: widget.id, version: widget.version }), 403)
  ok(await env.member.request('/widgets/remove', { id: widget.id, version: widget.version, requestKey: key() }), 403)
})

it('validates required fields, image content, limits and keeps Home counts current', async () => {
  const widgets = ok(await env.owner.request('/widgets'), 200).widgets
  const widget = widgets.find(row => row.kind === 'where-is-it' && row.visibility === 'family')
  for (const extra of [{ title: '  ' }, { location: '' }, { title: 'x'.repeat(101) }, { location: 'x'.repeat(161) }, { note: 'x'.repeat(1001) }, { removePhoto: 'yes' }]) ok(await write(env.owner, 'add', draft(widget, extra)), 422)
  ok(await write(env.owner, 'add', draft(widget), Buffer.from('<svg/>')), 422)
  const before = await env.owner.request('/widgets')
  const created = ok(await write(env.owner, 'add', draft(widget, { title: 'Count marker' })), 200)
  const after = await env.owner.request('/widgets', undefined, { 'If-None-Match': before.headers.get('etag') })
  ok(after, 200)
  assert.notEqual(before.headers.get('etag'), after.headers.get('etag'))
  const summary = after.data.widgets.find(row => row.id === widget.id)
  const items = created.items.filter(row => row.widgetId === widget.id)
  assert.equal(summary.itemCount, items.length)
  assert.equal(summary.latestItemUpdatedAt, items.map(item => item.updatedAt).sort().at(-1))
})

it('serialises real parallel edits and UUID retries to one durable result', async () => {
  const { execFile } = await import('node:child_process')
  const { promisify } = await import('node:util')
  const run = promisify(execFile)
  const actorRun = async (actor, action, body, goAt) => JSON.parse((await run('C:/xampp/php/php.exe', ['tests/support/where-is-it-fixture.php', env.fixtureFile, env.runId, JSON.stringify({ actor, action, body, goAt })], { windowsHide: true })).stdout)
  const widget = ok(await env.owner.request('/widgets'), 200).widgets.find(row => row.kind === 'where-is-it' && row.visibility === 'family')
  const item = ok(await write(env.owner, 'add', draft(widget, { title: 'Parallel item' })), 200).items.find(item => item.title === 'Parallel item')
  let at = Date.now() / 1000 + 1
  const competing = await Promise.all(['owner', 'member'].map(actor => actorRun(actor, 'edit', { ...draft(widget), id: item.id, version: item.version, location: actor + ' cupboard' }, at)))
  assert.deepEqual(competing.map(result => result.status).sort(), [200, 409])
  const same = draft(widget, { title: 'Parallel exact retry' })
  at = Date.now() / 1000 + 1
  const retries = await Promise.all([actorRun('owner', 'add', same, at), actorRun('owner', 'add', same, at)])
  assert.deepEqual(retries.map(result => result.status), [200, 200])
  assert.deepEqual(retries[0].data.receipt, retries[1].data.receipt)
  assert.equal(ok(await env.owner.request('/where-is-it'), 200).items.filter(item => item.title === same.title).length, 1)
})

it('acknowledges installation settings/removal retries after later changes', async () => {
  const { widget, body } = await install('private', { title: 'Receipt collection' })
  const edit = { id: widget.id, version: widget.version, title: 'Changed collection', visibility: 'private', requestKey: key() }
  const changed = ok(await env.owner.request('/widgets/edit', edit), 200)
  const latest = changed.widgets.find(row => row.id === widget.id)
  const remove = { id: widget.id, version: latest.version, requestKey: key() }
  const removed = ok(await env.owner.request('/widgets/remove', remove), 200)
  assert.deepEqual(ok(await env.owner.request('/widgets/edit', edit), 200).receipt, changed.receipt)
  assert.deepEqual(ok(await env.owner.request('/widgets/remove', remove), 200).receipt, removed.receipt)
  assert.equal(ok(await env.owner.request('/widgets/add', body), 200).widgets.some(row => row.id === widget.id), false)
  ok(await env.owner.request('/widgets/edit', { ...edit, title: 'Altered intent' }), 409)
})

it('authorises current photos on every fetch and revokes replaced/private/deleted URLs', async () => {
  const widget = ok(await env.owner.request('/widgets'), 200).widgets.find(row => row.kind === 'where-is-it' && row.visibility === 'family')
  const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6yF8AAAAASUVORK5CYII=', 'base64')
  const body = draft(widget, { title: 'Photo marker' })
  const state = ok(await write(env.owner, 'add', body, png), 200)
  let item = state.items.find(row => row.title === 'Photo marker')
  const photo = item => '/where-is-it/photo?id=' + item.id + '&v=' + item.photoKey
  const oldUrl = photo(item)
  const fetched = await env.member.request(oldUrl)
  assert.equal(fetched.status, 200)
  assert.equal(fetched.headers.get('cache-control'), 'private, no-store')
  ok(await env.client().request(oldUrl), 401)
  assert.deepEqual(ok(await write(env.owner, 'add', body, png), 200).receipt, state.receipt)
  const replaced = ok(await write(env.member, 'edit', { ...draft(widget), id: item.id, version: item.version }, png), 200)
  item = replaced.items.find(row => row.id === item.id)
  assert.notEqual(photo(item), oldUrl)
  ok(await env.member.request(oldUrl), 404)
  ok(await env.owner.request('/widgets/edit', { id: widget.id, version: widget.version, title: widget.title, visibility: 'private', requestKey: key() }), 200)
  for (const actor of [env.member, env.admin]) ok(await actor.request(photo(item), undefined, { 'If-None-Match': 'anything' }), 404)
  assert.equal((await env.owner.request(photo(item))).status, 200)
  const currentWidget = ok(await env.owner.request('/widgets'), 200).widgets.find(row => row.id === widget.id)
  ok(await env.owner.request('/widgets/remove', { id: widget.id, version: currentWidget.version, requestKey: key() }), 200)
  ok(await env.owner.request(photo(item)), 404)
})
