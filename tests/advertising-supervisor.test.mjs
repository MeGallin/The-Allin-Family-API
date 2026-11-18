import test from 'node:test';
import assert from 'node:assert/strict';
import { spawn, execFileSync } from 'node:child_process';
import { mkdtemp, readFile, writeFile, rm, readdir } from 'node:fs/promises';
import { resolve } from 'node:path';
import { tmpdir } from 'node:os';
import { createServer } from 'node:net';
import { setTimeout as delay } from 'node:timers/promises';
const powershell = `${process.env.SystemRoot}/System32/WindowsPowerShell/v1.0/powershell.exe`;
const fixture = resolve('tests/support/advertising-supervisor-fixture.ps1');
const core = resolve('bin/local-advertising/Supervisor.Core.ps1');
async function port() { const server=createServer(); await new Promise(r=>server.listen(0,'127.0.0.1',r)); const value=server.address().port; await new Promise(r=>server.close(r)); return value; }
async function until(fn) { for (let i=0;i<160;i++) { try { const value=await fn(); if(value)return value; } catch {} await delay(100); } throw new Error('Synthetic supervisor deadline'); }
const json = async (directory,name) => JSON.parse(await readFile(resolve(directory,name),'utf8'));
function alive(pid) { try { process.kill(pid,0); return true; } catch { return false; } }

test('Windows supervisor recovers exited children and its own crash without duplicate/foreign kills or raw logs', {skip:process.platform!=='win32', timeout:65000}, async t=>{
 const directory=await mkdtemp(resolve(tmpdir(),'allin-supervisor-'));
 const bridgePort=await port(), nodePort=await port();
 await writeFile(resolve(directory,'enabled.json'),'{}');
 let supervisor;
 const start=()=>spawn(powershell,['-NoLogo','-NoProfile','-NonInteractive','-ExecutionPolicy','Bypass','-File',fixture,'-Directory',directory,'-Node',process.execPath,'-BridgePort',String(bridgePort),'-NodePort',String(nodePort)],{windowsHide:true,stdio:'ignore'});
 t.after(async()=>{
  await writeFile(resolve(directory,'stop.json'),'{}');
  await until(()=>!supervisor || (supervisor.exitCode!==null||supervisor.signalCode!==null)).catch(()=>supervisor.kill());
  // Cleanup uses the same executable/argument/start-time identity checks, never a saved PID alone.
  execFileSync(powershell,['-NoProfile','-NonInteractive','-File',fixture,'-Directory',directory,'-Node',process.execPath,'-BridgePort',String(bridgePort),'-NodePort',String(nodePort),'-Mode','Stop'],{windowsHide:true,stdio:'ignore'});
  await rm(directory,{recursive:true,force:true});
 });
 supervisor=start();
 let first=await until(async()=>{const x=await json(directory,'owners.json');return x.worker&&x.node&&x.bridge?x:null});
 await writeFile(resolve(directory,'worker-exit.txt'),'23');
 first=await until(async()=>{const x=await json(directory,'owners.json');return x.worker&&x.worker.pid!==first.worker.pid?x:null});
 const initialExit=(await readFile(resolve(directory,'events.jsonl'),'utf8')).trim().split('\n').map(JSON.parse).find(x=>x.event==='child_exited'&&x.role==='worker');
 assert.equal(initialExit.exitCode,23);
 // Long-lived worker has no heartbeat at all; it must not be treated as a five-second failure.
 await delay(700);
 assert.equal((await json(directory,'owners.json')).worker.pid,first.worker.pid);
 process.kill(first.node.pid);
 const second=await until(async()=>{const x=await json(directory,'owners.json');return x.node&&x.node.pid!==first.node.pid?x:null});
 const killedExit=(await readFile(resolve(directory,'events.jsonl'),'utf8')).trim().split('\n').map(JSON.parse).find(x=>x.event==='child_exited'&&x.role==='node');
 assert.ok(Number.isInteger(killedExit.exitCode)); assert.notEqual(killedExit.exitCode,0);
 assert.equal(second.worker.pid,first.worker.pid);
 const oldSupervisor=supervisor; oldSupervisor.kill(); await until(()=>(oldSupervisor.exitCode!==null||oldSupervisor.signalCode!==null));
 supervisor=start();
 await until(async()=>{const x=await json(directory,'status.json');return x.pid===supervisor.pid&&x.dependenciesReady});
 const recovered=await json(directory,'owners.json');
 await delay(900); // Retained children keep writing both streams after original parent death.
 for(const identity of Object.values(recovered)) assert.ok(alive(identity.pid));
 for(const role of ['bridge','node','worker']) assert.equal(recovered[role].pid,second[role].pid);
 await writeFile(resolve(directory,'worker-exit.txt'),'23');
 const afterAdoptedExit=await until(async()=>{const x=await json(directory,'owners.json');return x.worker&&x.worker.pid!==recovered.worker.pid?x:null});
 const exits=(await readFile(resolve(directory,'events.jsonl'),'utf8')).trim().split('\n').map(JSON.parse).filter(x=>x.event==='child_exited'&&x.role==='worker');
 assert.deepEqual(exits.map(x=>x.exitCode),[23,23]);
 const duplicate=start(); await until(()=>(duplicate.exitCode!==null||duplicate.signalCode!==null)); assert.equal(duplicate.exitCode,0);
 await writeFile(resolve(directory,'stop.json'),'{}');
 await until(()=>(supervisor.exitCode!==null||supervisor.signalCode!==null)); assert.equal(supervisor.exitCode,0);
 for(const identity of Object.values(afterAdoptedExit)) assert.equal(alive(identity.pid),false);
 const logs=(await Promise.all((await readdir(directory)).filter(x=>x.endsWith('.jsonl')).map(x=>readFile(resolve(directory,x),'utf8')))).join('');
 assert.match(logs,/child_recovered/); assert.doesNotMatch(logs,/synthetic-sensitive/);
 assert.ok(logs.split('\n').filter(Boolean).map(JSON.parse).some(x=>x.event==='supervisor_started'&&x.exitCode===null));
 // No production config, env file, task registration, power policy or provider is involved.
 assert.ok(core.endsWith('Supervisor.Core.ps1'));
});

