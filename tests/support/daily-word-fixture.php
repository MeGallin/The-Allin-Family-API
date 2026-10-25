<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__,2).'/bin/common.php';
require dirname(__DIR__,2).'/src/activity.php';
require dirname(__DIR__,2).'/src/widgets.php';
require dirname(__DIR__,2).'/src/daily-word.php';
$fixture=$argv[1]??'';$run=$argv[2]??'';$mode=$argv[3]??'';
if(!preg_match('/^[a-f0-9]{48}$/D',$run))throw new RuntimeException('Invalid fixture ownership token.');
$directory=dirname(__DIR__,2).'/.local/daily-word-regression-'.substr($run,0,12);
if(realpath($fixture)!==realpath($directory.'/fixture.json')||is_link($directory))throw new RuntimeException('Only the owned Daily Word fixture is permitted.');
$config=json_decode(file_get_contents($fixture),true,32,JSON_THROW_ON_ERROR);
if($config['APP_ENV']!=='test'||$config['DB_HOST']!=='127.0.0.1'||$config['DB_PORT']!=='3307'||$config['DB_NAME']!=='allin_word_test_'.substr($run,0,12)||!hash_equals($config['TEST_RUN_ID'],$run))throw new RuntimeException('Unexpected fixture database.');
$db=connectDatabase($config);
if(!hash_equals((string)$db->query('SELECT run_id FROM regression_fixture_marker')->fetchColumn(),$run))throw new RuntimeException('Fixture database owner mismatch.');
$args=json_decode($argv[4]??'{}',true,32,JSON_THROW_ON_ERROR);
$q=$db->prepare('SELECT * FROM users WHERE id=?');$q->execute([$config['TEST_USERS']['owner']['id']]);$user=$q->fetch();
$instant=isset($args['instant'])?new DateTimeImmutable($args['instant']):null;
if($mode==='reset'){
    foreach(['daily_word_receipts','daily_word_history','daily_word_progress','daily_word_installations','daily_word_challenges'] as $table)$db->exec('DELETE FROM '.$table);
    $db->exec("DELETE FROM activity_events WHERE entity_type='daily_word'");
    $clock=dailyWordClock($instant);$answer=$args['answer']??'apple';
    if(!in_array($answer,dailyWordList('answers'),true))throw new RuntimeException('Fixture answer must be in real answer pool.');
    if(!($args['empty']??false))$db->prepare('INSERT INTO daily_word_challenges VALUES(?,?,?,?)')->execute([$clock['currentDay'],$answer,'esdb-2026.02.25-allin-1',(new DateTimeImmutable($clock['nextMidnight']))->format('Y-m-d H:i:s')]);
    echo json_encode($clock);exit;
}
if($mode==='inspect'){
    $out=[];foreach(['daily_word_installations','daily_word_challenges','daily_word_progress','daily_word_receipts','daily_word_history'] as $table)$out[$table]=$db->query('SELECT * FROM '.$table)->fetchAll();
    $out['audit']=$db->query("SELECT entity_type,action,before_data,after_data FROM activity_events WHERE entity_type='daily_word'")->fetchAll();
    echo json_encode($out);exit;
}
if($mode==='mutate'||$mode==='race'){
    if($mode==='race'){
        $label=$args['label']??'';if(!in_array($label,['a','b'],true))throw new RuntimeException('Unknown race label.');
        writePrivateFile($directory.'/ready-'.$label,$run);$until=microtime(true)+5;
        while(!is_file($directory.'/ready-a')||!is_file($directory.'/ready-b')){if(microtime(true)>$until)throw new RuntimeException('Race barrier timed out.');usleep(10000);}
    }
    try{echo json_encode(['status'=>200,'data'=>$args['action']==='challenge'?dailyWordChallenge($db,$instant):mutateDailyWord($db,$user,$args['action'],$args['body'],$instant)]);}catch(HttpError $e){echo json_encode(['status'=>$e->status,'code'=>$e->errorCode]);}exit;
}
throw new RuntimeException('Unknown isolated fixture operation.');
