<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__,2).'/bin/common.php';
require dirname(__DIR__,2).'/src/activity.php';
require dirname(__DIR__,2).'/src/widgets.php';
require dirname(__DIR__,2).'/src/sudoku-puzzles.php';
require dirname(__DIR__,2).'/src/sudoku.php';
$fixture=$argv[1]??'';$run=$argv[2]??'';$label=$argv[3]??'';
if(!preg_match('/^[a-f0-9]{48}$/D',$run)||!in_array($label,['a','b'],true))throw new RuntimeException('Invalid isolated race arguments.');
$root=dirname(__DIR__,2);$directory=$root.'/.local/sudoku-regression-'.substr($run,0,12);
if(realpath($fixture)!==realpath($directory.'/fixture.json')||is_link($directory))throw new RuntimeException('Only this isolated Sudoku fixture is allowed.');
$config=json_decode(file_get_contents($fixture),true,32,JSON_THROW_ON_ERROR);
if($config['APP_ENV']!=='test'||$config['DB_HOST']!=='127.0.0.1'||$config['DB_PORT']!=='3307'||$config['DB_NAME']!=='allin_sudoku_test_'.substr($run,0,12)||!hash_equals($config['TEST_RUN_ID'],$run))throw new RuntimeException('Unexpected race database.');
$db=connectDatabase($config);
if(!hash_equals((string)$db->query('SELECT run_id FROM regression_fixture_marker')->fetchColumn(),$run))throw new RuntimeException('Wrong fixture owner.');
$query=$db->prepare('SELECT * FROM users WHERE id=?');$query->execute([$config['TEST_USERS']['owner']['id']]);$user=$query->fetch();
$body=json_decode(file_get_contents($directory.'/race-'.$label.'.json'),true,32,JSON_THROW_ON_ERROR);
writePrivateFile($directory.'/ready-'.$label,$run);$until=microtime(true)+5;
while(!is_file($directory.'/ready-a')||!is_file($directory.'/ready-b')){if(microtime(true)>$until)throw new RuntimeException('Race barrier timed out.');usleep(10000);}
try{$result=mutateSudoku($db,$user,'save',$body);echo json_encode(['status'=>200,'version'=>$result['game']['version']]),PHP_EOL;}catch(HttpError $error){echo json_encode(['status'=>$error->status,'code'=>$error->errorCode]),PHP_EOL;}
