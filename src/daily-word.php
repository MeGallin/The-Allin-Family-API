<?php
declare(strict_types=1);

function dailyWordClock(?DateTimeImmutable $instant=null): array
{
    $now = ($instant ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone('Europe/London'));
    return ['currentDay'=>$now->format('Y-m-d'), 'serverTime'=>$now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'), 'nextMidnight'=>$now->modify('tomorrow')->setTime(0,0)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z')];
}
function dailyWordList(string $kind): array
{
    static $lists = [];
    return $lists[$kind] ??= file(__DIR__ . '/daily-word-data/' . $kind . '.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
}
function dailyWordChallenge(PDO $db,?DateTimeImmutable $instant=null): array
{
    $clock = dailyWordClock($instant);
    // Serialise challenge creation across all accounts, without an exposed date-to-answer algorithm.
    $db->beginTransaction();
    try {
        $db->query('SELECT id FROM users ORDER BY id LIMIT 1 FOR UPDATE')->fetch();
        $clock = dailyWordClock($instant);
        $query=$db->prepare('SELECT * FROM daily_word_challenges WHERE challenge_date=?'); $query->execute([$clock['currentDay']]); $challenge=$query->fetch();
        if (!$challenge) {
            $recent=$db->query('SELECT answer FROM daily_word_challenges ORDER BY challenge_date DESC LIMIT 30')->fetchAll(PDO::FETCH_COLUMN);
            $choices=array_values(array_diff(dailyWordList('answers'),$recent));
            $answer=$choices[random_int(0,count($choices)-1)];
            $expiry=(new DateTimeImmutable($clock['nextMidnight']))->format('Y-m-d H:i:s');
            $db->prepare('INSERT INTO daily_word_challenges(challenge_date,answer,list_version,expires_at) VALUES(?,?,?,?)')->execute([$clock['currentDay'],$answer,'esdb-2026.02.25-allin-1',$expiry]);
            $query->execute([$clock['currentDay']]); $challenge=$query->fetch();
        }
        $db->commit(); return $challenge;
    } catch(Throwable $error) { if($db->inTransaction())$db->rollBack(); throw $error; }
}
function dailyWordInstallation(PDO $db,int $owner): array|false
{
    $q=$db->prepare('SELECT * FROM daily_word_installations WHERE owner_id=?'); $q->execute([$owner]); return $q->fetch();
}
function dailyWordProgress(PDO $db,int $owner,string $day): array|false
{
    $q=$db->prepare('SELECT * FROM daily_word_progress WHERE owner_id=? AND challenge_date=?');$q->execute([$owner,$day]);return $q->fetch();
}
function dailyWordPublicProgress(array|false $row): array
{
    return ['guesses'=>$row ? json_decode($row['guesses'],true,16,JSON_THROW_ON_ERROR):[], 'revision'=>$row?(int)$row['revision']:0,'finished'=>$row && $row['finished_at']!==null,'solved'=>$row?(bool)$row['solved']:false,'shared'=>$row && $row['shared_at']!==null];
}
function dailyWordSnapshot(PDO $db,array $user,int $rolloverRetry=0): array
{
    $challenge=dailyWordChallenge($db);$owner=(int)$user['id'];$install=dailyWordInstallation($db,$owner);
    $q=$db->prepare('SELECT challenge_date,revision,shared_at,JSON_LENGTH(guesses) AS attempts,finished_at,solved FROM daily_word_progress WHERE owner_id=? AND challenge_date>=? AND challenge_date<=? ORDER BY challenge_date');
    $since=(new DateTimeImmutable($challenge['challenge_date']))->modify('-6 days')->format('Y-m-d');$q->execute([$owner,$since,$challenge['challenge_date']]);
    $week=array_map(static fn($r)=>['date'=>$r['challenge_date'],'revision'=>(int)$r['revision'],'shared'=>$r['shared_at']!==null,'attempts'=>(int)$r['attempts'],'finished'=>$r['finished_at']!==null,'solved'=>(bool)$r['solved']],$q->fetchAll());
    $progress=dailyWordPublicProgress(dailyWordProgress($db,$owner,$challenge['challenge_date']));
    $clock=dailyWordClock();
    if($clock['currentDay']!==$challenge['challenge_date']) {
        if($rolloverRetry===0)return dailyWordSnapshot($db,$user,1);
        throw new HttpError(409,'The London day changed. Refresh to open today’s word.','word_day_changed');
    }
    return ['ownerId'=>$owner,'installationVersion'=>$install?(int)$install['version']:0,'installed'=>$install && $install['deleted_at']===null,'day'=>$challenge['challenge_date'],'clock'=>$clock,'progress'=>$progress,'week'=>$week];
}
function dailyWordResults(PDO $db): array
{
    $clock=dailyWordClock();$q=$db->prepare('SELECT p.challenge_date,JSON_LENGTH(p.guesses) AS attempts,p.solved,p.owner_id,u.nickname,u.display_name FROM daily_word_progress p JOIN users u ON u.id=p.owner_id WHERE p.shared_at IS NOT NULL AND p.finished_at IS NOT NULL AND p.challenge_date>=? AND p.challenge_date<=? ORDER BY p.challenge_date DESC,p.shared_at');
    $q->execute([(new DateTimeImmutable($clock['currentDay']))->modify('-6 days')->format('Y-m-d'),$clock['currentDay']]);
    return ['clock'=>$clock,'results'=>array_map(static fn($r)=>['date'=>$r['challenge_date'],'ownerId'=>(int)$r['owner_id'],'name'=>familyDisplayName($r),'attempts'=>(int)$r['attempts'],'solved'=>(bool)$r['solved']],$q->fetchAll())];
}
function dailyWordScore(string $guess,string $answer): array
{
    $marks=array_fill(0,5,'absent');$left=[];
    for($i=0;$i<5;$i++) {if($guess[$i]===$answer[$i])$marks[$i]='correct';else $left[$answer[$i]]=($left[$answer[$i]]??0)+1;}
    for($i=0;$i<5;$i++)if($marks[$i]!=='correct' && ($left[$guess[$i]]??0)>0){$marks[$i]='present';$left[$guess[$i]]--;}
    return $marks;
}
function mutateDailyWord(PDO $db,array $user,string $action,array $body,?DateTimeImmutable $instant=null): array
{
    if(!in_array($action,['install','remove','guess','share','withdraw'],true))throw new HttpError(404,'Unknown Daily Word action.');
    $owner=(int)$user['id'];$key=widgetRequestKey($user,$body,'daily-word');
    // Stable field order is irrelevant: an exact logical retry has the same typed payload.
    ksort($body);$signature=hash('sha256',$action.json_encode($body,JSON_THROW_ON_ERROR));
    $challenge=$action==='install'?dailyWordChallenge($db,$instant):null;
    $db->beginTransaction();
    try {
        $db->prepare('SELECT id FROM users WHERE id=? FOR UPDATE')->execute([$owner]);
        $receipt=$db->prepare('SELECT request_hash,response FROM daily_word_receipts WHERE owner_id=? AND request_key=?');$receipt->execute([$owner,$key]);$prior=$receipt->fetch();
        if($prior){if(!hash_equals($prior['request_hash'],$signature))throw new HttpError(422,'Use a fresh request after changing your action.');$db->commit();return json_decode($prior['response'],true,32,JSON_THROW_ON_ERROR);}
        $install=dailyWordInstallation($db,$owner);
        $changed=false;
        if(!is_int($body['version']??null) || $body['version']!==($install?(int)$install['version']:0))throw new HttpError(409,'Your installation changed. Refresh before continuing.','word_conflict');
        if($action==='install'){
            $changed=!$install || $install['deleted_at']!==null;
            if(!$install)$db->prepare('INSERT INTO daily_word_installations(owner_id) VALUES(?)')->execute([$owner]);
            elseif($install['deleted_at']!==null)$db->prepare('UPDATE daily_word_installations SET deleted_at=NULL,version=version+1 WHERE owner_id=?')->execute([$owner]);
        }else{
            if(!$install || $install['deleted_at']!==null)throw new HttpError(409,'Install Daily Word before continuing.','word_conflict');
            if($action==='remove'){
                $changed=true;
                $db->prepare('UPDATE daily_word_installations SET deleted_at=UTC_TIMESTAMP(6),version=version+1 WHERE owner_id=?')->execute([$owner]);
            }
            else{
                $day=$body['day']??null;if(!is_string($day)||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$day))throw new HttpError(422,'Choose a Daily Word date.');
                $q=$db->prepare('SELECT * FROM daily_word_challenges WHERE challenge_date=?');$q->execute([$day]);$challenge=$q->fetch();
                if(!$challenge)throw new HttpError(409,'This daily word is unavailable. Refresh.','word_day_changed');
                $row=dailyWordProgress($db,$owner,$day);
                if(!is_int($body['revision']??null)||$body['revision']!==($row?(int)$row['revision']:0))throw new HttpError(409,'Your progress changed in another window. Load the latest board; your typed word is kept.','word_conflict');
                if($action==='guess'){
                    if($day!==dailyWordClock($instant)['currentDay'] || $challenge['expires_at']<=(new DateTimeImmutable(dailyWordClock($instant)['serverTime']))->format('Y-m-d H:i:s'))throw new HttpError(409,'A new London day has started. Open today’s word.','word_day_changed');
                    if($row && $row['finished_at']!==null)throw new HttpError(409,'Today’s word is already finished.','word_conflict');
                    $guess=$body['guess']??null;if(!is_string($guess)||!preg_match('/^[a-zA-Z]{5}$/D',$guess))throw new HttpError(422,'Enter five letters.','word_invalid');$guess=strtolower($guess);
                    if(!in_array($guess,dailyWordList('guesses'),true))throw new HttpError(422,'That word is not in this dictionary. Try another; no attempt was used.','word_invalid');
                    $guesses=$row?json_decode($row['guesses'],true,16,JSON_THROW_ON_ERROR):[];
                    foreach($guesses as $g)if($g['word']===$guess)throw new HttpError(422,'You already tried that word. No attempt was used.','word_duplicate');
                    $guesses[]=['word'=>$guess,'marks'=>dailyWordScore($guess,$challenge['answer'])];$solved=$guess===$challenge['answer'];$finished=$solved||count($guesses)===6;
                    $db->prepare('INSERT INTO daily_word_progress(owner_id,challenge_date,guesses,revision,finished_at,solved) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE guesses=VALUES(guesses),revision=VALUES(revision),finished_at=VALUES(finished_at),solved=VALUES(solved)')->execute([$owner,$day,json_encode($guesses,JSON_THROW_ON_ERROR),($row?(int)$row['revision']:0)+1,$finished?gmdate('Y-m-d H:i:s'):null,(int)$solved]);
                }else{
                    if(!$row || $row['finished_at']===null)throw new HttpError(422,'Finish the word before sharing a result.');
                    $changed=($action==='share')!==($row['shared_at']!==null);
                    if($changed)$db->prepare('UPDATE daily_word_progress SET shared_at=?,revision=revision+1 WHERE owner_id=? AND challenge_date=?')->execute([$action==='share'?gmdate('Y-m-d H:i:s'):null,$owner,$day]);
                }
            }
        }
        $saved=dailyWordInstallation($db,$owner);
        if($changed){
            $db->prepare('INSERT INTO daily_word_history(owner_id,challenge_date,action,occurred_at) VALUES(?,?,?,UTC_TIMESTAMP(6))')->execute([$owner,in_array($action,['share','withdraw'],true)?$body['day']:null,$action]);
            $auditAction=['install'=>'installed','remove'=>'removed','share'=>'word_result_shared','withdraw'=>'word_result_withdrawn'][$action];
            // Only generic lifecycle/publication metadata enters administrator oversight.
            // No private play, day, progress, answer, guess, pattern or completion payload.
            recordActivity($db,$user,'daily_word',$owner,$auditAction,null,['kind'=>'daily-word','ownerId'=>$owner,'visibility'=>'private','version'=>(int)$saved['version']]);
        }
        $response=['ownerId'=>$owner,'installationVersion'=>(int)$saved['version'],'installed'=>$saved['deleted_at']===null,'day'=>$body['day']??($challenge['challenge_date']??dailyWordClock($instant)['currentDay']),'progress'=>isset($body['day'])?dailyWordPublicProgress(dailyWordProgress($db,$owner,$body['day'])):null,'clock'=>dailyWordClock($instant)];
        $db->prepare('INSERT INTO daily_word_receipts(owner_id,request_key,request_hash,response,created_at) VALUES(?,?,?,?,UTC_TIMESTAMP(6))')->execute([$owner,$key,$signature,json_encode($response,JSON_THROW_ON_ERROR)]);
        $db->commit();return $response;
    }catch(Throwable $error){if($db->inTransaction())$db->rollBack();throw $error;}
}
