<?php
declare(strict_types=1);

/** Only fixed public providers are fetched; no user-supplied URL or personal event data. */
function weekProviderJson(string $url): array
{
    if (!function_exists('curl_init')) throw new RuntimeException('Weather transport unavailable.');
    $curl = curl_init($url);
    $body = '';
    curl_setopt_array($curl, [
        CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 4,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_USERAGENT => 'AllinFamily/1.0 (private family dashboard)',
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
            if (strlen($body) + strlen($chunk) > 1048576) return 0;
            $body .= $chunk;
            return strlen($chunk);
        },
    ]);
    try {
        if (curl_exec($curl) === false || curl_getinfo($curl, CURLINFO_RESPONSE_CODE) !== 200) {
            throw new RuntimeException('Public information provider unavailable.');
        }
        $value = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($value)) throw new RuntimeException('Invalid public information response.');
        return $value;
    } finally { curl_close($curl); }
}

/** Bounded stale fallback, non-blocking refresh lock and five-minute failure backoff. */
function weekCachedFeed(string $directory, string $key, int $ttl, int $maxAge, callable $load): array
{
    $unavailable = ['status' => 'unavailable', 'updatedAt' => null, 'data' => null];
    if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) return $unavailable;
    $path = $directory . '/' . hash('sha256', $key) . '.json';
    $read = static function () use ($path): array {
        $raw = @file_get_contents($path, false, null, 0, 1048577);
        if (!is_string($raw) || strlen($raw) > 1048576) return [];
        $value = json_decode($raw, true);
        return is_array($value) ? $value : [];
    };
    $result = static function (array $cached) use ($ttl, $maxAge, $unavailable): array {
        $at = $cached['fetchedAt'] ?? 0;
        $age = time() - (is_int($at) ? $at : 0);
        if ($age < 0 || $age > $maxAge || !isset($cached['data']) || !is_array($cached['data'])) return $unavailable;
        return ['status' => $age < $ttl ? 'ready' : 'stale', 'updatedAt' => gmdate('c', $at), 'data' => $cached['data']];
    };
    $cached = $read();
    $snapshot = $result($cached);
    if ($snapshot['status'] === 'ready' || ($cached['retryAfter'] ?? 0) > time()) return $snapshot;
    $lock = @fopen($path . '.lock', 'c');
    if (!$lock) return $snapshot;
    try {
        if (!flock($lock, LOCK_EX | LOCK_NB)) return $snapshot;
        $cached = $read();
        $snapshot = $result($cached);
        if ($snapshot['status'] === 'ready' || ($cached['retryAfter'] ?? 0) > time()) return $snapshot;
        try {
            $cached = ['fetchedAt' => time(), 'data' => $load()];
        } catch (Throwable $error) {
            // No upstream body, coordinates, credentials or family information in logs.
            error_log('Week Ahead public feed refresh unavailable.');
            $cached['retryAfter'] = time() + 300;
        }
        $temporary = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        if (@file_put_contents($temporary, json_encode($cached, JSON_THROW_ON_ERROR), LOCK_EX) !== false) {
            @chmod($temporary, 0600);
            if (!@rename($temporary, $path)) @unlink($temporary);
        }
        return $result($cached);
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}

function weekValidDate(mixed $value): bool
{
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) return false;
    [$year, $month, $day] = array_map('intval', explode('-', $value));
    return checkdate($month, $day, $year);
}

function weekForecast(array $raw): array
{
    $daily = $raw['daily'] ?? [];
    if (!is_array($daily) || !is_array($daily['time'] ?? null)) throw new RuntimeException('Missing forecast.');
    $days = [];
    foreach (array_slice($daily['time'], 0, 7) as $index => $date) {
        $values = [];
        foreach (['weather_code', 'temperature_2m_max', 'temperature_2m_min', 'precipitation_probability_max'] as $field) {
            $values[$field] = $daily[$field][$index] ?? null;
        }
        if (!weekValidDate($date) || count(array_filter($values, 'is_numeric')) !== 4
            || $values['temperature_2m_max'] < -100 || $values['temperature_2m_max'] > 70
            || $values['temperature_2m_min'] < -100 || $values['temperature_2m_min'] > 70
            || $values['temperature_2m_min'] > $values['temperature_2m_max']
            || $values['weather_code'] < 0 || $values['weather_code'] > 99
            || $values['precipitation_probability_max'] < 0 || $values['precipitation_probability_max'] > 100) continue;
        $days[] = ['date' => $date, 'code' => (int)$values['weather_code'],
            'high' => (int)round((float)$values['temperature_2m_max']), 'low' => (int)round((float)$values['temperature_2m_min']),
            'rain' => (int)round((float)$values['precipitation_probability_max'])];
    }
    if (!$days) throw new RuntimeException('Invalid forecast.');
    return $days;
}

function weekHolidays(array $raw, string $region): array
{
    $events = $raw[$region]['events'] ?? null;
    if (!is_array($events) || !$events) throw new RuntimeException('Missing holiday dates.');
    $days = [];
    foreach ($events as $event) {
        if (!is_array($event) || !weekValidDate($event['date'] ?? null) || !is_string($event['title'] ?? null)
            || strlen($event['title']) > 200 || preg_match('//u', $event['title']) !== 1) continue;
        $days[] = ['date' => $event['date'], 'title' => $event['title']];
    }
    if (!$days) throw new RuntimeException('Invalid holiday dates.');
    return $days;
}

function weekContextSnapshot(array $config, array $preferences): array
{
    $directory = weekContextDirectory($config);
    $town = $preferences['town'];
    $name = $town['label'] ?? '';
    $lat = $town['latitude'] ?? null;
    $lon = $town['longitude'] ?? null;
    $zone = $town['timeZone'] ?? 'Europe/London';
    $weather = ['status' => 'unconfigured', 'updatedAt' => null, 'data' => null];
    if ($town !== null) {
        $url = 'https://api.open-meteo.com/v1/forecast?' . http_build_query([
            'latitude' => $lat, 'longitude' => $lon, 'timezone' => $zone, 'forecast_days' => 7,
            'daily' => 'weather_code,temperature_2m_max,temperature_2m_min,precipitation_probability_max',
            'temperature_unit' => 'celsius',
        ]);
        $weather = weekCachedFeed($directory, 'forecast-v1:' . $url, 1800, 21600,
            static fn() => weekForecast(weekProviderJson($url)));
    }
    $region = $preferences['region'];
    $regions = ['england-and-wales' => 'England and Wales', 'scotland' => 'Scotland', 'northern-ireland' => 'Northern Ireland'];
    $holidays = ['status' => 'unconfigured', 'updatedAt' => null, 'data' => null];
    if (isset($regions[$region])) {
        $holidays = weekCachedFeed($directory, 'holidays-v1:' . $region, 86400, 604800,
            static fn() => weekHolidays(weekProviderJson('https://www.gov.uk/bank-holidays.json'), $region));
    }
    return ['weather' => $weather + ['location' => $name, 'timeZone' => $zone], 'holidays' => $holidays + ['region' => $regions[$region] ?? '']];
}
