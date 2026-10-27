import {before,after,it} from 'node:test';
import assert from 'node:assert/strict';
import {startEnvironment,expectStatus as ok,key} from './support/environment.mjs';
let env; before(async()=>env=await startEnvironment()); after(async()=>await env?.stop());
const draft=(scope='family')=>({scope,title:'Jobs regression marker',note:'Private marker',area:'Kitchen',targetDate:'2026-01-31',repeat:'monthly',assigneeId:null,requestKey:key()});
const write=(actor,action,job,extra={})=>actor.request('/household-jobs/'+action,{id:job.id,version:job.version,requestKey:key(),...extra});
it('enforces privacy, permissions, CAS claims, recurring history and shared ordering',async()=>{
 const privateJob=ok(await env.owner.request('/household-jobs/add',draft('mine')),200).jobs.find(j=>j.scope==='mine');
 for(const actor of [env.member,env.admin]){assert.equal(ok(await actor.request('/household-jobs'),200).jobs.some(j=>j.id===privateJob.id),false);ok(await write(actor,'complete',privateJob),404);}
 let shared=ok(await env.owner.request('/household-jobs/add',draft()),200).jobs.find(j=>j.scope==='family');
 ok(await write(env.member,'edit',shared,draft()),403);
 let state=ok(await write(env.member,'claim',shared),200); let claimed=state.jobs.find(j=>j.id===shared.id);
 ok(await write(env.owner,'claim',shared),409);
 state=ok(await write(env.member,'complete',claimed),200); let next=state.jobs.find(j=>j.id===shared.id);
 assert.equal(next.targetDate,'2026-02-28'); assert.equal(next.done,false); assert.equal(next.history.filter(h=>h.action==='complete').length,1);
 state=ok(await write(env.member,'reopen',next),200); next=state.jobs.find(j=>j.id===shared.id); assert.equal(next.targetDate,'2026-01-31'); assert.equal(next.history.filter(h=>h.action==='reopen').length,1);
 const other=ok(await env.owner.request('/household-jobs/add',{...draft(),title:'Another',repeat:'none',targetDate:null}),200).jobs.find(j=>j.title==='Another');
 state=ok(await env.member.request('/household-jobs/reorder',{scope:'family',ids:[other.id,shared.id],requestKey:key()}),200);
 assert.deepEqual(state.jobs.filter(j=>j.scope==='family').map(j=>j.id),[other.id,shared.id]);
 assert.deepEqual(ok(await env.owner.request('/household-jobs'),200).jobs.filter(j=>j.scope==='family').map(j=>j.id),[other.id,shared.id]);
 ok(await env.member.request('/household-jobs/reorder',{scope:'family',ids:[privateJob.id,shared.id],requestKey:key()}),409);
 ok(await write(env.member,'remove',next),403); ok(await write(env.owner,'remove',next),200);
 assert.equal(ok(await env.owner.request('/household-jobs'),200).jobs.some(j=>j.id===shared.id),false);
 const report=JSON.stringify(ok(await env.admin.request('/admin/activity'),200)); assert.equal(report.includes('Private marker'),false);
});

it('validates dates/text, authenticates writes and retains exact idempotent receipts',async()=>{
 ok(await env.client().request('/household-jobs'),401);
 ok(await env.owner.request('/household-jobs/add',draft(),{'X-CSRF-Token':''}),403);
 for(const extra of [{targetDate:'2026-02-29'},{targetDate:'bad'},{title:''},{note:'x'.repeat(2001)},{repeat:'daily'},{assigneeId:999999},{scope:'other'}])ok(await env.owner.request('/household-jobs/add',{...draft(),...extra}),422);
 const body={...draft(),title:'Idempotency marker',targetDate:null,repeat:'weekly'};
 const once=ok(await env.owner.request('/household-jobs/add',body),200).jobs.find(j=>j.title===body.title);
 const twice=ok(await env.owner.request('/household-jobs/add',body),200).jobs.filter(j=>j.title===body.title);assert.equal(twice.length,1);assert.equal(twice[0].id,once.id);
 ok(await env.owner.request('/household-jobs/add',{...body,title:'Changed payload'}),422);
 const completion={id:once.id,version:once.version,requestKey:key()};
 let state=ok(await env.owner.request('/household-jobs/complete',completion),200);let job=state.jobs.find(j=>j.id===once.id);assert.equal(job.targetDate,null);assert.equal(job.done,false);
 state=ok(await env.owner.request('/household-jobs/complete',completion),200);assert.equal(state.jobs.find(j=>j.id===once.id).history.filter(h=>h.action==='complete').length,1);
 const removed=ok(await write(env.owner,'remove',job),200);assert.equal(removed.archive.find(j=>j.id===job.id).history.some(h=>h.action==='complete'),true);
});
it('serialises parallel claims to one winner and applies the latest successful shared order',async()=>{
 const {execFile}=await import('node:child_process');const {promisify}=await import('node:util');const run=promisify(execFile);
 const actorRun=async(actor,action,body,goAt)=>JSON.parse((await run('C:/xampp/php/php.exe',['tests/support/household-jobs-fixture.php',env.fixtureFile,env.runId,JSON.stringify({actor,action,body,goAt})],{windowsHide:true})).stdout);
 let state=ok(await env.owner.request('/household-jobs/add',{...draft(),title:'Parallel marker',repeat:'none',targetDate:null}),200);const job=state.jobs.find(j=>j.title==='Parallel marker');const at=Date.now()/1000+1;
 const results=await Promise.all(['owner','member'].map(actor=>actorRun(actor,'claim',{id:job.id,version:job.version,requestKey:key()},at)));
 assert.deepEqual(results.map(r=>r.status).sort(),[200,409]);
 state=ok(await env.owner.request('/household-jobs'),200);const ids=state.jobs.filter(j=>j.scope==='family'&&!j.done).map(j=>j.id);
 const orders=[ids.toReversed(),[...ids.slice(1),ids[0]]];const next=Date.now()/1000+1;
 const ordered=await Promise.all(orders.map((ids,i)=>actorRun(i?'member':'owner','reorder',{scope:'family',ids,requestKey:key()},next)));
 assert.deepEqual(ordered.map(r=>r.status),[200,200]);
 const final=ok(await env.owner.request('/household-jobs'),200).jobs.filter(j=>j.scope==='family'&&!j.done).map(j=>j.id);
 assert.ok(orders.some(order=>JSON.stringify(order)===JSON.stringify(final)));
 // A later retry of an old accepted request is acknowledged without overwriting a newer move.
 const first={scope:'family',ids:orders[0],requestKey:key()};ok(await env.owner.request('/household-jobs/reorder',first),200);
 ok(await env.member.request('/household-jobs/reorder',{scope:'family',ids:orders[1],requestKey:key()}),200);const retried=ok(await env.owner.request('/household-jobs/reorder',first),200);
 assert.deepEqual(retried.jobs.filter(j=>j.scope==='family'&&!j.done).map(j=>j.id),orders[1]);
});
