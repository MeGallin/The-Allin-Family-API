<?php
declare(strict_types=1);

function sudokuRow(PDO $db, int $ownerId, bool $lock = false): array|false
{
    $query = $db->prepare('SELECT * FROM user_sudoku WHERE owner_id=?' . ($lock ? ' FOR UPDATE' : ''));
    $query->execute([$ownerId]); return $query->fetch();
}
function sudokuSnapshot(PDO $db, array $user): array
{
    $row = sudokuRow($db, (int)$user['id']);
    $game = !$row || $row['deleted_at'] !== null ? null : [
        'id' => (string)$row['id'], 'title' => 'Sudoku', 'schemaVersion' => (int)$row['schema_version'],
        'difficulty' => $row['difficulty'], 'givens' => $row['givens'], 'entries' => $row['entries'],
        'notes' => json_decode($row['pencil_marks'], true, 8, JSON_THROW_ON_ERROR), 'paused' => (bool)$row['paused'],
        'version' => (int)$row['version'], 'completedAt' => $row['completed_at'] === null ? null : activityTimestamp($row['completed_at']),
        'updatedAt' => activityTimestamp($row['updated_at']),
    ];
    return ['ownerId' => (int)$user['id'], 'installationVersion' => $row ? (int)$row['version'] : 0, 'game' => $game];
}
function sudokuProgress(array $body, string $givens): array
{
    $entries = $body['entries'] ?? null; $notes = $body['notes'] ?? null; $paused = $body['paused'] ?? null;
    if (!is_string($entries) || !preg_match('/^[0-9]{81}$/D', $entries) || !is_array($notes) || count($notes) !== 81 || array_keys($notes) !== range(0, 80) || !is_bool($paused)) throw new HttpError(422, 'Send all 81 cells, pencil marks and a pause choice.');
    foreach ($notes as $cell => $mask) {
        if (!is_int($mask) || $mask < 0 || $mask > 511 || ($givens[$cell] !== '0' && ($givens[$cell] !== $entries[$cell] || $mask !== 0)) || ($entries[$cell] !== '0' && $mask !== 0)) throw new HttpError(422, 'Keep the original clues unchanged and use valid pencil marks.');
    }
    return [$entries, $notes, $paused];
}
function mutateSudoku(PDO $db, array $user, string $operation, array $body): array
{
    if (!in_array($operation, ['install', 'save', 'new', 'restart', 'remove'], true)) throw new HttpError(404, 'Unknown Sudoku action.');
    // Reuse existing UUID validation, but isolate retry keys from Dinner operations.
    $key = widgetRequestKey($user, $body, 'sudoku-' . $operation);
    $signature = hash('sha256', json_encode($body, JSON_THROW_ON_ERROR));
    if ($operation === 'install' && (!is_int($body['version'] ?? null) || $body['version'] < 0)) throw new HttpError(422, 'Refresh before installing Sudoku.');
    $prior = sudokuRow($db, (int)$user['id']);
    if ($prior && hash_equals($prior['last_request_key'], $key)) {
        if (!hash_equals($prior['last_request_hash'], $signature)) throw new HttpError(422, 'Use a fresh request after changing the game.');
        return sudokuSnapshot($db, $user);
    }
    if ($operation === 'install' && $prior && $prior['deleted_at'] === null) return sudokuSnapshot($db, $user);
    if ($operation === 'install' && $body['version'] !== ($prior ? (int)$prior['version'] : 0)) throw new HttpError(409, 'This installation changed. Refresh before installing again.', 'sudoku_conflict');
    if ($operation !== 'install') {
        if (!$prior || $prior['deleted_at'] !== null || widgetIdentifier($body['id'] ?? null) !== (string)$prior['id']) throw new HttpError(404, 'Your Sudoku installation is no longer available.');
        if (!is_int($body['version'] ?? null) || $body['version'] !== (int)$prior['version']) throw new HttpError(409, 'Your game changed in another window. Your local progress is kept; load the latest game before saving.', 'sudoku_conflict');
    }
    $difficulty = in_array($operation, ['install', 'new'], true) ? sudokuDifficulty($body['difficulty'] ?? null) : ($prior['difficulty'] ?? 'easy');
    // Proof work occurs before any transaction/owner lock. Failure cannot overwrite a game.
    $puzzle = in_array($operation, ['install', 'new'], true) ? generateSudoku($difficulty) : null;
    $progress = $operation === 'save' ? sudokuProgress($body, $prior['givens']) : null;
    $db->beginTransaction();
    try {
        $db->prepare('SELECT id FROM users WHERE id=? FOR UPDATE')->execute([$user['id']]);
        $row = sudokuRow($db, (int)$user['id'], true);
        if ($row && hash_equals($row['last_request_key'], $key)) {
            if (!hash_equals($row['last_request_hash'], $signature)) throw new HttpError(422, 'Use a fresh request after changing the game.');
            $db->commit(); return sudokuSnapshot($db, $user);
        }
        if ($operation === 'install' && $row && $row['deleted_at'] === null) { $db->commit(); return sudokuSnapshot($db, $user); }
        if ($operation === 'install' && $body['version'] !== ($row ? (int)$row['version'] : 0)) throw new HttpError(409, 'This installation changed. Refresh before installing again.', 'sudoku_conflict');
        if ($operation !== 'install' && (!$row || $row['deleted_at'] !== null || (int)$row['version'] !== $body['version'] || (string)$row['id'] !== $body['id'])) throw new HttpError(409, 'Your game changed. Your local progress is kept; load the latest game before saving.', 'sudoku_conflict');
        $event = null;
        if ($operation === 'install') {
            $notes = json_encode(array_fill(0, 81, 0), JSON_THROW_ON_ERROR);
            if ($row) $db->prepare('UPDATE user_sudoku SET schema_version=1,difficulty=?,givens=?,entries=?,pencil_marks=?,paused=0,completed_at=NULL,deleted_at=NULL,version=version+1,last_request_key=?,last_request_hash=?,updated_at=UTC_TIMESTAMP(6) WHERE id=?')->execute([$difficulty,$puzzle,$puzzle,$notes,$key,$signature,$row['id']]);
            else $db->prepare('INSERT INTO user_sudoku(owner_id,difficulty,givens,entries,pencil_marks,last_request_key,last_request_hash,created_at,updated_at) VALUES(?,?,?,?,?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))')->execute([$user['id'],$difficulty,$puzzle,$puzzle,$notes,$key,$signature]);
            $event = 'installed';
        } elseif ($operation === 'remove') {
            $db->prepare('UPDATE user_sudoku SET deleted_at=UTC_TIMESTAMP(6),updated_at=UTC_TIMESTAMP(6),version=version+1,last_request_key=?,last_request_hash=? WHERE id=?')->execute([$key,$signature,$row['id']]);
            $event = 'removed';
        } else {
            if ($operation === 'save') [$entries, $notes, $paused] = $progress;
            else { $entries = $puzzle ?? $row['givens']; $notes = array_fill(0, 81, 0); $paused = false; }
            $complete = sudokuComplete($entries);
            $completedAt = $complete ? ($row['completed_at'] ?? gmdate('Y-m-d H:i:s')) : null;
            $givens = $puzzle ?? $row['givens'];
            $db->prepare('UPDATE user_sudoku SET difficulty=?,givens=?,entries=?,pencil_marks=?,paused=?,completed_at=?,version=version+1,last_request_key=?,last_request_hash=?,updated_at=UTC_TIMESTAMP(6) WHERE id=?')->execute([$difficulty,$givens,$entries,json_encode($notes,JSON_THROW_ON_ERROR),(int)$paused,$completedAt,$key,$signature,$row['id']]);
            if ($operation === 'new') $event = 'new_game';
            elseif ($operation === 'restart') $event = 'restarted';
            elseif ($complete && $row['completed_at'] === null) $event = 'completed';
        }
        if ($event !== null) {
            $saved = sudokuRow($db, (int)$user['id']);
            recordActivity($db,$user,'widget',(int)$saved['id'],$event,null,['kind'=>'sudoku','ownerId'=>(int)$user['id'],'visibility'=>'private','difficulty'=>$saved['difficulty'],'version'=>(int)$saved['version']]);
        }
        $db->commit();
    } catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
    return sudokuSnapshot($db,$user);
}
