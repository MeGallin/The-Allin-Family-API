<?php
declare(strict_types=1);

/** Called inside the widget snapshot transaction. Trial spins are never used as shared results. */
function widgetPublicationsSnapshot(PDO $db, array $ids): array
{
    if (!$ids) return [];
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $since=(new DateTimeImmutable('today',new DateTimeZone('Europe/London')))->modify('-6 days')->format('Y-m-d');
    $query = $db->prepare('SELECT recent.*,u.display_name,u.nickname,e.display_name AS eaten_name,e.nickname AS eaten_nickname FROM
        (SELECT p.*,ROW_NUMBER() OVER(PARTITION BY widget_id ORDER BY id DESC) AS position,
            ROW_NUMBER() OVER(PARTITION BY widget_id,for_date,meal_type ORDER BY id DESC) AS slot_position
            FROM dinner_publications p WHERE widget_id IN (' . $placeholders . ')) recent
        JOIN users u ON u.id=recent.publisher_id LEFT JOIN users e ON e.id=recent.eaten_by
        WHERE recent.position<=10 OR (recent.slot_position=1 AND recent.for_date>=?) ORDER BY recent.id DESC');
    $query->execute(array_merge($ids,[$since]));
    $rows = $query->fetchAll();
    if (!$rows) return [];
    $publicationIds = array_column($rows, 'id');
    $in = implode(',', array_fill(0, count($publicationIds), '?'));
    $query = $db->prepare('SELECT m.publication_id,u.id,u.display_name,u.nickname,u.is_active,r.choice,r.version,r.updated_at
        FROM dinner_publication_members m JOIN users u ON u.id=m.user_id
        LEFT JOIN dinner_responses r ON r.publication_id=m.publication_id AND r.user_id=m.user_id
        WHERE m.publication_id IN (' . $in . ') ORDER BY u.id');
    $query->execute($publicationIds);
    $members = [];
    foreach ($query->fetchAll() as $member) $members[$member['publication_id']][(int)$member['id']] = widgetDecisionMember($member);
    // Current suggestions include newly joined active members; historical audiences stay recorded.
    $active = $db->query('SELECT id,display_name,nickname,is_active FROM users WHERE is_active=1 ORDER BY id')->fetchAll();
    $result = [];
    foreach ($rows as $row) {
        $audience = $members[$row['id']] ?? [];
        if ((int)$row['slot_position'] === 1 && !$row['eaten_at']) foreach ($active as $member) {
            if (!isset($audience[(int)$member['id']])) $audience[(int)$member['id']] = widgetDecisionMember($member);
        }
        $result[$row['widget_id']][] = ['id'=>(string)$row['id'], 'spinId'=>(string)$row['spin_id'], 'meal'=>$row['meal_title'],
            'forDate'=>$row['for_date'], 'mealType'=>$row['meal_type'], 'current'=>(int)$row['slot_position']===1,
            'eatenAt'=>$row['eaten_at'] ? activityTimestamp($row['eaten_at']) : null,
            'eatenBy'=>$row['eaten_by'] ? ['id'=>(int)$row['eaten_by'],'name'=>familyDisplayName(['display_name'=>$row['eaten_name'],'nickname'=>$row['eaten_nickname']])] : null, 'publishedAt'=>activityTimestamp($row['published_at']),
            'publisher'=>['id'=>(int)$row['publisher_id'], 'name'=>familyDisplayName($row)], 'members'=>array_values($audience)];
    }
    return $result;
}

function widgetDecisionMember(array $member): array
{
    return ['id'=>(int)$member['id'], 'name'=>familyDisplayName($member), 'active'=>(bool)$member['is_active'],
        'choice'=>$member['choice'] ?? null, 'version'=>(int)($member['version'] ?? 0),
        'respondedAt'=>isset($member['updated_at']) ? activityTimestamp($member['updated_at']) : null];
}

function widgetDinnerDate(mixed $value): string
{
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) || substr($value,0,4)<'1000') throw new HttpError(422,'Choose a valid date for the meal.');
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
    if (!$date || $date->format('Y-m-d')!==$value) throw new HttpError(422,'Choose a valid date for the meal.');
    $today=new DateTimeImmutable('today',new DateTimeZone('Europe/London'));
    if ($value<$today->modify('-6 days')->format('Y-m-d') || $value>$today->modify('+365 days')->format('Y-m-d')) throw new HttpError(422,'Choose a meal date within the past seven days or the next year.');
    return $value;
}

