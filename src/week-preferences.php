<?php
declare(strict_types=1);

function weekPreferenceRecord(PDO $db, array $user): array
{
    $query = $db->prepare('SELECT town_json, holiday_region, version FROM user_week_preferences WHERE user_id = ?');
    $query->execute([$user['id']]);
    $row = $query->fetch();
    // Defaults apply only until this person saves their own preferences.
    if (!$row) return ['town' => ['id' => 2647793, 'label' => 'Guildford, Surrey, United Kingdom',
        'latitude' => 51.23536, 'longitude' => -0.57427, 'timeZone' => 'Europe/London'],
        'region' => 'england-and-wales', 'version' => 0];
    return ['town' => $row['town_json'] !== null ? json_decode($row['town_json'], true, 16, JSON_THROW_ON_ERROR) : null,
        'region' => $row['holiday_region'], 'version' => (int)$row['version']];
}

function weekPublicPreferences(array $preferences): array
{
    $town = $preferences['town'];
    return ['town' => $town === null ? null : ['id' => $town['id'], 'label' => $town['label'], 'timeZone' => $town['timeZone']],
        'region' => $preferences['region'], 'version' => $preferences['version']];
}

function weekContextDirectory(array $config): string
{
    return trim($config['WEEK_CONTEXT_DIRECTORY']) ?: dirname($config['SESSION_DIRECTORY']) . '/week-context';
}

/** Turn only validated provider towns into selectable choices; never trust browser coordinates. */
function weekTownSearch(array $config, array $user, mixed $term): array
{
    $term = familyProfileText($term, 'a town name', 80);
    if (preg_match_all('/./us', $term) < 2) throw new HttpError(422, 'Enter at least two characters for your town.');
    $result = cachedPublicFeed(weekContextDirectory($config), 'town-search-v1:' . $term, 86400, 604800,
        static function () use ($term): array {
            $raw = publicFeedJson('https://geocoding-api.open-meteo.com/v1/search?' . http_build_query([
                'name' => $term, 'count' => 6, 'language' => 'en', 'format' => 'json',
            ]));
            if (isset($raw['error'])) throw new RuntimeException('Town search unavailable.');
            $towns = [];
            foreach (array_slice($raw['results'] ?? [], 0, 6) as $row) {
                if (!is_array($row) || !is_int($row['id'] ?? null) || !is_string($row['name'] ?? null)
                    || !is_numeric($row['latitude'] ?? null) || !is_numeric($row['longitude'] ?? null)
                    || abs((float)$row['latitude']) > 90 || abs((float)$row['longitude']) > 180
                    || !in_array($row['timezone'] ?? null, DateTimeZone::listIdentifiers(), true)
                    || !str_starts_with((string)($row['feature_code'] ?? ''), 'P')) continue;
                $parts = array_filter([$row['name'], $row['admin2'] ?? '', $row['admin1'] ?? '', $row['country'] ?? ''], 'is_string');
                $label = familyProfileText(implode(', ', array_unique(array_filter($parts))), 'a town', 200);
                $towns[] = ['id' => $row['id'], 'label' => $label, 'latitude' => (float)$row['latitude'],
                    'longitude' => (float)$row['longitude'], 'timeZone' => $row['timezone']];
            }
            return $towns;
        });
    if ($result['data'] === null) throw new HttpError(503, 'Town search is unavailable. Please try again shortly.');
    return ['towns' => array_map(static function (array $town) use ($config, $user): array {
        $payload = base64_encode(json_encode(['town' => $town, 'user' => (int)$user['id'], 'expires' => time() + 3600], JSON_THROW_ON_ERROR));
        return ['id' => $town['id'], 'label' => $town['label'], 'timeZone' => $town['timeZone'],
            'token' => $payload . '.' . hash_hmac('sha256', $payload, $config['APP_KEY'])];
    }, $result['data'])];
}

function weekTownFromToken(mixed $token, array $config, array $user): array
{
    if (!is_string($token) || strlen($token) > 3000) throw new HttpError(422, 'Choose a town from the search results.');
    $parts = explode('.', $token);
    if (count($parts) !== 2 || !hash_equals(hash_hmac('sha256', $parts[0], $config['APP_KEY']), $parts[1])) {
        throw new HttpError(422, 'Choose a town from the search results.');
    }
    $data = json_decode(base64_decode($parts[0], true) ?: '', true);
    if (!is_array($data) || ($data['user'] ?? null) !== (int)$user['id'] || ($data['expires'] ?? 0) < time() || !is_array($data['town'] ?? null)) {
        throw new HttpError(422, 'The town selection expired. Search again before saving.');
    }
    return $data['town'];
}

function saveWeekPreferences(PDO $db, array $config, array $user, array $body): array
{
    $region = $body['region'] ?? null;
    if (!is_string($region) || !in_array($region, ['', 'england-and-wales', 'scotland', 'northern-ireland'], true)
        || !is_int($body['version'] ?? null) || $body['version'] < 0) throw new HttpError(422, 'Choose your bank holiday region and reload your preferences if needed.');
    $hasTown = array_key_exists('townToken', $body);
    $selected = $hasTown && $body['townToken'] !== null ? weekTownFromToken($body['townToken'], $config, $user) : null;
    $db->beginTransaction();
    try {
        // Lock the owning user too, so first saves cannot race before a preferences row exists.
        $lock = $db->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
        $lock->execute([$user['id']]);
        $before = weekPreferenceRecord($db, $user);
        if ($before['version'] !== $body['version']) throw new HttpError(409, 'These preferences changed elsewhere. Reload them before saving.', 'week_preferences_conflict');
        $town = $hasTown ? $selected : $before['town'];
        $after = ['town' => $town, 'region' => $region, 'version' => $before['version']];
        if ($before['version'] === 0 || $town !== $before['town'] || $region !== $before['region']) {
            $after['version']++;
            $db->prepare('INSERT INTO user_week_preferences (user_id, town_json, holiday_region, version, updated_at)
                VALUES (?, ?, ?, ?, UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE town_json = VALUES(town_json),
                holiday_region = VALUES(holiday_region), version = VALUES(version), updated_at = VALUES(updated_at)')
                ->execute([$user['id'], $town === null ? null : json_encode($town, JSON_THROW_ON_ERROR), $region, $after['version']]);
            recordActivity($db, $user, 'user_profile', (int)$user['id'], 'week_preferences_changed',
                ['town' => $before['town']['label'] ?? null, 'holidayRegion' => $before['region']],
                ['town' => $town['label'] ?? null, 'holidayRegion' => $region]);
        }
        $db->commit();
        return weekPublicPreferences($after);
    } catch (Throwable $error) { $db->rollBack(); throw $error; }
}
