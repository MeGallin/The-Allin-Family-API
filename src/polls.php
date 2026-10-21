<?php
declare(strict_types=1);

function pollText(array $body, string $field, int $limit, bool $required): string
{
    $value = $body[$field] ?? '';
    if (!is_string($value) || preg_match('//u', $value) !== 1) {
        throw new HttpError(422, 'Enter valid poll text.');
    }
    $value = trim(str_replace(["\r\n", "\r"], "\n", $value));
    $length = preg_match_all('/./us', $value);
    $controls = $field === 'detail' ? '/[\x00-\x08\x0b-\x1f\x7f]/' : '/[\x00-\x1f\x7f]/';
    if ($length === false || $length > $limit || ($required && $length === 0) || preg_match($controls, $value)) {
        throw new HttpError(422, 'Use ' . ($required ? '1 to ' : 'up to ') . $limit . ' characters for the poll ' . $field . '.');
    }
    return $value;
}

function pollOptions(array $body): array
{
    $options = $body['options'] ?? null;
    if (!is_array($options) || count($options) < 2 || count($options) > 6 || array_keys($options) !== range(0, count($options) - 1)) {
        throw new HttpError(422, 'Give the poll two to six choices.');
    }
    $labels = array_map(static fn($option): string => pollText(['choice' => $option], 'choice', 80, true), $options);
    foreach ($labels as $index => $label) {
        foreach (array_slice($labels, $index + 1) as $other) {
            if (preg_match('~\A' . preg_quote($label, '~') . '\z~iu', $other) === 1) {
                throw new HttpError(422, 'Use different names for each choice.');
            }
        }
    }
    return $labels;
}

function pollAudit(array $row): array
{
    return ['title' => $row['title'], 'detail' => $row['detail'], 'closed' => (bool) $row['closed']];
}