function mutateWidgetDecision(PDO $db, array $user, string $operation, array $body): array
{
    $id=widgetIdentifier($body['id']??null);
    $key=$operation==='publish' ? widgetRequestKey($user,$body,'publish') : null;
    $db->beginTransaction();
    try {
        $widget=availableWidget($db,$user,$id,true);
        if ($widget['visibility']!=='family') throw new HttpError(422,'Install this widget for the family before publishing or responding.');
        if ($operation==='publish') {
            $date=widgetDinnerDate($body['forDate']??null);
            $mealType=widgetMealType(array_key_exists('mealType',$body)?$body['mealType']:'dinner');
        } else {
            $publicationId=widgetIdentifier($body['publicationId']??null);
            $query=$db->prepare('SELECT for_date,meal_type FROM dinner_publications WHERE id=? AND widget_id=?');
            $query->execute([$publicationId,$id]); $target=$query->fetch();
            if (!$target) throw new HttpError(404,'This meal is not available.');
            $date=$target['for_date']; $mealType=$target['meal_type'];
        }
        $query=$db->prepare('SELECT * FROM dinner_publications WHERE widget_id=? AND for_date=? AND meal_type=? ORDER BY id DESC LIMIT 1');
        $query->execute([$id,$date,$mealType]); $latest=$query->fetch();
        if ($operation==='publish') publishWidgetDinner($db,$user,$widget,$latest?:null,$body,$key);
        elseif ($operation==='eaten') recordWidgetMealEaten($db,$user,$widget,$latest?:null,$body);
        else respondToWidgetDinner($db,$user,$widget,$latest?:null,$body);
        $db->commit();
    } catch (Throwable $error) { $db->rollBack(); throw $error; }
    return widgetsSnapshot($db,$user);
}