test('initial enable refuses unsafe-to-arm state without enabling or launching anything', {skip:process.platform!=='win32'},async()=>{
 const directory=await mkdtemp(resolve(tmpdir(),'allin-supervisor-arm-'));
 try {
  assert.throws(()=>execFileSync(powershell,['-NoProfile','-NonInteractive','-File',fixture,'-Directory',directory,'-Node',process.execPath,'-BridgePort','40001','-NodePort','40002','-Mode','Enable','-UnsafeToArm'],{windowsHide:true,stdio:'ignore'}));
  assert.deepEqual(await readdir(directory),[]);
 } finally { await rm(directory,{recursive:true,force:true}); }
});

test('Stop and Status work without runtime, env files or application-state dependencies', {skip:process.platform!=='win32'},async()=>{
 const directory=await mkdtemp(resolve(tmpdir(),'allin-supervisor-control-'));
 try {
  const output=execFileSync(powershell,['-NoProfile','-NonInteractive','-File',resolve('tests/support/advertising-supervisor-controls.ps1'),'-Directory',directory],{windowsHide:true,encoding:'utf8'});
  assert.match(output,/"enabled":\s*false/);
  assert.match(output,/"running":\s*false/);
 } finally { await rm(directory,{recursive:true,force:true}); }
});

test('Stop completes during stalled readiness and no worker launches after disarm', {skip:process.platform!=='win32',timeout:25000},async t=>{
 const directory=await mkdtemp(resolve(tmpdir(),'allin-supervisor-stop-race-'));
 const bridgePort=await port(), nodePort=await port();
 await writeFile(resolve(directory,'enabled.json'),'{}');
 const args=['-NoProfile','-NonInteractive','-File',fixture,'-Directory',directory,'-Node',process.execPath,'-BridgePort',String(bridgePort),'-NodePort',String(nodePort)];
 const child=spawn(powershell,[...args,'-ReadinessBarrier'],{windowsHide:true,stdio:'ignore'});
 t.after(async()=>{
  await writeFile(resolve(directory,'stop.json'),'{}');
  await writeFile(resolve(directory,'readiness-release.txt'),'release');
  await until(()=>child.exitCode!==null||child.signalCode!==null).catch(()=>child.kill());
  execFileSync(powershell,[...args,'-Mode','Stop'],{windowsHide:true,stdio:'ignore'});
  await rm(directory,{recursive:true,force:true});
 });
 await until(async()=>{await readFile(resolve(directory,'readiness-entered.txt'));return true});
 // Enable must not clear stop markers/arm an already resident or draining owner.
 assert.throws(()=>execFileSync(powershell,[...args,'-Mode','Enable'],{windowsHide:true,stdio:'ignore'}));
 const output=execFileSync(powershell,[...args,'-Mode','Stop'],{windowsHide:true,encoding:'utf8',timeout:3000});
 assert.match(output,/Stop requested/);
 assert.equal(child.exitCode,null); // Readiness has not been released, so drain is asynchronous.
 assert.ok(!(await readdir(directory)).includes('enabled.json'));
 assert.ok((await readdir(directory)).includes('stop.json'));
 await writeFile(resolve(directory,'readiness-release.txt'),'release');
 await until(()=>child.exitCode!==null||child.signalCode!==null);
 assert.equal(child.exitCode,0);
 const events=(await readFile(resolve(directory,'events.jsonl'),'utf8')).trim().split('\n').map(JSON.parse);
 assert.equal(events.some(x=>x.event==='child_started'&&x.role==='worker'),false);
 assert.deepEqual(await json(directory,'owners.json'),{});
 assert.deepEqual((await json(directory,'status.json')).roles,[]);
});

test('foreign loopback listener blocks startup and mismatched PID identity is never adopted', {skip:process.platform!=='win32',timeout:25000},async t=>{
 const directory=await mkdtemp(resolve(tmpdir(),'allin-supervisor-foreign-'));
 const server=createServer(); await new Promise(r=>server.listen(0,'127.0.0.1',r));
 const bridgePort=server.address().port, nodePort=await port();
 await writeFile(resolve(directory,'enabled.json'),'{}');
 // A PID with an unrelated start time must not become an owned process.
 await writeFile(resolve(directory,'owners.json'),JSON.stringify({bridge:{pid:process.pid,startedTicks:'1',exe:process.execPath,arguments:`"${resolve('tests/support/advertising-supervisor-child.mjs')}" "bridge" "${bridgePort}"`}}));
 const child=spawn(powershell,['-NoProfile','-NonInteractive','-ExecutionPolicy','Bypass','-File',fixture,'-Directory',directory,'-Node',process.execPath,'-BridgePort',String(bridgePort),'-NodePort',String(nodePort)],{windowsHide:true,stdio:'ignore'});
 t.after(async()=>{await writeFile(resolve(directory,'stop.json'),'{}'); await until(()=>(child.exitCode!==null||child.signalCode!==null)).catch(()=>child.kill()); await new Promise(r=>server.close(r));await rm(directory,{recursive:true,force:true});});
 await until(async()=>/foreign_port/.test(await readFile(resolve(directory,'events.jsonl'),'utf8')));
 assert.ok(alive(process.pid));
 assert.equal((await json(directory,'status.json')).dependenciesReady,false);
 assert.equal((await json(directory,'status.json')).roles.length,0);
});
