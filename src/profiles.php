<?php
declare(strict_types=1);

function requireFamilyAdmin(array $user): void
{
    if ($user['role'] !== 'admin') throw new HttpError(403, 'Administrator access is required.', 'admin_required');
}

function adminFamilyProfiles(PDO $db): array
{
    return ['users' => array_map('publicFamilyUser', $db->query('SELECT id, username, display_name, nickname, profile_version, role, must_change_password FROM users WHERE is_active = 1 ORDER BY display_name, id')->fetchAll())];
}

function changeFamilyNickname(PDO $db, array $actor, array $body, bool $admin): array
{
    if ($admin) requireFamilyAdmin($actor);
    $id = $admin ? ($body['id'] ?? null) : (int) $actor['id'];
    if (!is_int($id) || $id < 1 || !isset($body['version']) || !is_int($body['version']) || $body['version'] < 1) {
        throw new HttpError(422, 'Reload the profile and try again.');
    }
    $nickname = $body['nickname'] ?? null;
    if (!is_string($nickname) || preg_match('//u', $nickname) !== 1 ||
        preg_match('/[\p{Cc}\p{Zl}\p{Zp}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', $nickname)) {
        throw new HttpError(422, 'Enter a nickname without line breaks or control characters.');
    }
    $nickname = trim(preg_replace('/\s+/u', ' ', $nickname));
    if (preg_match_all('/./us', $nickname) > 40) throw new HttpError(422, 'Use a nickname of up to 40 characters.');
    $nickname = $nickname === '' ? null : $nickname;
    $db->beginTransaction();
    try {
        $query = $db->prepare('SELECT * FROM users WHERE id = ? AND is_active = 1 FOR UPDATE');
        $query->execute([$id]);
        $profile = $query->fetch();
        if (!$profile) throw new HttpError(404, 'This family account is not available.');
        if ((int) $profile['profile_version'] !== $body['version']) {
            throw new HttpError(409, 'This nickname changed elsewhere. Load the latest version before saving.', 'profile_conflict');
        }
        if ($profile['nickname'] !== $nickname) {
            $db->prepare('UPDATE users SET nickname = ?, profile_version = profile_version + 1 WHERE id = ?')->execute([$nickname, $id]);
            recordActivity($db, $actor, 'user_profile', $id, 'nickname_changed', ['nickname' => $profile['nickname']], ['nickname' => $nickname]);
            $profile['nickname'] = $nickname;
            $profile['profile_version']++;
        }
        $db->commit();
    } catch (Throwable $error) {
        $db->rollBack();
        throw $error;
    }
    $self = currentFamilyUser($db);
    if (!$self) throw new HttpError(401, 'Please sign in.', 'unauthenticated');
    return ['profile' => publicFamilyUser($profile), 'self' => publicFamilyUser($self)];
}
