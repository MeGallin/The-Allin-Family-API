<?php
declare(strict_types=1);

/** Original ALLIN picture patterns; fixed identifiers, no third-party catalogue. */
function nonogramBank(): array
{
    return [
        'sprout-5'=>['name'=>'Sprout','rows'=>['00100','01110','00100','10101','01110']],
        'cup-5'=>['name'=>'Cup','rows'=>['00000','11110','10011','11110','01100']],
        'house-5'=>['name'=>'House','rows'=>['00100','01110','11111','11011','11011']],
        'lantern-10'=>['name'=>'Lantern','rows'=>['0001111000','0001001000','0011111100','0111111110','0110110110','0110110110','0111111110','0011111100','0001111000','0011111100']],
        'tree-10'=>['name'=>'Tree','rows'=>['0000110000','0001111000','0011111100','0001111000','0011111100','0111111110','1111111111','0000110000','0000110000','0001111000']],
        'boat-10'=>['name'=>'Boat','rows'=>['0000100000','0000110000','0000111000','0000111100','0000111110','0000100000','1111111111','0111111110','0011111100','0001111000']],
    ];
}
function nonogramClue(string $line): array
{
    preg_match_all('/1+/', $line, $runs);
    return $runs[0] ? array_map('strlen', $runs[0]) : [0];
}
function nonogramPuzzle(string $id): array
{
    $p=nonogramBank()[$id]??null;
    if(!$p) throw new RuntimeException('Unknown Nonograms puzzle.');
    $size=count($p['rows']); $columns=[];
    for($c=0;$c<$size;$c++) {
        $line=''; foreach($p['rows'] as $row) $line.=$row[$c];
        $columns[]=nonogramClue($line);
    }
    return ['puzzleId'=>$id,'name'=>$p['name'],'size'=>$size,'rowClues'=>array_map('nonogramClue',$p['rows']),'columnClues'=>$columns];
}
function nonogramRow(PDO $db,int $owner): array|false
{
    $q=$db->prepare('SELECT * FROM user_nonograms WHERE owner_id=?');
    $q->execute([$owner]); return $q->fetch();
}
function nonogramSnapshot(PDO $db,array $user): array
{
    $row=nonogramRow($db,(int)$user['id']);
    $played=$row?json_decode($row['played'],true,16,JSON_THROW_ON_ERROR):[];
    $game=!$row || $row['deleted_at']!==null?null:array_merge(nonogramPuzzle($row['puzzle_id']),[
        'id'=>'nonograms','title'=>'Nonograms','version'=>(int)$row['version'],
        'cells'=>$row['cells'],'assisted'=>(bool)$row['assisted'],
        'completedAt'=>$row['completed_at']===null?null:activityTimestamp($row['completed_at']),
        'updatedAt'=>activityTimestamp($row['updated_at']),'replay'=>(bool)$row['replay'],
    ]);
    return ['ownerId'=>(int)$user['id'],'installationVersion'=>$row?(int)$row['version']:0,'game'=>$game,'played'=>$played,
        'catalogue'=>array_map(static fn($id)=>array_merge(nonogramPuzzle($id),['played'=>in_array($id,$played,true)]),array_keys(nonogramBank()))];
}
function nonogramComplete(string $cells,string $id): bool
{
    $answer=implode('',nonogramBank()[$id]['rows']);
    for($i=0;$i<strlen($answer);$i++) if(($cells[$i]==='1')!==($answer[$i]==='1')) return false;
    return true;
}
function mutateNonogram(PDO $db,array $user,string $action,array $body): array
{
    if(!in_array($action,['install','save','new','restart','remove'],true)) throw new HttpError(404,'Unknown Nonograms action.');
    $key=widgetRequestKey($user,$body,'nonograms');
    ksort($body); $signature=hash('sha256',$action.json_encode($body,JSON_THROW_ON_ERROR));
    $owner=(int)$user['id']; $db->beginTransaction();
    try {
        $db->prepare('SELECT id FROM users WHERE id=? FOR UPDATE')->execute([$owner]);
        $q=$db->prepare('SELECT request_hash,response FROM nonogram_receipts WHERE owner_id=? AND request_key=?');
        $q->execute([$owner,$key]); $prior=$q->fetch();
        if($prior) {
            if(!hash_equals($prior['request_hash'],$signature)) throw new HttpError(422,'Use a fresh request after changing your action.');
            $db->commit(); return json_decode($prior['response'],true,32,JSON_THROW_ON_ERROR);
        }
        $row=nonogramRow($db,$owner);
        if(!is_int($body['version']??null) || $body['version']!==($row?(int)$row['version']:0))
            throw new HttpError(409,'Your puzzle changed in another window. Your local marks are kept. Load the latest board before continuing.','nonogram_conflict');
        if($action==='install') {
            if($row && $row['deleted_at']===null) throw new HttpError(409,'Nonograms is already installed. Open your latest board.','nonogram_conflict');
        } elseif(!$row || $row['deleted_at']!==null || ($body['id']??null)!=='nonograms' || ($body['puzzleId']??null)!==$row['puzzle_id'])
            throw new HttpError(409,'This puzzle is no longer active. Load the latest board.','nonogram_conflict');
        $played=$row?json_decode($row['played'],true,16,JSON_THROW_ON_ERROR):[];
        $id=$row['puzzle_id']??''; $cells=$row['cells']??''; $assisted=$row?(bool)$row['assisted']:false;
        $replay=$row?(bool)$row['replay']:false; $completed=$row['completed_at']??null;
        if(in_array($action,['install','new'],true)) {
            $id=$body['nextPuzzleId']??null;
            if(!is_string($id) || !isset(nonogramBank()[$id])) throw new HttpError(422,'Choose a puzzle from the collection.');
            $replay=in_array($id,$played,true); $cells=str_repeat('0',nonogramPuzzle($id)['size']**2); $assisted=false; $completed=null;
            if(!$replay) $played[]=$id;
        } elseif($action==='restart') {
            $cells=str_repeat('0',strlen($cells)); $assisted=false; $completed=null;
        } elseif($action==='save') {
            $cells=$body['cells']??null; $assisted=$body['assisted']??null;
            if(!is_string($cells) || strlen($cells)!==nonogramPuzzle($id)['size']**2 || !preg_match('/^[012]+$/D',$cells) || !is_bool($assisted))
                throw new HttpError(422,'Send valid marks for every square.');
            $assisted=$assisted || (bool)$row['assisted'];
            $completed=nonogramComplete($cells,$id)?($completed??gmdate('Y-m-d H:i:s')):null;
        }
        $version=($row?(int)$row['version']:0)+1; $deleted=$action==='remove'?gmdate('Y-m-d H:i:s'):null;
        if($row) $db->prepare('UPDATE user_nonograms SET puzzle_id=?,cells=?,assisted=?,completed_at=?,played=?,replay=?,version=?,deleted_at=?,updated_at=UTC_TIMESTAMP(6) WHERE owner_id=?')->execute([$id,$cells,(int)$assisted,$completed,json_encode($played,JSON_THROW_ON_ERROR),(int)$replay,$version,$deleted,$owner]);
        else $db->prepare('INSERT INTO user_nonograms(owner_id,puzzle_id,cells,assisted,completed_at,played,replay,version,deleted_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6))')->execute([$owner,$id,$cells,(int)$assisted,$completed,json_encode($played,JSON_THROW_ON_ERROR),(int)$replay,$version,$deleted]);
        // No activity/shared revision: installation, progress and completion remain owner-private.
        $response=nonogramSnapshot($db,$user);
        $db->prepare('INSERT INTO nonogram_receipts(owner_id,request_key,request_hash,response) VALUES(?,?,?,?)')->execute([$owner,$key,$signature,json_encode($response,JSON_THROW_ON_ERROR)]);
        $db->commit(); return $response;
    } catch(Throwable $error) {if($db->inTransaction())$db->rollBack();throw $error;}
}
