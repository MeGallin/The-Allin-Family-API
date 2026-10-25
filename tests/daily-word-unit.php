<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
require dirname(__DIR__).'/src/daily-word.php';
$checks=0;
function checkWord(bool $value,string $message):void{global $checks;if(!$value)throw new RuntimeException($message);$checks++;}
checkWord(dailyWordScore('allot','plant')===['present','correct','absent','absent','correct'],'Repeated L exact allocation must precede presence.');
checkWord(dailyWordScore('allee','apple')===['correct','present','absent','absent','correct'],'Surplus repeated L/E must be absent after exact matches.');
checkWord(dailyWordScore('apple','apple')===array_fill(0,5,'correct'),'Five exact letters.');
checkWord(dailyWordScore('zzzzz','apple')===array_fill(0,5,'absent'),'All absent.');
$manifest=json_decode(file_get_contents(dirname(__DIR__).'/src/daily-word-data/provenance.json'),true,32,JSON_THROW_ON_ERROR);
foreach($manifest['files'] as $file=>$meta)checkWord(hash_file('sha256',dirname(__DIR__).'/src/daily-word-data/'.$file)===$meta['sha256'],'Pinned asset hash: '.$file);
$answers=dailyWordList('answers');$guesses=dailyWordList('guesses');
checkWord(count($answers)===696&&count($guesses)===6939,'Pinned dictionary sizes.');
checkWord(array_diff($answers,$guesses)===[],'Every answer must be accepted.');
checkWord(count(array_filter($guesses,fn($w)=>preg_match('/^[a-z]{5}$/D',$w)!==1))===0,'Only five ASCII letters.');
foreach([
 ['2026-03-28T12:00:00Z','2026-03-29T00:00:00Z',12],
 ['2026-03-29T00:00:00Z','2026-03-29T23:00:00Z',23],
 ['2026-10-25T00:00:00Z','2026-10-26T00:00:00Z',24],
 ['2026-10-24T23:00:00Z','2026-10-26T00:00:00Z',25],
] as [$start,$midnight,$hours]){
 $clock=dailyWordClock(new DateTimeImmutable($start));checkWord($clock['nextMidnight']===$midnight,'London calendar midnight at '.$start);checkWord((strtotime($midnight)-strtotime($start))/3600===$hours,'DST day length at '.$start);
}
echo json_encode(['passed'=>$checks]),PHP_EOL;
