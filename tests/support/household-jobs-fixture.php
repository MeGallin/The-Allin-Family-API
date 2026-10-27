<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__,2).'/bin/common.php';
require dirname(__DIR__,2).'/src/activity.php';
require dirname(__DIR__,2).'/src/widgets.php';
require dirname(__DIR__,2).'/src/household-jobs.php';
$fixture=$argv[1]??''; $run=$argv[2]??'';
$directory=dirname(__DIR__,2).'/.local/regression';
if (!preg_match('/^[a-f0-9]{48}$/D',$run)||realpath($fixture)!==realpath($directory.'/fixture.json')||is_link($directory)) throw new RuntimeException('Only the owned disposable regression fixture is permitted.');
$config=json_decode(file_get_contents($fixture),true,32,JSON_THROW_ON_ERROR);
if($config['APP_ENV']!=='test'||$config['DB_HOST']!=='127.0.0.1'||$config['DB_PORT']!=='3307'||$config['DB_NAME']!=='theallinfamily_regression_test'||!hash_equals($config['TEST_RUN_ID'],$run))throw new RuntimeException('Unexpected fixture DB.');
$db=connectDatabase($config);
if(!hash_equals((string)$db->query('SELECT run_id FROM regression_fixture_marker')->fetchColumn(),$run))throw new RuntimeException('Wrong fixture owner.');
$args=json_decode($argv[3]??'{}',true,32,JSON_THROW_ON_ERROR);
$role=$args['actor']??'owner';if(!in_array($role,['owner','member','admin'],true))throw new RuntimeException('Unknown synthetic actor.');
$q=$db->prepare('SELECT * FROM users WHERE id=?');$q->execute([$config['TEST_USERS'][$role]['id']]);$user=$q->fetch();
$delay=($args['goAt']??0)-microtime(true);if($delay>3)throw new RuntimeException('Invalid race clock.');if($delay>0)usleep((int)($delay*1000000));
try { echo json_encode(['status'=>200,'data'=>mutateHouseholdJob($db,$user,$args['action'],$args['body'])]); }
catch(HttpError $error){echo json_encode(['status'=>$error->status,'code'=>$error->errorCode]);}
