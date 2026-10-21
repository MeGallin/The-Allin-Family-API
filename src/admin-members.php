<?php
declare(strict_types=1);

function adminMemberView(array $row): array
{
    return publicFamilyUser($row) + ['enabled' => (bool)$row['is_active'],
        'createdAt' => activityTimestamp($row['created_at'])];
}

function adminMembersSnapshot(PDO $db, array $actor): array
{
    requireFamilyAdmin($actor);
    return ['members' => array_map('adminMemberView', $db->query(
        'SELECT id,username,display_name,nickname,profile_version,role,is_active,must_change_password,created_at
         FROM users ORDER BY is_active DESC,display_name,id')->fetchAll())];
}

// A deliberately explicit audit projection: credentials and hashes never enter history.
function memberAuditData(array $row): array
{
    return ['username' => $row['username'], 'displayName' => $row['display_name'],
        'nickname' => $row['nickname'], 'role' => $row['role'], 'enabled' => (bool)$row['is_active'],
        'mustChangePassword' => (bool)$row['must_change_password']];
}

function revokeMemberSessions(PDO $db, int $id, ?string $keepKey = null): void
{
    $db->prepare("UPDATE platform_sessions SET ended_at = last_seen_at, end_reason = 'revoked', was_active = 0
        WHERE user_id = ? AND ended_at IS NULL AND tracking_key <> ?")->execute([$id, $keepKey ?? '']);
}

function mutateAdminMember(PDO $db, array $actor, string $action, array $body): array
{
    requireFamilyAdmin($actor);
    if (!in_array($action, ['add', 'edit', 'password'], true)) throw new HttpError(404, 'Not found.');
    $adding = $action === 'add';
    if (!$adding && (!is_int($body['id'] ?? null) || $body['id'] < 1 ||
        !is_int($body['version'] ?? null) || $body['version'] < 1)) {
        throw new HttpError(422, 'Reload the member and try again.');
    }
    if ($action !== 'password') {
        $username = strtolower(trim(is_string($body['username'] ?? null) ? $body['username'] : ''));
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{2,63}$/', $username)) {
            throw new HttpError(422, 'Use a username of 3 to 64 letters, numbers, dots, underscores or hyphens. Start with a letter or number.');
        }
        $name = familyProfileText($body['displayName'] ?? null, 'a full name', 120);
        $nickname = familyProfileText($body['nickname'] ?? null, 'a nickname', 40, false);
        $nickname = $nickname === '' ? null : $nickname;
        $role = $body['role'] ?? null;
        if (!in_array($role, ['admin', 'member'], true) || !is_bool($body['enabled'] ?? null)) {
            throw new HttpError(422, 'Choose a role and account access.');
        }
        if ($adding && !$body['enabled']) throw new HttpError(422, 'Create an active account first.');
    }
    // Generated credentials are returned once, never persisted in plaintext or audit data.
    $temporary = $action !== 'edit' ? bin2hex(random_bytes(12)) : null;
    $hash = $temporary === null ? null : hashFamilyPassword($temporary);
    $db->beginTransaction();
    try {
        // Family-sized directory: one consistent lock order serialises concurrent admin edits.
        // Revalidate the actor here so a concurrent demotion cannot authorise another change.
        $rows = $db->query('SELECT * FROM users ORDER BY id FOR UPDATE')->fetchAll();
        $members = array_column($rows, null, 'id');
        $liveActor = $members[$actor['id']] ?? null;
        if (!$liveActor || !(bool)$liveActor['is_active'] || $liveActor['role'] !== 'admin' ||
            (int)$liveActor['auth_version'] !== (int)$actor['auth_version'] || (bool)$liveActor['must_change_password']) {
            throw new HttpError(403, 'Your account access changed. Please sign in again.', 'admin_required');
        }
        $before = null;
        if ($adding) {
            $db->prepare('INSERT INTO users (username,display_name,nickname,role,password_hash) VALUES (?,?,?,?,?)')
                ->execute([$username,$name,$nickname,$role,$hash]);
            $id = (int)$db->lastInsertId();
        } else {
            $id = $body['id'];
            $before = $members[$id] ?? null;
            if (!$before) throw new HttpError(404, 'This family account is not available.');
            if ((int)$before['profile_version'] !== $body['version']) {
                throw new HttpError(409, 'This account changed elsewhere. Load the latest details before saving.', 'profile_conflict');
            }
            if ($action === 'password') {
                if ($id === (int)$actor['id']) throw new HttpError(422, 'Use Change password from your account menu.');
                if (!(bool)$before['is_active']) throw new HttpError(422, 'Restore account access before resetting the password.');
                $db->prepare('UPDATE users SET password_hash=?,must_change_password=1,
                    auth_version=auth_version+1,profile_version=profile_version+1 WHERE id=?')->execute([$hash,$id]);
                revokeMemberSessions($db, $id);
            } else {
                if ($id === (int)$actor['id'] && ($role !== 'admin' || !$body['enabled'])) {
                    throw new HttpError(422, 'You cannot remove your own administrator access.');
                }
                $after = ['username'=>$username,'display_name'=>$name,'nickname'=>$nickname,'role'=>$role,
                    'is_active'=>$body['enabled'],'must_change_password'=>$before['must_change_password']];
                if (memberAuditData($before) !== memberAuditData($after)) {
                    $revoke = $role !== $before['role'] || (bool)$before['is_active'] !== $body['enabled'];
                    $db->prepare('UPDATE users SET username=?,display_name=?,nickname=?,role=?,is_active=?,
                        profile_version=profile_version+1,auth_version=auth_version+? WHERE id=?')
                        ->execute([$username,$name,$nickname,$role,$body['enabled'] ? 1 : 0,$revoke ? 1 : 0,$id]);
                    if ($revoke) revokeMemberSessions($db, $id);
                }
            }
        }
        $query = $db->prepare('SELECT * FROM users WHERE id=?');
        $query->execute([$id]);
        $member = $query->fetch();
        if ($adding || $action === 'password' || memberAuditData($before) !== memberAuditData($member)) {
            recordActivity($db, $liveActor, 'user_account', $id,
                $adding ? 'created' : ($action === 'password' ? 'password_reset' : 'edited'),
                $before === null ? null : memberAuditData($before), memberAuditData($member));
        }
        $db->commit();
    } catch (Throwable $error) {
        $db->rollBack();
        if ($error instanceof PDOException && ($error->errorInfo[1] ?? null) === 1062) {
            throw new HttpError(409, 'That username is already taken. Choose another username.', 'username_taken');
        }
        throw $error;
    }
    $self = currentFamilyUser($db);
    if (!$self) throw new HttpError(401, 'Please sign in again.', 'unauthenticated');
    return ['member'=>adminMemberView($member), 'self'=>publicFamilyUser($self), 'temporaryPassword'=>$temporary];
}
