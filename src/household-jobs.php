<?php
declare(strict_types=1);

function householdJobDraft(array $body, array $user): array
{
    $scope=$body['scope']??null;
    if (!in_array($scope,['family','mine'],true)) throw new HttpError(422,'Choose Family or Mine.');
    $title=widgetText($body['title']??null,120);
    $note=$body['note']??''; $area=$body['area']??'';
    foreach (['note'=>[$note,2000], 'area'=>[$area,80]] as [$value,$max]) {
        if (!is_string($value)||preg_match('//u',$value)!==1||preg_match_all('/./us',$value)>$max||preg_match('/[\x00-\x08\x0b-\x1f\x7f]/',$value)) throw new HttpError(422,'Use plain text: note up to 2000 characters, area up to 80.');
    }
    $date=$body['targetDate']??null;
    if ($date!==null) {
        $parsed=is_string($date)?DateTimeImmutable::createFromFormat('!Y-m-d',$date):false;
        if (!$parsed||$parsed->format('Y-m-d')!==$date||$date<'1000-01-01'||$date>'9998-12-31') throw new HttpError(422,'Choose a valid optional target day.');
    }
    $repeat=$body['repeat']??'none';
    if (!in_array($repeat,['none','weekly','monthly'],true)) throw new HttpError(422,'Choose no repeat, weekly or monthly.');
    $assignee=$body['assigneeId']??null;
    if ($assignee!==null&&(!is_int($assignee)||$assignee<1)) throw new HttpError(422,'Choose a family member.');
    if ($scope==='mine') $assignee=(int)$user['id'];
    return [$scope,$title,trim($note),trim($area),$date,$repeat,$assignee];
}
function householdJobNextDate(?string $date, string $repeat): ?string
{
    // Flexible jobs stay flexible. Monthly dates clamp to the destination month.
    if ($date===null) return null;
    $day=new DateTimeImmutable($date);
    if ($repeat==='weekly') return $day->modify('+7 days')->format('Y-m-d');
    $month=$day->modify('first day of next month');
    return $month->setDate((int)$month->format('Y'),(int)$month->format('m'),min((int)$day->format('d'),(int)$month->format('t')))->format('Y-m-d');
}
function householdJobsSnapshot(PDO $db,array $user): array
{
    $db->beginTransaction();
    try {
        $q=$db->prepare("SELECT * FROM household_jobs WHERE scope='family' OR owner_id=? ORDER BY position,id"); $q->execute([$user['id']]); $rows=$q->fetchAll();
        $q=$db->prepare("SELECT h.*,u.display_name,u.nickname FROM household_job_history h JOIN household_jobs j ON j.id=h.job_id JOIN users u ON u.id=h.actor_id WHERE j.scope='family' OR j.owner_id=? ORDER BY h.id DESC"); $q->execute([$user['id']]); $history=[];
        foreach ($q->fetchAll() as $h) $history[$h['job_id']][]=['id'=>(string)$h['id'],'action'=>$h['action'],'targetDate'=>$h['target_date'],'actor'=>['id'=>(int)$h['actor_id'],'name'=>familyDisplayName($h)],'occurredAt'=>activityTimestamp($h['occurred_at'])];
        $members=$db->query('SELECT id,display_name,nickname FROM users WHERE is_active=1')->fetchAll();
        $jobs=[]; $archive=[];
        foreach($rows as $r) {
            $job=['id'=>(string)$r['id'],'ownerId'=>(int)$r['owner_id'],'scope'=>$r['scope'],'title'=>$r['title'],'note'=>$r['note'],'area'=>$r['area'],'targetDate'=>$r['target_date'],'repeat'=>$r['repeat_kind'],'assigneeId'=>$r['assignee_id']===null?null:(int)$r['assignee_id'],'done'=>(bool)$r['done'],'version'=>(int)$r['version'],'history'=>$history[$r['id']]??[]];
            if ($r['deleted_at']===null) $jobs[]=$job; else $archive[]=$job;
        }
        $db->commit(); return ['ownerId'=>(int)$user['id'],'jobs'=>$jobs,'archive'=>$archive,'members'=>array_map(static fn($m)=>['id'=>(int)$m['id'],'name'=>familyDisplayName($m)],$members)];
    } catch(Throwable $e) {if($db->inTransaction())$db->rollBack();throw $e;}
}
function mutateHouseholdJob(PDO $db,array $user,string $action,array $body): array
{
    if(!in_array($action,['add','edit','claim','assign','complete','reopen','remove','reorder'],true)) throw new HttpError(404,'Unknown job action.');
    $owner=(int)$user['id']; $key=widgetRequestKey($user,$body,'household-jobs');
    ksort($body); $signature=hash('sha256',$action.json_encode($body,JSON_THROW_ON_ERROR));
    $draft=in_array($action,['add','edit'],true)?householdJobDraft($body,$user):null;
    $db->beginTransaction();
    try {
        // One family lock serialises membership, complete-list reorder and domain changes.
        // Private order shares the lock but never enters shared payloads or reporting.
        $db->query("SELECT scope_key FROM household_job_order WHERE scope_key='family' FOR UPDATE")->fetch();
        $q=$db->prepare('SELECT * FROM household_job_receipts WHERE actor_id=? AND request_key=?');$q->execute([$owner,$key]);$receipt=$q->fetch();
        if($receipt){if(!hash_equals($receipt['request_hash'],$signature))throw new HttpError(422,'Use a fresh request for a changed action.');$db->commit();return ['ownerId'=>$owner,'accepted'=>true];}
        if($action==='reorder') {
            $scope=$body['scope']??null; $ids=$body['ids']??null;
            if(!in_array($scope,['family','mine'],true)||!is_array($ids)||($ids!==[] && array_keys($ids)!==range(0,count($ids)-1))||count($ids)>1000||count(array_unique($ids,SORT_REGULAR))!==count($ids))throw new HttpError(422,'Refresh the jobs before moving them.');
            foreach($ids as $id)widgetIdentifier($id);
            $q=$db->prepare('SELECT id FROM household_jobs WHERE deleted_at IS NULL AND done=0 AND scope=?'.($scope==='mine'?' AND owner_id=?':'').' ORDER BY position,id');$q->execute($scope==='mine'?[$scope,$owner]:[$scope]);$current=array_map('strval',array_column($q->fetchAll(),'id'));
            $actual=$ids;sort($actual);$expected=$current;sort($expected);
            if($actual!==$expected)throw new HttpError(409,'The list changed. Jobs refreshed; move it again.','jobs_conflict');
            $q=$db->prepare('UPDATE household_jobs SET position=? WHERE id=?');foreach($ids as $position=>$id)$q->execute([$position,$id]);
        } else {
            $job=null;
            if($action!=='add') {
                $id=widgetIdentifier($body['id']??null);$q=$db->prepare('SELECT * FROM household_jobs WHERE id=? AND deleted_at IS NULL FOR UPDATE');$q->execute([$id]);$job=$q->fetch();
                if(!$job||($job['scope']==='mine'&&(int)$job['owner_id']!==$owner))throw new HttpError(404,'This job is not available in your view.');
                if(!is_int($body['version']??null)||(int)$job['version']!==$body['version'])throw new HttpError(409,'This job changed. Review the refreshed job before trying again.','jobs_conflict');
                $manager=(int)$job['owner_id']===$owner||$user['role']==='admin';
                if(in_array($action,['edit','assign','remove'],true)&&!$manager)throw new HttpError(403,'Only the creator or an administrator can change this job.');
                if(in_array($action,['complete','reopen'],true)&&!$manager&&(int)$job['assignee_id']!==$owner)throw new HttpError(403,'Claim this job before completing it.');
            }
            $assignee=$draft[6]??($body['assigneeId']??null);
            if($action==='assign'&&$assignee!==null&&(!is_int($assignee)||$assignee<1))throw new HttpError(422,'Choose a family member.');
            if(in_array($action,['add','edit','assign'],true)&&$assignee!==null){$q=$db->prepare('SELECT id FROM users WHERE id=? AND is_active=1');$q->execute([$assignee]);if(!$q->fetch())throw new HttpError(422,'Choose an active family member.');}
            if($action==='add') {
                $q=$db->prepare('INSERT INTO household_jobs(owner_id,scope,title,note,area,target_date,repeat_kind,assignee_id,position,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,(SELECT COALESCE(MAX(position),0)+1 FROM household_jobs j),UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))');$q->execute(array_merge([$owner],$draft));$id=(string)$db->lastInsertId();
            } elseif($action==='edit') {
                if($draft[0]!==$job['scope'])throw new HttpError(422,'The privacy scope cannot change after creation. Create a separate job instead.');
                $db->prepare('UPDATE household_jobs SET scope=?,title=?,note=?,area=?,target_date=?,repeat_kind=?,assignee_id=? WHERE id=?')->execute(array_merge($draft,[$id]));
            } elseif($action==='claim') {
                if($job['assignee_id']!==null||$job['done'])throw new HttpError(409,'This job is already claimed or done. The list has been refreshed.','jobs_conflict');
                $db->prepare('UPDATE household_jobs SET assignee_id=? WHERE id=?')->execute([$owner,$id]);
            } elseif($action==='assign') {
                if($job['scope']==='mine'&&$assignee!==$owner)throw new HttpError(422,'Personal jobs stay with their owner.');
                $db->prepare('UPDATE household_jobs SET assignee_id=? WHERE id=?')->execute([$assignee,$id]);
            } elseif($action==='complete') {
                if($job['done'])throw new HttpError(409,'This job is already done.');
                $date=$job['repeat_kind']==='none'?$job['target_date']:householdJobNextDate($job['target_date'],$job['repeat_kind']);
                if($date!==null&&$date>'9998-12-31')throw new HttpError(422,'Choose an earlier target day before repeating.');
                $db->prepare('UPDATE household_jobs SET done=?,target_date=? WHERE id=?')->execute([$job['repeat_kind']==='none'?1:0,$date,$id]);
            } elseif($action==='reopen') {
                $q=$db->prepare("SELECT * FROM household_job_history WHERE job_id=? AND action IN ('complete','reopen') ORDER BY id DESC LIMIT 1");$q->execute([$id]);$last=$q->fetch();
                if(!$last||$last['action']!=='complete')throw new HttpError(409,'There is no completion to reopen.');
                $db->prepare('UPDATE household_jobs SET done=0,target_date=? WHERE id=?')->execute([$last['target_date'],$id]);
            } elseif($action==='remove')$db->prepare('UPDATE household_jobs SET deleted_at=UTC_TIMESTAMP(6) WHERE id=?')->execute([$id]);
            if($action!=='add')$db->prepare('UPDATE household_jobs SET version=version+1,updated_at=UTC_TIMESTAMP(6) WHERE id=?')->execute([$id]);
            $history = $db->prepare('INSERT INTO household_job_history(job_id,actor_id,action,target_date,occurred_at) VALUES(?,?,?,?,UTC_TIMESTAMP(6))');
            $history->execute([$id,$owner,$action,$job['target_date']??($draft[4]??null)]);
            // Assignment made through the editor remains an explicit attributed action.
            if (($action==='edit' && $draft[6] !== ($job['assignee_id']===null?null:(int)$job['assignee_id'])) || ($action==='add' && $draft[6]!==null)) {
                $history->execute([$id,$owner,'assign',$draft[4]]);
            }
        }
        $db->prepare("UPDATE household_job_order SET revision=revision+1 WHERE scope_key='family'")->execute();
        $response=['ownerId'=>$owner,'accepted'=>true];
        $db->prepare('INSERT INTO household_job_receipts(actor_id,request_key,request_hash,response) VALUES(?,?,?,?)')->execute([$owner,$key,$signature,json_encode($response,JSON_THROW_ON_ERROR)]);
        $db->commit();return $response;
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
