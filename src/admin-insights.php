<?php
declare(strict_types=1);

function insightCategory(string $action): string
{
    if ($action === 'created') return 'created';
    if ($action === 'removed') return 'removed';
    if (in_array($action, ['liked','unliked','voted','vote_changed','vote_withdrawn'], true)) return 'responded';
    return 'updated';
}

function insightCounts(): array
{
    return ['created'=>0,'updated'=>0,'responded'=>0,'removed'=>0,'total'=>0];
}

function adminInsightsSnapshot(PDO $db, array $user, array $query): array
{
    requireFamilyAdmin($user);
    $days = familyReportingPeriod($query);
    // One declared reporting zone keeps calendar days comparable across family devices.
    $window = familyReportingWindow($days);
    $zone = $window['zone'];
    $utc = new DateTimeZone('UTC');
    $end = $window['end'];
    $today = $end->modify('-1 day');
    $start = $window['start'];
    $previousStart = $start->modify('-'.$days.' days');
    $from = $previousStart->setTimezone($utc)->format('Y-m-d H:i:s');
    $until = $end->setTimezone($utc)->format('Y-m-d H:i:s');
    $types = sharedActivityTypes();
    $in = implode(',', array_fill(0, count($types), '?'));
    $db->beginTransaction();
    try {
        $first = $db->prepare('SELECT MIN(occurred_at) FROM activity_events WHERE entity_type IN ('.$in.')');
        $first->execute($types);
        $firstActivity = $first->fetchColumn() ?: null;
        $firstUsage = $db->query('SELECT MIN(started_at) FROM platform_sessions')->fetchColumn() ?: null;
        $people = [];
        foreach ($db->query('SELECT id,display_name,nickname,is_active FROM users ORDER BY display_name,id')->fetchAll() as $row) {
            $people[(int)$row['id']] = insightCounts() + ['id'=>(int)$row['id'], 'name'=>familyDisplayName($row),
                'enabled'=>(bool)$row['is_active'], 'logins'=>0, 'activeSeconds'=>0];
        }
        // Aggregate at the database boundary; no note content or audit snapshots leave the API.
        $actions = $db->prepare("SELECT DATE_FORMAT(occurred_at,'%Y-%m-%d %H:00:00') AS hour,
            entity_type,action,actor_id,COUNT(*) AS amount FROM activity_events
            WHERE entity_type IN (".$in.") AND occurred_at >= ? AND occurred_at < ?
            GROUP BY hour,entity_type,action,actor_id");
        $actions->execute(array_merge($types, [$from,$until]));
        $actionRows = $actions->fetchAll();
        $logins = $db->prepare("SELECT DATE_FORMAT(login_at,'%Y-%m-%d %H:00:00') AS hour, user_id,COUNT(*) AS amount
            FROM platform_sessions WHERE login_at >= ? AND login_at < ? GROUP BY hour,user_id");
        $logins->execute([$from,$until]);
        $loginRows = $logins->fetchAll();
        $time = $db->prepare('SELECT user_id,SUM(active_seconds) AS seconds FROM platform_sessions
            WHERE started_at >= ? AND started_at < ? GROUP BY user_id');
        $time->execute([$start->setTimezone($utc)->format('Y-m-d H:i:s'),$until]);
        $timeRows = $time->fetchAll();
        $db->commit();
    } catch (Throwable $error) { $db->rollBack(); throw $error; }
    $asDay = static fn(string $value): string => (new DateTimeImmutable($value,$utc))->setTimezone($zone)->format('Y-m-d');
    $activitySince = $firstActivity === null ? null : $asDay($firstActivity);
    $usageSince = $firstUsage === null ? null : $asDay($firstUsage);
    $daily = []; $features = [];
    foreach ($types as $type) $features[$type] = insightCounts() + ['key'=>$type];
    for ($date=$previousStart; $date<$end; $date=$date->modify('+1 day')) {
        $day=$date->format('Y-m-d');
        $daily[$day] = insightCounts() + ['date'=>$day,'signins'=>0,'contributors'=>0,'actors'=>[]];
    }
    $startDay=$start->format('Y-m-d'); $currentTotal=0; $previousTotal=0;
    foreach ($actionRows as $row) {
        $day=$asDay($row['hour']); $amount=(int)$row['amount']; $category=insightCategory($row['action']);
        $daily[$day][$category]+=$amount; $daily[$day]['total']+=$amount;
        $daily[$day]['actors'][(int)$row['actor_id']]=true;
        if ($day >= $startDay) {
            $currentTotal+=$amount;
            $features[$row['entity_type']][$category]+=$amount; $features[$row['entity_type']]['total']+=$amount;
            $people[(int)$row['actor_id']][$category]+=$amount; $people[(int)$row['actor_id']]['total']+=$amount;
        } else $previousTotal+=$amount;
    }
    foreach ($loginRows as $row) {
        $day=$asDay($row['hour']); $amount=(int)$row['amount'];
        $daily[$day]['signins']+=$amount;
        if ($day >= $startDay) $people[(int)$row['user_id']]['logins']+=$amount;
    }
    foreach ($timeRows as $row) $people[(int)$row['user_id']]['activeSeconds']=(int)$row['seconds'];
    $series=[];
    foreach ($daily as $day=>$row) {
        if ($day < $startDay) continue;
        $row['contributors']=count($row['actors']); unset($row['actors']);
        // Unknown history is a gap, never an invented run of zero activity.
        if ($activitySince === null || $day < $activitySince) {
            foreach (array_keys(insightCounts()) as $key) $row[$key]=null;
            $row['contributors']=null;
        }
        if ($usageSince === null || $day < $usageSince) $row['signins']=null;
        $series[]=$row;
    }
    usort($features,static fn(array $a,array $b): int => $b['total']<=>$a['total']);
    $previousComplete = $activitySince !== null && $activitySince < $previousStart->format('Y-m-d');
    return ['days'=>$days,'timezone'=>'Europe/London','from'=>$startDay,'through'=>$today->format('Y-m-d'),
        'activitySince'=>$activitySince,'usageSince'=>$usageSince,'daily'=>$series,'features'=>array_values($features),
        'people'=>array_values($people),'summary'=>['actions'=>$currentTotal,'previousActions'=>$previousComplete ? $previousTotal : null,
            'contributors'=>count(array_filter($people,static fn(array $person): bool => $person['total']>0)),
            'signins'=>array_sum(array_column($people,'logins'))],
        'generatedAt'=>gmdate('Y-m-d\TH:i:s\Z')];
}