function pollsSnapshot(PDO $db, array $user): array
{
    $db->beginTransaction();
    try {
        $polls = $db->query('SELECT p.*, c.display_name AS creator_name, c.nickname AS creator_nickname,
            u.display_name AS updater_name, u.nickname AS updater_nickname
            FROM polls p JOIN users c ON c.id = p.created_by JOIN users u ON u.id = p.updated_by
            WHERE p.deleted_at IS NULL ORDER BY p.closed, p.created_at DESC, p.id DESC')->fetchAll();
        $options = $db->query('SELECT o.id, o.poll_id, o.label, COUNT(v.user_id) AS votes
            FROM poll_options o JOIN polls p ON p.id = o.poll_id AND p.deleted_at IS NULL
            LEFT JOIN poll_votes v ON v.poll_id = o.poll_id AND v.option_id = o.id
            GROUP BY o.id, o.poll_id, o.label, o.position ORDER BY o.poll_id, o.position')->fetchAll();
        $query = $db->prepare('SELECT poll_id, option_id, version FROM poll_votes WHERE user_id = ?');
        $query->execute([$user['id']]);
        $own = [];
        foreach ($query->fetchAll() as $vote) $own[$vote['poll_id']] = $vote;
        $db->commit();
    } catch (Throwable $error) {
        $db->rollBack();
        throw $error;
    }
    $choices = [];
    foreach ($options as $option) {
        $choices[$option['poll_id']][] = ['id' => (string) $option['id'], 'label' => $option['label'], 'votes' => (int) $option['votes']];
    }
    return ['polls' => array_map(static function (array $poll) use ($choices, $own): array {
        $options = $choices[$poll['id']] ?? [];
        return pollAudit($poll) + [
            'id' => (string) $poll['id'], 'version' => (int) $poll['version'], 'options' => $options,
            'totalVotes' => array_sum(array_column($options, 'votes')),
            'ownOptionId' => isset($own[$poll['id']]['option_id']) ? (string) $own[$poll['id']]['option_id'] : null,
            'voteVersion' => (int) ($own[$poll['id']]['version'] ?? 0),
            'createdBy' => ['id' => (int) $poll['created_by'], 'displayName' => familyDisplayName([
                'display_name' => $poll['creator_name'], 'nickname' => $poll['creator_nickname'],
            ])],
            'updatedBy' => ['id' => (int) $poll['updated_by'], 'displayName' => familyDisplayName([
                'display_name' => $poll['updater_name'], 'nickname' => $poll['updater_nickname'],
            ])],
            'createdAt' => activityTimestamp($poll['created_at']), 'updatedAt' => activityTimestamp($poll['updated_at']),
        ];
    }, $polls)];
}

// Caller holds the poll row lock and the transaction containing the activity write.
function savePollVote(PDO $db, array $user, array $poll, array $body): void
{
    if ($poll['closed']) throw new HttpError(409, 'Voting has closed for this poll.', 'poll_closed');
    $query = $db->prepare('SELECT id, label FROM poll_options WHERE poll_id = ?');
    $query->execute([$poll['id']]);
    $labels = [];
    foreach ($query->fetchAll() as $option) $labels[(string) $option['id']] = $option['label'];
    $optionId = $body['optionId'];
    if ($optionId !== null && !isset($labels[$optionId])) throw new HttpError(422, 'Choose an option from this poll.');

    $query = $db->prepare('SELECT option_id, version FROM poll_votes WHERE poll_id = ? AND user_id = ? FOR UPDATE');
    $query->execute([$poll['id'], $user['id']]);
    $old = $query->fetch();
    $oldId = $old && $old['option_id'] !== null ? (string) $old['option_id'] : null;
    if ($oldId === $optionId) return; // Repeated votes/retries create no duplicates or false activity.
    if ((int) ($old['version'] ?? 0) !== $body['voteVersion']) {
        throw new HttpError(409, 'Your vote changed in another window. Review it before voting again.', 'vote_conflict');
    }
    $query = $db->prepare('INSERT INTO poll_votes (poll_id, user_id, option_id, version, updated_at)
        VALUES (?, ?, ?, 1, UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE
        option_id = VALUES(option_id), version = version + 1, updated_at = UTC_TIMESTAMP(6)');
    $query->execute([$poll['id'], $user['id'], $optionId]);
    recordActivity($db, $user, 'poll', (int) $poll['id'],
        $optionId === null ? 'vote_withdrawn' : ($oldId === null ? 'voted' : 'vote_changed'),
        ['title' => $poll['title'], 'choice' => $oldId === null ? null : $labels[$oldId]],
        ['title' => $poll['title'], 'choice' => $optionId === null ? null : $labels[$optionId]]);
}

function savePollDetails(PDO $db, array $user, array $poll, string $operation, array $body, ?array $draft): void
{
    if ($user['role'] !== 'admin' && (int) $poll['created_by'] !== (int) $user['id']) {
        throw new HttpError(403, 'Only the poll creator or an administrator can manage this poll.', 'poll_manage_required');
    }
    $before = pollAudit($poll);
    $after = $operation === 'edit' ? $draft + ['closed' => (bool) $poll['closed']]
        : ($operation === 'close' ? ['title' => $poll['title'], 'detail' => $poll['detail'], 'closed' => $body['closed']] : null);
    if ($operation !== 'remove' && $before === $after) return;
    $data = $after ?? $before;
    $deletedAt = $operation === 'remove' ? 'UTC_TIMESTAMP(6)' : 'NULL';
    $db->prepare('UPDATE polls SET title = ?, detail = ?, closed = ?, updated_by = ?,
        updated_at = UTC_TIMESTAMP(6), version = version + 1, deleted_at = ' . $deletedAt . ' WHERE id = ?')
        ->execute([$data['title'], $data['detail'], (int) $data['closed'], $user['id'], $poll['id']]);
    recordActivity($db, $user, 'poll', (int) $poll['id'],
        $operation === 'remove' ? 'removed' : ($operation === 'edit' ? 'edited' : ($body['closed'] ? 'closed' : 'reopened')),
        $before, $after);
}

function mutatePoll(PDO $db, array $user, string $operation, array $body): array
{
    $draft = in_array($operation, ['add', 'edit'], true) ? [
        'title' => pollText($body, 'title', 160, true), 'detail' => pollText($body, 'detail', 1000, false),
    ] : null;
    $choices = $operation === 'add' ? pollOptions($body) : [];
    if ($operation === 'add') {
        if (!isset($body['requestKey']) || !is_string($body['requestKey']) ||
            !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $body['requestKey'])) {
            throw new HttpError(422, 'Reopen the poll form and try again.');
        }
    } elseif (!isset($body['id'], $body['version']) || !is_string($body['id']) || !ctype_digit($body['id']) ||
        strlen($body['id']) > 18 || !is_int($body['version']) || $body['version'] < 1) {
        throw new HttpError(422, 'Refresh the polls and try again.');
    }
    if ($operation === 'close' && (!isset($body['closed']) || !is_bool($body['closed']))) {
        throw new HttpError(422, 'Choose whether voting is closed.');
    }
    if ($operation === 'vote' && (!array_key_exists('optionId', $body) ||
        ($body['optionId'] !== null && (!is_string($body['optionId']) || !ctype_digit($body['optionId']) || strlen($body['optionId']) > 18)) ||
        !isset($body['voteVersion']) || !is_int($body['voteVersion']) || $body['voteVersion'] < 0)) {
        throw new HttpError(422, 'Choose an option and refresh your vote.');
    }

    $db->beginTransaction();
    try {
        if ($operation === 'add') {
            $key = hash('sha256', $user['id'] . ':' . $body['requestKey']);
            $query = $db->prepare('INSERT INTO polls (title, detail, created_by, updated_by, created_at, updated_at, request_key)
                VALUES (?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), ?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)');
            $query->execute([$draft['title'], $draft['detail'], $user['id'], $user['id'], $key]);
            $id = (int) $db->lastInsertId();
            if ($query->rowCount() === 1) {
                $query = $db->prepare('INSERT INTO poll_options (poll_id, label, position) VALUES (?, ?, ?)');
                foreach ($choices as $position => $label) $query->execute([$id, $label, $position]);
                recordActivity($db, $user, 'poll', $id, 'created', null, $draft + ['closed' => false, 'choices' => $choices]);
            }
        } else {
            // Serialise close/remove and voting; ballot revisions remain independent per person.
            $query = $db->prepare('SELECT * FROM polls WHERE id = ? AND deleted_at IS NULL FOR UPDATE');
            $query->execute([$body['id']]);
            $poll = $query->fetch();
            if (!$poll) throw new HttpError(404, 'This poll is no longer available.', 'poll_unavailable');
            if ((int) $poll['version'] !== $body['version']) {
                throw new HttpError(409, 'This poll changed. Review the latest version before trying again.', 'poll_conflict');
            }
            if ($operation === 'vote') savePollVote($db, $user, $poll, $body);
            else savePollDetails($db, $user, $poll, $operation, $body, $draft);
        }
        $db->commit();
    } catch (Throwable $error) {
        $db->rollBack();
        throw $error;
    }
    return pollsSnapshot($db, $user);
}
