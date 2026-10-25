<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__,2).'/bin/common.php';
require dirname(__DIR__,2).'/src/activity.php';
require dirname(__DIR__,2).'/src/widgets.php';
require dirname(__DIR__,2).'/src/focus-timer.php';
$fixture=$argv[1]??'';$run=$argv[2]??'';$mode=$argv[3]??'';
if(!preg_match('/^[a-f0-9]{48}$/D',$run))throw new RuntimeException('Invalid fixture token.');
$directory=dirname(__DIR__,2).'/.local/focus-timer-regression-'.substr($run,0,12);
if(realpath($fixture)!==realpath($directory.'/fixture.json')||is_link($directory))throw new RuntimeException('Only the owned Focus fixture is permitted.');
$c=json_decode(file_get_contents($fixture),true,32,JSON_THROW_ON_ERROR);
if($c['APP_ENV']!=='test'||$c['DB_HOST']!=='127.0.0.1'||$c['DB_PORT']!=='3307'||$c['DB_NAME']!=='allin_focus_test_'.substr($run,0,12)||!hash_equals($c['TEST_RUN_ID'],$run))throw new RuntimeException('Unexpected fixture DB.');
$db=connectDatabase($c);if(!hash_equals((string)$db->query('SELECT run_id FROM regression_fixture_marker')->fetchColumn(),$run))throw new RuntimeException('Wrong fixture owner.');
$a=json_decode($argv[4]??'{}',true,32,JSON_THROW_ON_ERROR);
$q=$db->prepare('SELECT * FROM users WHERE id=?');$q->execute([$c['TEST_USERS']['owner']['id']]);$user=$q->fetch();
$now=isset($a['at'])?focusTimerMilliseconds($a['at']):null;
if($mode==='reset'){
 foreach(['focus_timer_receipts','focus_timer_installations','focus_timer_sessions'] as $t)$db->exec('DELETE FROM '.$t);
 $db->exec("DELETE FROM activity_events WHERE entity_type='focus_timer'");echo '{}';exit;
}
if($mode==='inspect'){
 $out=[];foreach(['focus_timer_installations','focus_timer_sessions','focus_timer_receipts'] as $t)$out[$t]=$db->query('SELECT * FROM '.$t)->fetchAll();$out['audit']=$db->query("SELECT entity_type,entity_id,action,before_data,after_data FROM activity_events WHERE entity_type='focus_timer'")->fetchAll();echo json_encode($out);exit;
}
if($mode==='snapshot'){echo json_encode(focusTimerSnapshot($db,$user,$now));exit;}
if($mode==='seed_history'){
 // Fixed synthetic aggregation data: >200 terminals across seven days, plus exact boundary cases.
 $insert=$db->prepare('INSERT INTO focus_timer_sessions(owner_id,phase,label,duration_ms,elapsed_ms,state,started_at,ended_at) VALUES(?,?,?,?,?,?,?,?)');
 $start=focusTimerMilliseconds('2026-03-28T01:00:00Z');
 for($i=0;$i<201;$i++){ $end=$start+($i+1)*300000;$insert->execute([$user['id'],'focus','Synthetic history',300000,300000,'completed',focusTimerDate($end-300000),focusTimerDate($end)]); }
 foreach([
 ['2026-03-29T22:59:59.999Z','focus',120000,'early'],
 ['2026-03-29T23:00:00.000Z','short',60000,'completed'],
 ['2026-03-23T23:59:59.999Z','focus',300000,'completed'],
 ['2026-03-24T00:00:00.000Z','focus',300000,'completed'],
 ['2026-03-30T12:00:00Z','focus',300000,'discarded'],
 ] as [$at,$phase,$elapsed,$state])$insert->execute([$user['id'],$phase,'Synthetic boundary',300000,$elapsed,$state,focusTimerDate(focusTimerMilliseconds($at)-$elapsed),focusTimerDate(focusTimerMilliseconds($at))]);
 echo '{}';exit;
}
if($mode==='seed_events'){
 $q=$db->prepare('INSERT INTO focus_timer_sessions(owner_id,phase,label,duration_ms,elapsed_ms,state,started_at,ended_at) VALUES(?,?,?,?,?,?,?,?)');
 foreach($a['rows'] as $r){$end=focusTimerMilliseconds($r['at']);$q->execute([$user['id'],$r['phase'],'Synthetic DST boundary',300000,$r['elapsed'],$r['state'],focusTimerDate($end-$r['elapsed']),focusTimerDate($end)]);}echo '{}';exit;
}
if($mode==='mutate'||$mode==='race'){
 if($mode==='race'){ $label=$a['label']??'';if(!in_array($label,['a','b'],true))throw new RuntimeException('Unknown race label.');writePrivateFile($directory.'/ready-'.$label,$run);$until=microtime(true)+5;while(!is_file($directory.'/ready-a')||!is_file($directory.'/ready-b')){if(microtime(true)>$until)throw new RuntimeException('Race barrier timed out.');usleep(10000);} }
 try{echo json_encode(['status'=>200,'data'=>mutateFocusTimer($db,$user,$a['action'],$a['body'],$now)]);}catch(HttpError $e){echo json_encode(['status'=>$e->status,'code'=>$e->errorCode]);}exit;
}
throw new RuntimeException('Unknown fixture operation.');
