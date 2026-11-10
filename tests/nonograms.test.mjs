import { before,after,describe,it } from 'node:test'
import assert from 'node:assert/strict'
import {execFileSync} from 'node:child_process'
import {startEnvironment,expectStatus as status,key} from './support/environment.mjs'
const php='C:/xampp/php/php.exe'
const bank=JSON.parse(execFileSync(php,['-r',"require 'src/nonograms.php'; echo json_encode(nonogramBank());"],{encoding:'utf8',windowsHide:true}))
const clue=s=>(s.match(/1+/g)||['']).map(r=>r.length)
// Independent binary enumeration of every possible line; no production clues/solver are used.
function prove(rows) {
 const n=rows.length,cols=Array.from({length:n},(_,c)=>rows.map(r=>r[c]).join(''))
 assert(rows.every(row=>new RegExp(`^[01]{${n}}$`).test(row)))
 const candidates=[...rows,...cols].map(s=>Array.from({length:2**n},(_,m)=>m.toString(2).padStart(n,'0')).filter(line=>JSON.stringify(clue(line))===JSON.stringify(clue(s))))
 const at=(l,o)=>l<n?l*n+o:o*n+l-n
 // Line overlap deduction provides a human-style no-guess solving certificate.
 const deductions=Array(n*n).fill('?');let changed=true
 while(changed){changed=false;for(let l=0;l<2*n;l++){
   const matching=candidates[l].filter(v=>[...v].every((x,o)=>deductions[at(l,o)]==='?'||deductions[at(l,o)]===x));assert(matching.length)
   for(let o=0;o<n;o++){const i=at(l,o),v=matching[0][o];if(deductions[i]==='?'&&matching.every(c=>c[o]===v)){deductions[i]=v;changed=true}}
 }}
 assert.equal(deductions.join(''),rows.join(''),'all cells deduced from clues without guessing')
 // Independently exhaust row assignments with column-prefix pruning, counting up to two solutions.
 let count=0
 function visit(chosen) {
  if(count>=2)return
  if(chosen.length===n){count++;return}
  for(const row of candidates[chosen.length]) {
   const next=[...chosen,row]
   if(candidates.slice(n).every((columnOptions,c)=>columnOptions.some(v=>next.every((r,i)=>r[c]===v[i]))))visit(next)
  }
 }
 visit([]);assert.equal(count,1,'exactly one solution')
 return {n,rowClues:rows.map(clue),columnClues:cols.map(clue)}
}
let env
before(async()=>{env=await startEnvironment({suite:'sudoku'})})
after(async()=>{await env?.stop()})
const read=async(client=env.owner)=>status(await client.request('/nonograms'),200)
const post=async(action,body,expected=200,client=env.owner)=>status(await client.request('/nonograms/'+action,body),expected)
const body=(game,extra={})=>({id:game.id,puzzleId:game.puzzleId,version:game.version,requestKey:key(),...extra})
async function install(id='sprout-5'){const s=await read();return post('install',{version:s.installationVersion,nextPuzzleId:id,requestKey:key()})}
describe('Nonograms isolated API and original puzzle proof',()=>{
 for(const [id,p] of Object.entries(bank))it(id+' has exact clues, one solution and no-guess deduction',async()=>{
  const proof=prove(p.rows),catalogue=(await read()).catalogue.find(p=>p.puzzleId===id)
  assert.equal(catalogue.size,proof.n);assert.deepEqual(catalogue.rowClues,proof.rowClues);assert.deepEqual(catalogue.columnClues,proof.columnClues)
 })
 it('requires authentication/CSRF and exposes only the signed-in account',async()=>{
  status(await env.client().request('/nonograms'),401)
  status(await env.owner.request('/nonograms/install',{version:0,nextPuzzleId:'sprout-5',requestKey:key()},{'X-CSRF-Token':'invalid'}),403)
  const s=await install();assert(s.game);assert.equal(s.ownerId,env.users.owner.id)
  const privateCells='1'+'0'.repeat(24)
  const saved=await post('save',body(s.game,{cells:privateCells,assisted:false}))
  for(const client of [env.member,env.admin]) {const other=await read(client);assert.equal(other.game,null);assert.deepEqual(other.played,[]);assert.equal(other.ownerId,client.user.id)}
  await post('save',body(saved.game,{cells:privateCells,assisted:false}),409,env.member)
  const activity=status(await env.admin.request('/admin/activity'),200)
  assert(!JSON.stringify(activity).includes('nonograms'),'No private puzzle activity in admin read model')
 })
 it('guards stale saves, returns immutable receipts and rejects altered retries',async()=>{
  let s=await read(),g=s.game
  const first=body(g,{cells:'2'+'0'.repeat(24),assisted:true})
  const accepted=await post('save',first)
  const second=await post('save',body(accepted.game,{cells:'0'.repeat(25),assisted:false}))
  assert.equal(second.game.assisted,true,'Undo cannot hide hint assistance')
  assert.deepEqual(await post('save',first),accepted,'Old receipt remains immutable')
  assert.equal((await read()).game.version,second.game.version,'Retry cannot rewind stored state')
  await post('save',{...first,cells:'1'.repeat(25)},422)
  await post('save',body(g,{cells:'0'.repeat(25),assisted:false}),409)
  await post('save',body(second.game,{cells:'0'.repeat(24),assisted:false}),422)
 })
 it('completes with correct filled squares while empties stay unknown; reset/new and replay stay honest',async()=>{
  let s=await read(),g=s.game
  const complete=await post('save',body(g,{cells:bank[g.puzzleId].rows.join(''),assisted:g.assisted}))
  assert(complete.game.completedAt)
  const reset=await post('restart',body(complete.game));assert.equal(reset.game.completedAt,null);assert.equal(reset.game.cells,'0'.repeat(25));assert.equal(reset.game.assisted,false)
  await post('restart',body(complete.game),409)
  const fresh=await post('new',body(reset.game,{nextPuzzleId:'tree-10'}));assert.equal(fresh.game.cells.length,100);assert.equal(fresh.game.replay,false)
  const replay=await post('new',body(fresh.game,{nextPuzzleId:'sprout-5'}));assert.equal(replay.game.replay,true);assert.equal(replay.played.length,2)
 })
 it('removal/reinstall never accepts old saves or repeats an old removal against a new board',async()=>{
  const s=await read(),remove=body(s.game),removed=await post('remove',remove)
  assert.equal(removed.game,null)
  const reinstalled=await install('cup-5');assert.equal(reinstalled.game.cells,'0'.repeat(25));assert.equal(reinstalled.played.length,3)
  await post('save',body(s.game,{cells:s.game.cells,assisted:false}),409)
  assert.deepEqual(await post('remove',remove),removed)
  assert.equal((await read()).game.puzzleId,'cup-5')
 })
})
