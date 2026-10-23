<?php
declare(strict_types=1);

require_once __DIR__ . '/public-feeds.php';

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
        $weather = cachedPublicFeed($directory, 'forecast-v1:' . $url, 1800, 21600,
            static fn() => weekForecast(publicFeedJson($url)));
    }
    $region = $preferences['region'];
    $regions = ['england-and-wales' => 'England and Wales', 'scotland' => 'Scotland', 'northern-ireland' => 'Northern Ireland'];
    $holidays = ['status' => 'unconfigured', 'updatedAt' => null, 'data' => null];
    if (isset($regions[$region])) {
        $holidays = cachedPublicFeed($directory, 'holidays-v1:' . $region, 86400, 604800,
            static fn() => weekHolidays(publicFeedJson('https://www.gov.uk/bank-holidays.json'), $region));
    }
    return ['weather' => $weather + ['location' => $name, 'timeZone' => $zone], 'holidays' => $holidays + ['region' => $regions[$region] ?? '']];
}
