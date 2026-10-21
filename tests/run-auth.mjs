import assert from 'node:assert/strict'
import { spawn, execFileSync } from 'node:child_process'
import { readFileSync, openSync, closeSync } from 'node:fs'
import { createServer } from 'node:net'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { setTimeout as delay } from 'node:timers/promises'

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const php = process.env.PHP_BINARY || 'C:\\xampp\\php\\php.exe'
const fixture = (action) => execFileSync(php, ['tests/fixture.php', action], { cwd: root, stdio: 'inherit' })
fixture('--create')
const config = JSON.parse(readFileSync(resolve(root, '.local/auth-test.json'), 'utf8'))
const port = await new Promise((done) => {
  const probe = createServer()
  probe.listen(0, '127.0.0.1', () => { const value = probe.address().port; probe.close(() => done(value)) })
})
const log = openSync(resolve(root, '.local/auth-test.log'), 'a')
const server = spawn(php, ['-S', `127.0.0.1:${port}`, 'router.php'], {
  cwd: root, env: { ...process.env, ...config }, stdio: ['ignore', log, log], windowsHide: true,
})
const base = `http://127.0.0.1:${port}`
class Browser {
  cookie = ''
  csrf = ''
  async request(path, body, options = {}) {
    const response = await fetch(`${base}${path}`, {
      method: body === undefined ? 'GET' : 'POST',
      headers: {
        Cookie: this.cookie, Origin: config.APP_ORIGIN,
        ...(body === undefined ? {} : { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrf }),
        ...options.headers,
      },
      body: body === undefined ? undefined : JSON.stringify(body),
    })
    for (const value of response.headers.getSetCookie()) {
      if (value.startsWith('allin_family_session=')) this.cookie = value.split(';')[0]
    }
    const data = await response.json()
    if (data.csrfToken) this.csrf = data.csrfToken
    return { status: response.status, data, headers: response.headers }
  }
}
let passed = 0
function check(label, result) { assert.ok(result, label); passed++; console.log(`PASS: ${label}`) }
try {
  let ready = false
  for (let index = 0; index < 50; index++) {
    if (server.exitCode !== null) throw new Error('The test API failed to start.')
    try { ready = (await fetch(`${base}/health`)).ok } catch {}
    if (ready) break
    await delay(100)
  }
  assert.ok(ready, 'Test API did not become ready.')
  const member = new Browser()
  const anonymous = await member.request('/auth/session')
  check('anonymous session contains no account', anonymous.data.user === null)
  const cookies = anonymous.headers.getSetCookie().join(';')
  check('session cookie is HttpOnly and SameSite', /httponly/i.test(cookies) && /samesite=lax/i.test(cookies))
  check('private endpoint rejects anonymous visitors', (await member.request('/family')).status === 401)
  check('registration endpoint does not exist', (await member.request('/auth/register', {})).status === 404)
  const credentials = { username: 'test_member', password: config.TEST_PASSWORD }
  check('login requires a CSRF token', (await member.request('/auth/login', credentials, { headers: { 'X-CSRF-Token': '' } })).status === 403)
  check('login rejects other origins', (await member.request('/auth/login', credentials, { headers: { Origin: 'https://other.invalid' } })).status === 403)
  const wrong = await member.request('/auth/login', { ...credentials, password: 'incorrect password' })
  const unknown = await member.request('/auth/login', { ...credentials, username: 'unknown_member' })
  check('invalid and unknown accounts receive the same rejection', wrong.status === 401 && unknown.status === 401 && wrong.data.error === unknown.data.error)
  check('SQL-like username does not grant access', (await member.request('/auth/login', { ...credentials, username: "' OR 1=1 --" })).status === 401)
  const oldCookie = member.cookie
  const login = await member.request('/auth/login', { ...credentials, role: 'admin' })
  check('valid account signs in with a new session ID', login.status === 200 && member.cookie !== oldCookie)
  check('password hashes are never returned', !JSON.stringify(login.data).includes('password_hash'))
  check('client cannot promote its account role', login.data.user.role === 'member')
  check('login rotates the CSRF token', login.data.csrfToken !== anonymous.data.csrfToken)
  check('signed-in member can access private endpoint', (await member.request('/family')).status === 200)
  const replay = new Browser(); replay.cookie = oldCookie
  check('pre-login session cannot access private data', (await replay.request('/family')).status === 401)
  const other = new Browser(); await other.request('/auth/session')
  await other.request('/auth/login', credentials)
  const freshPassword = 'A different private passphrase for tests'
  check('weak password is rejected', (await member.request('/auth/password', { currentPassword: config.TEST_PASSWORD, newPassword: 'short' })).status === 422)
  check('password change succeeds', (await member.request('/auth/password', { currentPassword: config.TEST_PASSWORD, newPassword: freshPassword })).status === 200)
  check('password change revokes other sessions', (await other.request('/family')).status === 401)
  const temporary = new Browser(); await temporary.request('/auth/session')
  await temporary.request('/auth/login', { username: 'test_temporary', password: config.TEST_PASSWORD })
  check('temporary passwords cannot access private data', (await temporary.request('/family')).data.code === 'password_change_required')
  await temporary.request('/auth/password', { currentPassword: config.TEST_PASSWORD, newPassword: freshPassword })
  check('first-login password change unlocks private access', (await temporary.request('/family')).status === 200)
  check('logout requires a CSRF token', (await member.request('/auth/logout', {}, { headers: { 'X-CSRF-Token': '' } })).status === 403)
  const loggedInCookie = member.cookie
  check('logout succeeds', (await member.request('/auth/logout', {})).status === 200)
  const stolen = new Browser(); stolen.cookie = loggedInCookie
  check('logged-out session cannot be replayed', (await stolen.request('/family')).status === 401)
  const disabled = new Browser(); await disabled.request('/auth/session')
  await disabled.request('/auth/login', { username: 'test_disabled', password: config.TEST_PASSWORD })
  fixture('--deactivate=test_disabled')
  check('disabling an account revokes its existing session', (await disabled.request('/family')).status === 401)
  const throttle = new Browser(); await throttle.request('/auth/session')
  for (let index = 0; index < 5; index++) await throttle.request('/auth/login', { username: 'test_throttle', password: 'wrong password' })
  const limited = await throttle.request('/auth/login', { username: 'test_throttle', password: config.TEST_PASSWORD })
  check('repeated attempts are throttled with retry guidance', limited.status === 429 && limited.headers.has('retry-after'))
  for (const path of ['/.env', '/.git/config', '/.local/mysql-admin.ini', '/bin/create-user.php', '/src/auth.php']) {
    check(`server files are not served: ${path}`, (await new Browser().request(path)).status === 404)
  }
  await delay(2100)
  check('idle sessions expire on the server', (await temporary.request('/family')).status === 401)
  console.log(`${passed} authentication checks passed.`)
} finally {
  server.kill()
  if (server.exitCode === null) await new Promise((done) => server.once('exit', done))
  closeSync(log)
  fixture('--cleanup')
}
