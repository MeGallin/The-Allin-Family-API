<?php
declare(strict_types=1);

function focusTimerNow(): int { return (int)floor(microtime(true) * 1000); }
function focusTimerDate(int $milliseconds): string
{
    return gmdate('Y-m-d H:i:s', intdiv($milliseconds, 1000)) . sprintf('.%03d', $milliseconds % 1000);
}
function focusTimerMilliseconds(string $date): int
{
    return (int)round((float)(new DateTimeImmutable($date, new DateTimeZone('UTC')))->format('U.u') * 1000);
}
function focusTimerInstallation(PDO $db, int $owner): array|false
{
    $q = $db->prepare('SELECT * FROM focus_timer_installations WHERE owner_id=?');
    $q->execute([$owner]); return $q->fetch();
}
function focusTimerSession(PDO $db, int $owner, mixed $id): array|false
{
    if ($id === null) return false;
    $q = $db->prepare('SELECT * FROM focus_timer_sessions WHERE owner_id=? AND id=?');
    $q->execute([$owner, $id]); return $q->fetch();
}
function focusTimerElapsed(array $session, int $now): int
{
    $elapsed = (int)$session['elapsed_ms'];
    if ($session['state'] === 'running') $elapsed += max(0, $now - focusTimerMilliseconds($session['interval_started_at']));
    return min((int)$session['duration_ms'], $elapsed);
}
function focusTimerPublicSession(array $row, int $now): array
{
    $elapsed = focusTimerElapsed($row, $now);
    return [
        'id'=>(string)$row['id'], 'phase'=>$row['phase'], 'label'=>$row['label'],
        'durationMs'=>(int)$row['duration_ms'], 'elapsedMs'=>$elapsed,
        'state'=>$row['state'] === 'running' && $elapsed >= (int)$row['duration_ms'] ? 'elapsed' : $row['state'],
        'deadline'=>$row['deadline_at'] === null ? null : activityTimestamp($row['deadline_at']),
        'endedAt'=>$row['ended_at'] === null ? null : activityTimestamp($row['ended_at']),
    ];
}
function focusTimerState(PDO $db, int $owner, int $now): array
{
    $row = focusTimerInstallation($db, $owner);
    $session = $row ? focusTimerSession($db, $owner, $row['current_session_id']) : false;
    return [
        'ownerId'=>$owner, 'version'=>$row ? (int)$row['version'] : 0,
        'generation'=>$row ? (int)$row['generation'] : 0,
        'installed'=>$row && $row['deleted_at'] === null,
        'defaults'=>['focus'=>$row ? (int)$row['focus_minutes'] : 25, 'short'=>$row ? (int)$row['short_minutes'] : 5, 'long'=>$row ? (int)$row['long_minutes'] : 15],
        'session'=>$session ? focusTimerPublicSession($session, $now) : null,
        'serverTime'=>activityTimestamp(focusTimerDate($now)),
    ];
}
function focusTimerSnapshot(PDO $db, array $user, ?int $instant=null): array
{
    $owner = (int)$user['id']; $now = $instant ?? focusTimerNow();
    // One read transaction keeps current identity/version/history coherent against another device.
    $db->beginTransaction();
    try {
        $snapshot = focusTimerState($db, $owner, $now);
        $zone = new DateTimeZone('Europe/London');
        $today = (new DateTimeImmutable(focusTimerDate($now),new DateTimeZone('UTC')))->setTimezone($zone)->setTime(0,0);
        $since = $today->modify('-6 days')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $until = $today->modify('+1 day')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $q = $db->prepare("SELECT * FROM focus_timer_sessions WHERE owner_id=? AND ended_at>=? AND ended_at<? ORDER BY ended_at DESC,id DESC LIMIT 200");
        $q->execute([$owner,$since,$until]); $history = $q->fetchAll();
        // Summary aggregates all terminal rows in the window, independently of the bounded recent list.
        // UTC hour buckets map exactly to London calendar dates, including BST/GMT boundaries.
        // This avoids relying on optional MySQL timezone tables or truncating the daily totals.
        $totals = $db->prepare("SELECT DATE_FORMAT(ended_at,'%Y-%m-%d %H:00:00') AS hour,phase,state,COUNT(*) AS sessions,SUM(elapsed_ms) AS elapsed FROM focus_timer_sessions WHERE owner_id=? AND ended_at>=? AND ended_at<? AND state IN ('completed','early') GROUP BY hour,phase,state");
        $totals->execute([$owner,$since,$until]);
        $summary = ['focusCompleted'=>0,'focusMs'=>0,'breakMs'=>0];
        $days=[];
        for ($day=$today->modify('-6 days');$day<=$today;$day=$day->modify('+1 day')) $days[$day->format('Y-m-d')]=['date'=>$day->format('Y-m-d'),'focusMs'=>0,'breakMs'=>0,'focusCompleted'=>0];
        foreach ($totals->fetchAll() as $r) {
            $date=(new DateTimeImmutable($r['hour'],new DateTimeZone('UTC')))->setTimezone($zone)->format('Y-m-d');
            if ($r['phase']==='focus') {
                $summary['focusMs']+=(int)$r['elapsed']; $days[$date]['focusMs']+=(int)$r['elapsed'];
                if ($r['state']==='completed') { $summary['focusCompleted']+=(int)$r['sessions']; $days[$date]['focusCompleted']+=(int)$r['sessions']; }
            } else { $summary['breakMs']+=(int)$r['elapsed']; $days[$date]['breakMs']+=(int)$r['elapsed']; }
        }
        $snapshot += ['history'=>array_map(static fn($r)=>focusTimerPublicSession($r,$now),$history),'summary'=>$summary,'days'=>array_values($days),'through'=>$today->format('Y-m-d')];
        $db->commit(); return $snapshot;
    } catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
}
function focusTimerDraft(array $body): array
{
    $phase = $body['phase'] ?? null; $minutes = $body['minutes'] ?? null;
    if (!in_array($phase,['focus','short','long'],true) || !is_int($minutes) || $minutes < ($phase==='focus'?5:1) || $minutes > ($phase==='focus'?180:60)) throw new HttpError(422,'Choose a whole-minute duration: Focus 5–180, breaks 1–60.');
    $label = $body['label'] ?? '';
    if (!is_string($label) || preg_match('//u',$label)!==1 || preg_match_all('/./us',$label)>80 || preg_match('/[\p{Cc}\p{Cf}]/u',$label)) throw new HttpError(422,'Use an optional session label of up to 80 plain-text characters.');
    return [$phase,$minutes,trim($label)];
}
function focusTimerClose(PDO $db, array $session, int $now, string $state): void
{
    $db->prepare('UPDATE focus_timer_sessions SET elapsed_ms=?,state=?,interval_started_at=NULL,deadline_at=NULL,ended_at=? WHERE id=? AND owner_id=?')->execute([focusTimerElapsed($session,$now),$state,focusTimerDate($now),$session['id'],$session['owner_id']]);
}
function mutateFocusTimer(PDO $db, array $user, string $action, array $body, ?int $instant=null): array
{
    if (!in_array($action,['install','start','pause','resume','finish','reset','next','remove'],true)) throw new HttpError(404,'Unknown Focus Timer action.');
    // Focus has sessionId, not the helper's optional widget id: ignored fields must not change retry scope.
    $owner=(int)$user['id']; $key=widgetRequestKey($user,['requestKey'=>$body['requestKey']??null],'focus-timer');
    ksort($body); $signature=hash('sha256',$action.json_encode($body,JSON_THROW_ON_ERROR));
    $db->beginTransaction();
    try {
        $db->prepare('SELECT id FROM users WHERE id=? FOR UPDATE')->execute([$owner]);
        $q=$db->prepare('SELECT request_hash,response FROM focus_timer_receipts WHERE owner_id=? AND request_key=?'); $q->execute([$owner,$key]); $receipt=$q->fetch();
        if ($receipt) {
            if (!hash_equals($receipt['request_hash'],$signature)) throw new HttpError(422,'Use a fresh request after changing your action.');
            $db->commit(); return json_decode($receipt['response'],true,32,JSON_THROW_ON_ERROR);
        }
        $row=focusTimerInstallation($db,$owner); $now=$instant ?? focusTimerNow();
        if (!is_int($body['version']??null) || $body['version']!==($row?(int)$row['version']:0) || !is_int($body['generation']??null) || $body['generation']!==($row?(int)$row['generation']:0)) throw new HttpError(409,'Your timer changed in another window. Load the latest timer before continuing.','focus_conflict');
        $event=null;
        if ($action==='install') {
            if (!$row) { $db->prepare('INSERT INTO focus_timer_installations(owner_id) VALUES(?)')->execute([$owner]); $event='installed'; }
            elseif ($row['deleted_at']!==null) { $db->prepare('UPDATE focus_timer_installations SET deleted_at=NULL,current_session_id=NULL,generation=generation+1,version=version+1 WHERE owner_id=?')->execute([$owner]); $event='installed'; }
        } else {
            if (!$row || $row['deleted_at']!==null) throw new HttpError(409,'This Focus Timer installation was removed.','focus_conflict');
            $session=focusTimerSession($db,$owner,$row['current_session_id']);
            $id=$body['sessionId']??null;
            if ($id!==($session?(string)$session['id']:null)) throw new HttpError(409,'The current session changed. Load the latest timer.','focus_conflict');
            $elapsed=$session?focusTimerElapsed($session,$now):0;
            $reached=$session && $elapsed>=(int)$session['duration_ms'];
            if ($action==='remove') {
                if ($session) focusTimerClose($db,$session,$now,$reached?'elapsed_removed':'discarded');
                $db->prepare('UPDATE focus_timer_installations SET current_session_id=NULL,deleted_at=?,generation=generation+1,version=version+1 WHERE owner_id=?')->execute([focusTimerDate($now),$owner]); $event='removed';
            } else {
                if ($action==='start' && $session) throw new HttpError(409,'Finish or reset your current session before starting another.','focus_conflict');
                if ($action!=='start' && !$session) throw new HttpError(409,'This session has already ended. Load the latest timer.','focus_conflict');
                if ($action==='next' && !$reached) throw new HttpError(409,'This timer has not reached its planned duration.','focus_conflict');
                if (in_array($action,['start','next'],true)) {
                    [$phase,$minutes,$label]=focusTimerDraft($body);
                    if ($action==='next') focusTimerClose($db,$session,$now,'completed');
                    $db->prepare("INSERT INTO focus_timer_sessions(owner_id,phase,label,duration_ms,state,started_at,interval_started_at,deadline_at) VALUES(?,?,?,?,'running',?,?,?)")->execute([$owner,$phase,$label,$minutes*60000,focusTimerDate($now),focusTimerDate($now),focusTimerDate($now+$minutes*60000)]);
                    $current=$db->lastInsertId(); $field=['focus'=>'focus_minutes','short'=>'short_minutes','long'=>'long_minutes'][$phase];
                    $db->prepare("UPDATE focus_timer_installations SET current_session_id=?,$field=?,version=version+1 WHERE owner_id=?")->execute([$current,$minutes,$owner]);
                } elseif ($action==='pause') {
                    if ($session['state']!=='running') throw new HttpError(409,'This timer is already paused. Load the latest state.','focus_conflict');
                    // A delayed Pause at zero remains elapsed; no resumable zero interval is created.
                    if (!$reached) $db->prepare("UPDATE focus_timer_sessions SET state='paused',elapsed_ms=?,interval_started_at=NULL,deadline_at=NULL WHERE id=? AND owner_id=?")->execute([$elapsed,$session['id'],$owner]);
                    $db->prepare('UPDATE focus_timer_installations SET version=version+1 WHERE owner_id=?')->execute([$owner]);
                } elseif ($action==='resume') {
                    if ($session['state']!=='paused' || $reached) throw new HttpError(409,'This session cannot be resumed. Load the latest timer.','focus_conflict');
                    $db->prepare("UPDATE focus_timer_sessions SET state='running',interval_started_at=?,deadline_at=? WHERE id=? AND owner_id=?")->execute([focusTimerDate($now),focusTimerDate($now+(int)$session['duration_ms']-$elapsed),$session['id'],$owner]);
                    $db->prepare('UPDATE focus_timer_installations SET version=version+1 WHERE owner_id=?')->execute([$owner]);
                } else {
                    focusTimerClose($db,$session,$now,$action==='reset'?'discarded':($reached?'completed':'early'));
                    $db->prepare('UPDATE focus_timer_installations SET current_session_id=NULL,version=version+1 WHERE owner_id=?')->execute([$owner]);
                }
            }
        }
        $response=focusTimerState($db,$owner,$instant ?? focusTimerNow());
        if ($event) recordActivity($db,$user,'focus_timer',$owner,$event,null,['kind'=>'focus-timer','ownerId'=>$owner,'visibility'=>'private','generation'=>$response['generation']]);
        $db->prepare('INSERT INTO focus_timer_receipts(owner_id,request_key,request_hash,response,created_at) VALUES(?,?,?,?,?)')->execute([$owner,$key,$signature,json_encode($response,JSON_THROW_ON_ERROR),focusTimerDate($now)]);
        $db->commit(); return $response;
    } catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
}
