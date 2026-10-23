<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__) . '/src/week-context.php';
$directory = dirname(__DIR__) . '/.local/week-context-unit-' . bin2hex(random_bytes(12));
if (!mkdir($directory, 0700)) throw new RuntimeException('Cannot create isolated cache fixture.');
$passed = 0;
function checkWeek(bool $condition, string $label): void {
    global $passed;
    if (!$condition) throw new RuntimeException($label);
    $passed++;
}
try {
    checkWeek(weekValidDate('2028-02-29') && !weekValidDate('2027-02-29'), 'Civil dates validated');
    $days = weekForecast(['daily' => ['time' => ['2026-12-25', '2026-12-26'], 'weather_code' => [61, 0],
        'temperature_2m_max' => [7.4, null], 'temperature_2m_min' => [1.6, 0], 'precipitation_probability_max' => [80, 0]]]);
    checkWeek(count($days) === 1 && $days[0]['high'] === 7 && $days[0]['low'] === 2, 'Null/incomplete forecast never appears as zero');
    $holidays = ['scotland' => ['events' => [['date' => '2027-01-02', 'title' => '2nd January']]],
        'england-and-wales' => ['events' => [['date' => '2027-01-01', 'title' => "New Year’s Day"]]]];
    checkWeek(weekHolidays($holidays, 'scotland')[0]['title'] === '2nd January', 'Selected holiday region only');
    checkWeek(weekHolidays($holidays, 'england-and-wales')[0]['date'] === '2027-01-01', 'Regions do not leak dates');
    $calls = 0;
    $loader = static function () use (&$calls): array { $calls++; return [['date' => '2026-12-25']]; };
    $first = cachedPublicFeed($directory, 'unit', 1800, 21600, $loader);
    $second = cachedPublicFeed($directory, 'unit', 1800, 21600, $loader);
    checkWeek($calls === 1 && $first['status'] === 'ready' && $second === $first, 'Fresh shared cache avoids provider refresh');
    $path = $directory . '/' . hash('sha256', 'unit') . '.json';
    file_put_contents($path, json_encode(['fetchedAt' => time() - 1900, 'data' => [['date' => '2026-12-25']]]));
    $failing = static function () use (&$calls): array { $calls++; throw new RuntimeException('Simulated provider outage'); };
    $stale = cachedPublicFeed($directory, 'unit', 1800, 21600, $failing);
    $backoff = cachedPublicFeed($directory, 'unit', 1800, 21600, $failing);
    checkWeek($stale['status'] === 'stale' && $backoff['data'] === $stale['data'] && $calls === 2, 'Bounded stale response and failure backoff');
    file_put_contents($path, json_encode(['fetchedAt' => time() - 21601, 'retryAfter' => time() + 300, 'data' => [['date' => '2026-12-25']]]));
    $expired = cachedPublicFeed($directory, 'unit', 1800, 21600, $failing);
    checkWeek($expired['status'] === 'unavailable' && $expired['data'] === null, 'Expired forecast is never displayed');
    echo json_encode(['passed' => $passed, 'networkRequests' => 0]), PHP_EOL;
} finally {
    // Only files in this newly-created random fixture directory; no recursive delete.
    foreach (glob($directory . '/*') as $path) if (is_file($path)) unlink($path);
    rmdir($directory);
}
