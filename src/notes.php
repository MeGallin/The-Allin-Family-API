<?php
declare(strict_types=1);

function personalNoteDraft(array $body): array
{
    $draft = [];
    foreach (['title' => 120, 'header' => 500, 'body' => 20000, 'footer' => 500] as $field => $max) {
        $value = $body[$field] ?? '';
        if (!is_string($value) || preg_match('//u', $value) !== 1) throw new HttpError(422, 'Enter valid note text.');
        $value = trim(str_replace(["\r\n", "\r"], "\n", $value));
        $length = preg_match_all('/./us', $value);
        if ($length === false || $length > $max || (in_array($field, ['title', 'body'], true) && $length === 0) ||
            preg_match($field === 'title' ? '/[\x00-\x1f\x7f]/' : '/[\x00-\x08\x0b-\x1f\x7f]/', $value)) {
            throw new HttpError(422, 'Use ' . (in_array($field, ['title', 'body'], true) ? '1 to ' : 'up to ') . $max . ' characters for the note ' . $field . '.');
        }
        $draft[$field] = $value;
    }
    $date = $body['noteDate'] ?? null;
    if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)) throw new HttpError(422, 'Choose a valid note date.');
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date || substr($date, 0, 4) < '1000') throw new HttpError(422, 'Choose a valid note date.');
    $draft['noteDate'] = $date;
    return $draft;
}

function personalNoteFields(array $row): array
{
    return ['title' => $row['title'], 'header' => $row['header'], 'body' => $row['body'], 'footer' => $row['footer'], 'noteDate' => $row['note_date']];
}

function personalNoteHistorySummary(?string $data): ?array
{
    if ($data === null) return null;
    $draft = json_decode($data, true, 16, JSON_THROW_ON_ERROR);
    return ['title' => $draft['title']];
}

function personalNotesSnapshot(PDO $db, array $user): array
{
    // Ownership always comes from the authenticated session, including for admins.
    $db->beginTransaction();
    try {
        $query = $db->prepare('SELECT * FROM personal_notes WHERE owner_id = ? AND deleted_at IS NULL ORDER BY updated_at DESC, id DESC');
        $query->execute([$user['id']]);
        $rows = $query->fetchAll();
        $query = $db->prepare('SELECT h.* FROM personal_note_history h JOIN personal_notes n ON n.id = h.note_id
            WHERE h.owner_id = ? AND n.owner_id = ? ORDER BY h.id DESC LIMIT 50');
        $query->execute([$user['id'], $user['id']]);
        $history = $query->fetchAll();
        $db->commit();
    } catch (Throwable $error) {
        $db->rollBack();
        throw $error;
    }
    return ['ownerId' => (int) $user['id'],
        'notes' => array_map(static fn(array $row): array => personalNoteFields($row) + [
            'id' => (string) $row['id'], 'version' => (int) $row['version'],
            'createdAt' => activityTimestamp($row['created_at']), 'updatedAt' => activityTimestamp($row['updated_at']),
        ], $rows),
        'activity' => array_map(static fn(array $row): array => [
            'id' => (string) $row['id'], 'noteId' => (string) $row['note_id'], 'action' => $row['action'],
            'occurredAt' => activityTimestamp($row['occurred_at']),
            'before' => personalNoteHistorySummary($row['before_data']),
            'after' => personalNoteHistorySummary($row['after_data']),
        ], $history),
    ];
}

function mutatePersonalNote(PDO $db, array $user, string $operation, array $body): array
{
    $draft = in_array($operation, ['add', 'edit'], true) ? personalNoteDraft($body) : null;
    if ($operation === 'add') {
        if (!isset($body['requestKey']) || !is_string($body['requestKey']) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $body['requestKey'])) throw new HttpError(422, 'Reopen the note form and try again.');
    } elseif (!isset($body['id'], $body['version']) || !is_string($body['id']) || !ctype_digit($body['id']) || strlen($body['id']) > 18 || !is_int($body['version']) || $body['version'] < 1) {
        throw new HttpError(422, 'Refresh your notes and try again.');
    }
    $db->beginTransaction();
    try {
        $before = null;
        $changed = true;
        if ($operation === 'add') {
            $key = hash('sha256', $user['id'] . ':' . $body['requestKey']);
            $query = $db->prepare('INSERT INTO personal_notes (owner_id, title, header, body, footer, note_date, created_at, updated_at, request_key)
                VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), ?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)');
            $query->execute([$user['id'], $draft['title'], $draft['header'], $draft['body'], $draft['footer'], $draft['noteDate'], $key]);
            $id = (int) $db->lastInsertId();
            $changed = $query->rowCount() === 1;
        } else {
            $query = $db->prepare('SELECT * FROM personal_notes WHERE id = ? AND owner_id = ? AND deleted_at IS NULL FOR UPDATE');
            $query->execute([$body['id'], $user['id']]);
            $note = $query->fetch();
            // Identical response for a missing note or another account's note.
            if (!$note) throw new HttpError(404, 'This note is not available in your notebook.', 'note_unavailable');
            if ((int) $note['version'] !== $body['version']) throw new HttpError(409, 'This note changed in another window. Reopen the latest version before saving.', 'note_conflict');
            $id = (int) $note['id'];
            $before = personalNoteFields($note);
            $changed = $operation === 'remove' || $before !== $draft;
            if ($changed) {
                $after = $operation === 'remove' ? $before : $draft;
                $db->prepare('UPDATE personal_notes SET title = ?, header = ?, body = ?, footer = ?, note_date = ?, updated_at = UTC_TIMESTAMP(6), version = version + 1,
                    deleted_at = ' . ($operation === 'remove' ? 'UTC_TIMESTAMP(6)' : 'NULL') . ' WHERE id = ? AND owner_id = ?')
                    ->execute([$after['title'], $after['header'], $after['body'], $after['footer'], $after['noteDate'], $id, $user['id']]);
            }
        }
        if ($changed) {
            // Private note text never enters the shared family activity table/feed.
            $db->prepare('INSERT INTO personal_note_history (note_id, owner_id, action, occurred_at, before_data, after_data) VALUES (?, ?, ?, UTC_TIMESTAMP(6), ?, ?)')
                ->execute([$id, $user['id'], $operation === 'add' ? 'created' : ($operation === 'edit' ? 'edited' : 'removed'),
                    $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
                    $operation === 'remove' ? null : json_encode($draft, JSON_THROW_ON_ERROR)]);
        }
        $db->commit();
    } catch (Throwable $error) {
        $db->rollBack();
        throw $error;
    }
    return personalNotesSnapshot($db, $user);
}
