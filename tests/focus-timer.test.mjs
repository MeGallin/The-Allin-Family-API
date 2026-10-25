import { before,after,beforeEach,describe,it } from 'node:test'
import assert from 'node:assert/strict'
import {execFileSync,execFile} from 'node:child_process'
import {promisify} from 'node:util'
import {rmSync} from 'node:fs'
import {dirname,join} from 'node:path'
import {startEnvironment,expectStatus as ok,key} from './support/environment.mjs'
const php=process.env.PHP_BINARY||'C:/xampp/php/php.exe',run=promisify(execFile),at='2026-03-29T12:00:00.123Z'
let env
const args=(mode,body={})=>['tests/support/focus-timer-fixture.php',env.fixtureFile,env.runId,mode,JSON.stringify(body)]
const helper=(mode,body={})=>JSON.parse(execFileSync(php,args(mode,body),{encoding:'utf8',windowsHide:true}))
const snap=(time=at)=>helper('snapshot',{at:time})
const fields=(s,extra={})=>({version:s.version,generation:s.generation,sessionId:s.session?.id??null,requestKey:key(),...extra})
const mutate=(action,s,extra={},time=at,status=200)=>{const result=helper('mutate',{action,body:fields(s,extra),at:time});assert.equal(result.status,status);return result.data}
const install=()=>mutate('install',snap())
const start=(s=install(),extra={},time=at)=>mutate('start',s,{phase:'focus',minutes:5,label:'Synthetic private label',...extra},time)
before(async()=>{env=await startEnvironment({suite:'focus-timer'})})
after(async()=>{await env?.stop()})
beforeEach(()=>helper('reset'))
describe('Focus Timer isolated owner/time/receipt contract',()=>{
 it('requires authentication/CSRF and isolates installed member/admin spoof requests',async()=>{
  ok(await env.client().request('/focus-timer'),401)
  const get=async actor=>ok(await actor.request('/focus-timer'),200),post=async(actor,action,body,status=200)=>ok(await actor.request('/focus-timer/'+action,body),status)
  let owner=await get(env.owner);owner=await post(env.owner,'install',fields(owner));
  ok(await env.owner.request('/focus-timer/start',fields(owner,{phase:'focus',minutes:5,label:'Owner private label'}),{'X-CSRF-Token':''}),403)
  owner=await post(env.owner,'start',fields(owner,{phase:'focus',minutes:5,label:'Owner private label'}))
  for(const actor of [env.member,env.admin]){let other=await get(actor);assert.equal(other.session,null);assert.equal(JSON.stringify(other).includes('Owner private label'),false);other=await post(actor,'install',fields(other));other=await post(actor,'start',fields(other,{phase:'short',minutes:1,label:'Own only',ownerId:owner.ownerId}));assert.equal(other.ownerId,actor.user.id);assert.notEqual(other.session.id,owner.session.id);await post(actor,'pause',fields(other,{sessionId:owner.session.id}),409)}
  assert.equal((await get(env.owner)).session.id,owner.session.id)
  const admin=ok(await env.admin.request('/admin/activity?type=focus_timer&days=7'),200);assert.equal(admin.entries.length,3);assert.equal(JSON.stringify(admin).includes('Owner private label'),false);for(const e of admin.entries)assert.deepEqual(Object.keys(e.after).sort(),['generation','kind','ownerId','visibility']);ok(await env.member.request('/admin/activity?type=focus_timer'),403)
  assert.deepEqual(ok(await env.member.request('/widgets'),200).widgets,[])
 })
 it('validates phase/duration/label and rejects a second current start',()=>{
  const s=install();for(const extra of [{minutes:4},{minutes:181},{minutes:5.5},{minutes:'5'},{phase:'short',minutes:0},{phase:'long',minutes:61},{phase:'other'},{label:'x'.repeat(81)},{label:'bad\nlabel'}])mutate('start',s,{phase:'focus',minutes:5,label:'',...extra},at,422)
  assert.equal(snap().session,null);const running=start(s);mutate('start',running,{phase:'focus',minutes:5,label:''},at,409);assert.equal(helper('inspect').focus_timer_sessions.length,1)
 })
 it('preserves millisecond precision, excludes long paused spans and records early actual time',()=>{
  let s=start();s=mutate('pause',s,{},'2026-03-29T12:00:01.357Z');assert.equal(s.session.elapsedMs,1234);assert.equal(s.session.state,'paused');assert.equal(snap('2026-03-29T13:00:00.000Z').session.elapsedMs,1234)
  s=mutate('resume',s,{},'2026-03-29T13:00:00.000Z');assert.equal(s.session.deadline,'2026-03-29T13:04:58.766Z');s=mutate('finish',s,{},'2026-03-29T13:00:10.000Z');assert.equal(s.session,null)
  const saved=snap('2026-03-29T13:00:11Z');assert.equal(saved.history[0].state,'early');assert.equal(saved.summary.focusMs,11234);assert.equal(saved.summary.focusCompleted,0)
 })
 it('normalises at-deadline Pause, caps time, credits no GET and atomically starts a manual break once',()=>{
  let s=start();s=mutate('pause',s,{},'2026-03-29T12:05:00.123Z');assert.equal(s.session.state,'elapsed');assert.equal(s.session.elapsedMs,300000);mutate('resume',s,{},'2026-03-29T12:05:00.124Z',409);assert.equal(snap('2026-03-29T12:06:00Z').summary.focusCompleted,0)
  const request=fields(s,{phase:'short',minutes:1,label:''}),call=time=>helper('mutate',{action:'next',body:request,at:time});const receipt=call('2026-03-29T12:06:00Z');assert.equal(receipt.status,200);assert.equal(receipt.data.session.phase,'short');assert.deepEqual(call('2026-03-29T18:00:00Z'),receipt)
  const next=snap('2026-03-29T12:07:00Z');assert.equal(next.session.state,'elapsed');assert.equal(next.summary.focusCompleted,1);assert.equal(next.summary.focusMs,300000);assert.equal(next.summary.breakMs,0)
  mutate('finish',next,{},'2026-03-29T12:07:00Z');const done=snap('2026-03-29T12:08:00Z');assert.equal(done.summary.breakMs,60000);assert.equal(done.history.length,2)
 })
 it('requires elapsed state for next and exact interval identity for replacement writes',()=>{
  let s=start();const original=s.session.id;mutate('next',s,{phase:'short',minutes:1,label:''},at,409);s=mutate('reset',s,{},'2026-03-29T12:01:00Z');s=start(s,{},'2026-03-29T12:02:00Z');mutate('finish',s,{sessionId:original},'2026-03-29T12:03:00Z',409);assert.equal(snap('2026-03-29T12:03:00Z').summary.focusMs,0);assert.equal(snap('2026-03-29T12:03:00Z').history[0].state,'discarded')
 })
 it('replays every old accepted action after later writes/removal/reinstall and rejects changed UUID reuse',()=>{
  let s=snap(),saved=[];const act=(action,extra,time)=>{const body=fields(s,extra),r=helper('mutate',{action,body,at:time});assert.equal(r.status,200);saved.push({action,body,result:r});s=r.data}
  act('install',{},at);act('start',{phase:'focus',minutes:15,label:'Private'},at);act('pause',{},'2026-03-29T12:01:00Z');act('resume',{},'2026-03-29T12:02:00Z');act('finish',{},'2026-03-29T12:03:00Z');act('start',{phase:'short',minutes:1,label:''},'2026-03-29T12:04:00Z');act('reset',{},'2026-03-29T12:04:30Z');act('remove',{},'2026-03-29T12:05:00Z');act('install',{},'2026-03-29T12:06:00Z')
  for(const r of saved)assert.deepEqual(helper('mutate',{action:r.action,body:r.body,at:'2026-03-30T12:00:00Z'}),r.result)
  const first=saved[0];assert.equal(helper('mutate',{action:first.action,body:{...first.body,id:'ignored-but-changed'},at}).status,422);assert.equal(s.session,null);assert.ok(s.generation>saved[0].result.data.generation);assert.equal(helper('inspect').focus_timer_receipts.length,saved.length)
 })
 it('actual removal discards running or archives elapsed without credit, rejects stale versions and preserves defaults',()=>{
  let s=start(undefined,{minutes:50});const captured=s;s=mutate('pause',s,{},'2026-03-29T12:01:00Z');mutate('remove',captured,{},'2026-03-29T12:02:00Z',409);s=mutate('remove',s,{},'2026-03-29T12:03:00Z');assert.equal(s.installed,false);assert.equal(s.session,null);const removed=s;s=mutate('install',s,{},'2026-03-29T12:04:00Z');assert.equal(s.defaults.focus,50);assert.equal(s.session,null);mutate('resume',captured,{},'2026-03-29T12:04:00Z',409);assert.equal(snap().summary.focusMs,0)
  s=start(s,{minutes:5},'2026-03-29T12:05:00Z');s=mutate('remove',s,{},'2026-03-29T13:00:00Z');assert.equal(snap('2026-03-29T13:01:00Z').history[0].state,'elapsed_removed');assert.equal(snap('2026-03-29T13:01:00Z').summary.focusMs,0);assert.ok(s.generation>removed.generation)
 })
 it('aggregates all >200 records and exact seven-day London spring boundaries independently of recents',()=>{
  helper('seed_history');const s=snap('2026-03-30T13:00:00Z');assert.equal(s.history.length,200);assert.equal(s.days.length,7);assert.equal(s.through,'2026-03-30');assert.equal(s.summary.focusCompleted,202);assert.equal(s.summary.focusMs,60720000);assert.equal(s.summary.breakMs,60000);assert.equal(s.days.find(d=>d.date==='2026-03-29').focusMs,120000);assert.equal(s.days.find(d=>d.date==='2026-03-30').breakMs,60000);assert.equal(s.days[0].date,'2026-03-24')
 })
 it('maps the 25-hour autumn day and lower seven-day BST boundary without SQL timezone tables',()=>{
  helper('seed_events',{rows:[{at:'2026-10-18T22:59:59.999Z',phase:'focus',elapsed:300000,state:'completed'},{at:'2026-10-18T23:00:00Z',phase:'focus',elapsed:300000,state:'completed'},{at:'2026-10-24T22:59:59.999Z',phase:'focus',elapsed:300000,state:'completed'},{at:'2026-10-24T23:00:00Z',phase:'focus',elapsed:300000,state:'completed'},{at:'2026-10-25T23:59:59.999Z',phase:'short',elapsed:60000,state:'completed'}]});const s=snap('2026-10-25T23:59:59.999Z');assert.equal(s.days[0].date,'2026-10-19');assert.equal(s.summary.focusCompleted,3);assert.equal(s.days[6].focusMs,300000);assert.equal(s.days[6].breakMs,60000);assert.equal(s.summary.breakMs,60000)
 })
 it('keeps generic install/remove audit deduplicated and private actions out of family ETags',async()=>{
  const before=await env.member.request('/widgets');let s=install();s=start(s);s=mutate('finish',s,{},'2026-03-29T12:01:00Z');s=mutate('remove',s,{},'2026-03-29T12:02:00Z');const audit=helper('inspect').audit;assert.deepEqual(audit.map(e=>e.action),['installed','removed']);for(const e of audit){assert.equal(e.entity_type,'focus_timer');assert.deepEqual(Object.keys(JSON.parse(e.after_data)).sort(),['generation','kind','ownerId','visibility']);assert.equal(e.before_data,null)}const after=await env.member.request('/widgets');assert.equal(after.headers.get('etag'),before.headers.get('etag'));assert.deepEqual(after.data,before.data)
 })
 it('serialises true parallel starts and terminal writes to one CAS winner',async()=>{
  let s=install();async function race(actions,time=at){try{const results=await Promise.all(['a','b'].map((label,i)=>run(php,args('race',{label,action:actions[i],body:fields(s,actions[i]==='start'?{phase:'focus',minutes:5,label:''}:{}),at:time}),{windowsHide:true,timeout:15000})));assert.deepEqual(results.map(r=>JSON.parse(r.stdout).status).sort(),[200,409])}finally{for(const label of ['a','b'])rmSync(join(dirname(env.fixtureFile),'ready-'+label),{force:true})}}
  await race(['start','start']);s=snap();assert.equal(helper('inspect').focus_timer_sessions.length,1);await race(['finish','reset']);const inspected=helper('inspect');assert.equal(inspected.focus_timer_sessions.length,1);assert.equal(snap().session,null);assert.ok(snap().summary.focusCompleted<=1);s=start(snap(),{},at);await race(['finish','finish'],'2026-03-29T12:05:00.123Z');const final=snap('2026-03-29T12:06:00Z');assert.equal(final.summary.focusCompleted,1);assert.equal(final.summary.focusMs,300000);assert.equal(helper('inspect').focus_timer_sessions.filter(r=>r.state==='completed').length,1)
 })
})
