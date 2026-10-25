import { before, after, beforeEach, describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { execFileSync, execFile } from 'node:child_process'
import { promisify } from 'node:util'
import { rmSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { startEnvironment, expectStatus as ok, key } from './support/environment.mjs'
const php = process.env.PHP_BINARY || 'C:/xampp/php/php.exe', run = promisify(execFile)
let env
const helperArgs = (mode, body = {}) => ['tests/support/daily-word-fixture.php', env.fixtureFile, env.runId, mode, JSON.stringify(body)]
const helper = (mode, body = {}) => JSON.parse(execFileSync(php, helperArgs(mode, body), { encoding:'utf8',windowsHide:true }))
const snap = async (actor = env.owner) => ok(await actor.request('/daily-word'),200)
const post = async (action, body, status=200, actor=env.owner) => ok(await actor.request('/daily-word/'+action,body),status)
const body = (s, extra={}) => ({version:s.installationVersion,day:s.day,revision:s.progress.revision,requestKey:key(),...extra})
async function installed(actor=env.owner) { const s=await snap(actor);await post('install',{version:s.installationVersion,requestKey:key()},200,actor);return snap(actor) }
async function guess(s,word) { await post('guess',body(s,{guess:word}));return snap() }
before(async()=>{env=await startEnvironment({suite:'daily-word'})})
after(async()=>{await env?.stop()})
beforeEach(()=>helper('reset'))
describe('Daily Word isolated API contract',()=>{
 it('checks scoring, pinned dictionary inclusion and 23/25-hour London days',()=>{assert.equal(JSON.parse(execFileSync(php,['tests/daily-word-unit.php'],{encoding:'utf8',windowsHide:true})).passed,18)})
 it('requires authentication and CSRF; sessions cannot choose another owner',async()=>{
  ok(await env.client().request('/daily-word'),401)
  const s=await installed();ok(await env.owner.request('/daily-word/guess',body(s,{guess:'crane'}),{'X-CSRF-Token':''}),403)
  await guess(s,'crane')
  for(const actor of [env.member,env.admin]){
   const other=ok(await actor.request('/daily-word?ownerId='+env.owner.user.id),200);assert.equal(other.ownerId,actor.user.id);assert.deepEqual(other.progress.guesses,[])
   await post('guess',body(s,{guess:'apple',ownerId:env.owner.user.id}),409,actor)
  }
  for(const actor of [env.member,env.admin]){const own=await installed(actor);assert.equal(own.installationVersion,s.installationVersion);const accepted=await post('guess',body(own,{guess:'house',ownerId:env.owner.user.id}),200,actor);assert.equal(accepted.ownerId,actor.user.id);assert.deepEqual((await snap(actor)).progress.guesses.map(g=>g.word),['house']);assert.deepEqual((await snap()).progress.guesses.map(g=>g.word),['crane'])}
 })
 it('saves normalized guesses; invalid and duplicate guesses do not consume attempts',async()=>{
  let s=await installed();for(const invalid of ['zzzzz','four','12345','abcdef',null])await post('guess',body(s,{guess:invalid}),422)
  assert.equal((await snap()).progress.revision,0)
  s=await guess(s,'ALLOT');assert.equal(s.progress.guesses[0].word,'allot');assert.equal(s.progress.guesses.length,1)
  await post('guess',body(s,{guess:'allot'}),422);assert.equal((await snap()).progress.guesses.length,1)
  assert.deepEqual((await snap()).progress,s.progress)
 })
 it('keeps each immutable accepted receipt after later writes and rejects altered reuse',async()=>{
  let s=await installed();const first=body(s,{guess:'crane'});const receipt=await post('guess',first);s=await snap();s=await guess(s,'slate');assert.equal(s.progress.guesses.length,2)
  assert.deepEqual(await post('guess',first),receipt);await post('guess',{...first,guess:'apple'},422)
  assert.equal(helper('inspect').daily_word_receipts.filter(r=>JSON.parse(r.response).progress?.guesses.length).length,2)
 })
 it('enforces six accepted guesses and derives a loss without revealing the answer',async()=>{
  let s=await installed();const saved=[];for(const word of ['crane','slate','house','train','world','beach']){const request=body(s,{guess:word});saved.push([request,await post('guess',request)]);s=await snap()}
  assert.equal(s.progress.finished,true);assert.equal(s.progress.solved,false);assert.equal(s.progress.guesses.length,6)
  await post('guess',body(s,{guess:'apple'}),409);assert.equal((await snap()).progress.guesses.length,6);assert.equal(JSON.stringify(s).includes('apple'),false)
  const accepted=helper('inspect').daily_word_receipts.filter(r=>JSON.parse(r.response).progress?.guesses.length);assert.equal(accepted.length,6)
  for(const [request,receipt] of saved)assert.deepEqual(await post('guess',request),receipt)
 })
 it('shares server-derived finished results only, withdraws and deduplicates administrator metadata',async()=>{
  let s=await installed();await post('share',body(s,{solved:true,attempts:1}),422);s=await guess(s,'apple');assert.equal(s.progress.solved,true)
  assert.deepEqual(ok(await env.member.request('/daily-word/results'),200).results,[])
  const share=body(s,{solved:false,attempts:6,answer:'bogus'});await post('share',share);await post('share',share);s=await snap()
  const results=ok(await env.member.request('/daily-word/results'),200).results;assert.equal(results.length,1);assert.deepEqual(Object.keys(results[0]).sort(),['attempts','date','name','ownerId','solved']);assert.equal(results[0].attempts,1);assert.equal(results[0].solved,true)
  await post('share',body(s));s=await snap();const withdraw=body(s);await post('withdraw',withdraw);await post('withdraw',withdraw);s=await snap();await post('withdraw',body(s));assert.deepEqual(ok(await env.member.request('/daily-word/results'),200).results,[])
  const audit=ok(await env.admin.request('/admin/activity?type=daily_word&days=7'),200).entries;assert.equal(audit.length,3)
  for(const event of audit){assert.deepEqual(Object.keys(event.after).sort(),['kind','ownerId','version','visibility']);assert.equal(event.before,null)}
  assert.equal(JSON.stringify(audit).includes('apple'),false);ok(await env.member.request('/admin/activity?type=daily_word'),403)
 })
 it('isolates private lifecycle from family shelf/ETag and retains attempts through reinstall',async()=>{
  const before=await env.member.request('/widgets');let s=await installed();const request=body(s,{guess:'crane'}),receipt=await post('guess',request);s=await snap();const pending=body(s,{guess:'slate'});await post('remove',{version:s.installationVersion,requestKey:key()});let removed=await snap();assert.equal(removed.installed,false)
  await post('guess',pending,409);await post('install',{version:removed.installationVersion,requestKey:key()});const next=await snap();assert.equal(next.progress.guesses.length,1);assert.ok(next.installationVersion>s.installationVersion);await post('guess',pending,409)
  const after=await env.member.request('/widgets');assert.equal(after.headers.get('etag'),before.headers.get('etag'));assert.deepEqual(after.data,before.data)
  assert.deepEqual(await post('guess',request),receipt);assert.equal((await snap()).progress.guesses.length,1)
 })
 it('rejects new expired writes but replays an accepted old-day receipt after London midnight',()=>{
  const instant='2026-03-28T12:00:00Z',clock=helper('reset',{instant})
  const call=(action,body,at=instant)=>helper('mutate',{action,body,instant:at})
  assert.equal(call('install',{version:0,requestKey:key()}).status,200)
  const first={version:1,day:clock.currentDay,revision:0,guess:'crane',requestKey:key()};const accepted=call('guess',first);assert.equal(accepted.status,200)
  assert.equal(call('guess',{...first,revision:1,guess:'slate',requestKey:key()}).status,200)
  assert.deepEqual(call('guess',first,'2026-03-29T00:00:01Z'),accepted)
  assert.equal(call('guess',{...first,revision:2,guess:'house',requestKey:key()},'2026-03-29T00:00:01Z').status,409)
  assert.equal(helper('inspect').daily_word_progress[0].revision,2)
 })
 it('serializes real competing PHP processes to one accepted CAS guess',async()=>{
  const s=await installed()
  try{const responses=await Promise.all(['a','b'].map(label=>run(php,helperArgs('race',{label,action:'guess',body:body(s,{guess:label==='a'?'crane':'slate'})}),{windowsHide:true,timeout:15000})));assert.deepEqual(responses.map(r=>JSON.parse(r.stdout).status).sort(),[200,409]);assert.equal((await snap()).progress.guesses.length,1)}finally{for(const label of ['a','b'])rmSync(join(dirname(env.fixtureFile),'ready-'+label),{force:true})}
 })
 it('creates one immutable challenge atomically across simultaneous first readers',async()=>{
  helper('reset',{empty:true})
  try{const responses=await Promise.all(['a','b'].map(label=>run(php,helperArgs('race',{label,action:'challenge'}),{windowsHide:true,timeout:15000})));const values=responses.map(r=>JSON.parse(r.stdout));assert.equal(values[0].status,200);assert.equal(values[1].status,200);assert.deepEqual(values[0].data,values[1].data);assert.equal(helper('inspect').daily_word_challenges.length,1);assert.deepEqual((await snap()).progress.guesses,[])}finally{for(const label of ['a','b'])rmSync(join(dirname(env.fixtureFile),'ready-'+label),{force:true})}
 })
})