/** The caller holds the widget lock, so replacement checks and publication are atomic. */
function publishWidgetDinner(PDO $db, array $user, array $widget, ?array $latest, array $body, string $key): void
{
    $spinId=widgetIdentifier($body['spinId']??null);
    $date=widgetDinnerDate($body['forDate']??null);
    $mealType=widgetMealType(array_key_exists('mealType',$body)?$body['mealType']:'dinner');
    $query=$db->prepare('SELECT id FROM dinner_publications WHERE request_key=? AND widget_id=? AND publisher_id=?');
    $query->execute([$key,$widget['id'],$user['id']]);
    if ($query->fetchColumn()) return;
    if (!is_int($body['version']??null) || (int)$widget['version']!==$body['version']) throw new HttpError(409,'This installation changed. Review it before publishing.','widget_conflict');
    $previous=$body['previousPublicationId']??null;
    if ($previous!==null) $previous=widgetIdentifier($previous);
    if (($latest ? (string)$latest['id'] : null)!==$previous) throw new HttpError(409,'A family suggestion was published in another window. Review it before replacing it.','widget_conflict');
    if ($latest && $latest['eaten_at']) throw new HttpError(409,'This meal is recorded as eaten. Correct that record before replacing it.','widget_conflict');
    $query=$db->prepare('SELECT s.* FROM dinner_spins s JOIN dinner_meals m ON m.id=s.meal_id AND m.widget_id=s.widget_id
        WHERE s.id=? AND s.widget_id=? AND s.actor_id=? AND m.deleted_at IS NULL AND m.version=s.meal_version AND BINARY m.title=BINARY s.meal_title');
    $query->execute([$spinId,$widget['id'],$user['id']]); $spin=$query->fetch();
    if (!$spin) throw new HttpError(422,'Choose one of your own trial picks from the current meal list. Spin again if its meal changed.');
    $query=$db->prepare('SELECT id FROM dinner_publications WHERE spin_id=?');
    $query->execute([$spinId]);
    if ($query->fetchColumn()) throw new HttpError(409,'That trial pick has already been published. Spin again for a new suggestion.','widget_conflict');
    $db->prepare('INSERT INTO dinner_publications(widget_id,spin_id,publisher_id,meal_title,for_date,meal_type,request_key,published_at) VALUES(?,?,?,?,?,?,?,UTC_TIMESTAMP(6))')
        ->execute([$widget['id'],$spinId,$user['id'],$spin['meal_title'],$date,$mealType,$key]);
    $publicationId=(string)$db->lastInsertId();
    $db->prepare('INSERT INTO dinner_publication_members(publication_id,user_id) SELECT ?,id FROM users WHERE is_active=1')->execute([$publicationId]);
    $db->prepare('UPDATE family_widgets SET version=version+1,updated_at=UTC_TIMESTAMP(6) WHERE id=?')->execute([$widget['id']]);
    recordActivity($db,$user,'widget',(int)$widget['id'],'published',
        $latest ? ['publicationId'=>(string)$latest['id']] : null,
        ['kind'=>'dinner-spinner','publicationId'=>$publicationId,'spinId'=>$spinId,'forDate'=>$date,'mealType'=>$mealType]);
}

function respondToWidgetDinner(PDO $db, array $user, array $widget, ?array $latest, array $body): void
{
    $publicationId=widgetIdentifier($body['publicationId']??null);
    if (!$latest || (string)$latest['id']!==$publicationId) throw new HttpError(409,'This suggestion was replaced. Review the latest dinner before responding.','widget_conflict');
    if ($latest['eaten_at']) throw new HttpError(409,'This meal is already recorded as eaten. Correct that record before changing replies.','widget_conflict');
    if (!array_key_exists('choice',$body) || !in_array($body['choice'],['agree','another',null],true) || !is_int($body['responseVersion']??null) || $body['responseVersion']<0) throw new HttpError(422,'Choose a valid dinner response.');
    $query=$db->prepare('SELECT choice,version FROM dinner_responses WHERE publication_id=? AND user_id=?');
    $query->execute([$publicationId,$user['id']]); $previous=$query->fetch();
    $before=$previous['choice']??null; $choice=$body['choice'];
    if ($choice===$before) return;
    if ((int)($previous['version']??0)!==$body['responseVersion']) throw new HttpError(409,'Your response changed in another window. Review it before saving.','widget_conflict');
    $db->prepare('INSERT IGNORE INTO dinner_publication_members(publication_id,user_id) VALUES(?,?)')->execute([$publicationId,$user['id']]);
    $db->prepare('INSERT INTO dinner_responses(publication_id,user_id,choice,updated_at) VALUES(?,?,?,UTC_TIMESTAMP(6))
        ON DUPLICATE KEY UPDATE choice=VALUES(choice),version=version+1,updated_at=UTC_TIMESTAMP(6)')->execute([$publicationId,$user['id'],$choice]);
    recordActivity($db,$user,'widget',(int)$widget['id'],$choice===null?'dinner_response_withdrawn':($before===null?'dinner_responded':'dinner_response_changed'),
        ['publicationId'=>$publicationId,'response'=>$before],['publicationId'=>$publicationId,'response'=>$choice]);
}

function widgetMealType(mixed $value): string
{
    if (!is_string($value) || !in_array($value,['breakfast','lunch','dinner','other'],true)) throw new HttpError(422,'Choose breakfast, lunch, dinner or other.');
    return $value;
}

function recordWidgetMealEaten(PDO $db, array $user, array $widget, ?array $latest, array $body): void
{
    $publicationId=widgetIdentifier($body['publicationId']??null);
    if (!$latest || (string)$latest['id']!==$publicationId) throw new HttpError(409,'This meal was replaced. Review the latest choice.','widget_conflict');
    if (!is_bool($body['eaten']??null)) throw new HttpError(422,'Choose whether this meal was eaten.');
    if (!is_int($body['version']??null) || $body['version']<1) throw new HttpError(422,'Refresh this meal record before saving.');
    // Retrying an already-applied state must preserve its original actor and timestamp.
    if ((bool)$latest['eaten_at']===$body['eaten']) return;
    if ($body['version']!==(int)$widget['version']) throw new HttpError(409,'This meal record changed. Refresh before saving.','widget_conflict');
    $today=(new DateTimeImmutable('today',new DateTimeZone('Europe/London')))->format('Y-m-d');
    if ($body['eaten'] && $latest['for_date']>$today) throw new HttpError(422,'Future meals can be recorded as eaten on their date.');
    $db->prepare('UPDATE dinner_publications SET eaten_at=' . ($body['eaten']?'UTC_TIMESTAMP(6)':'NULL') . ',eaten_by=? WHERE id=?')
        ->execute([$body['eaten']?$user['id']:null,$publicationId]);
    $db->prepare('UPDATE family_widgets SET version=version+1,updated_at=UTC_TIMESTAMP(6) WHERE id=?')->execute([$widget['id']]);
    recordActivity($db,$user,'widget',(int)$widget['id'],$body['eaten']?'meal_eaten':'meal_eaten_corrected',
        ['publicationId'=>$publicationId,'eaten'=>(bool)$latest['eaten_at']],
        ['publicationId'=>$publicationId,'eaten'=>$body['eaten'],'forDate'=>$latest['for_date'],'mealType'=>$latest['meal_type']]);
}
