<?php
declare(strict_types=1);
require_once __DIR__ . '/public-feeds.php';

function warningAreaKey(string $value): string
{
    $value = function_exists('mb_strtolower') ? mb_strtolower(trim($value), 'UTF-8') : strtolower(trim($value));
    $value = preg_replace('/^(city of |county of )| council$/u', '', $value);
    $value = trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value));
    return $value === 'greater london' ? 'london' : $value;
}

function warningUtc(string $clock, string $weekday, string $day, string $month, int $year): DateTimeImmutable
{
    $value = DateTimeImmutable::createFromFormat('!Y j M Hi', "$year $day $month $clock", new DateTimeZone('UTC'));
    $errors = DateTimeImmutable::getLastErrors();
    if (!$value || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))
        || $value->format('D') !== $weekday) throw new RuntimeException('Invalid warning date.');
    return $value;
}

/** Parse only the public RSS schema; never fetch provider-supplied enclosure URLs. */
function weatherWarningNotices(string $xml): array
{
    if (strlen($xml) > 1048576 || preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) throw new RuntimeException('Unsafe warning feed.');
    $previous = libxml_use_internal_errors(true);
    try { $rss = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET); }
    finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
    if (!$rss || $rss->getName() !== 'rss' || !isset($rss->channel)
        || !str_starts_with((string)$rss->channel->title, 'Met Office warnings for ')
        || count($rss->channel->item) > 300) throw new RuntimeException('Invalid warning feed.');
    $result = [];
    foreach ($rss->channel->item as $item) {
        $title = trim((string)$item->title);
        $description = trim((string)$item->description);
        $url = trim((string)$item->link);
        $parts = parse_url($url);
        if (strlen($title) > 500 || strlen($description) > 12000 || preg_match('//u', $description) !== 1
            || !is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
            || !in_array($parts['host'] ?? '', ['www.metoffice.gov.uk', 'weather.metoffice.gov.uk'], true)
            || ($parts['path'] ?? '') !== '/weather/warnings-and-advice/uk-warnings'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
            || preg_match('/[\x00-\x20\x7f]/', $url)) throw new RuntimeException('Invalid warning notice.');
        parse_str(ltrim($parts['fragment'] ?? '', '?'), $query);
        if (!is_string($query['id'] ?? null) || !is_string($query['date'] ?? null)
            || !preg_match('/^[a-f0-9-]{36}$/D', $query['id'])
            || !preg_match('/^(\d{4})-\d{2}-\d{2}$/D', (string)($query['date'] ?? ''))
            || !preg_match('/^(Yellow|Amber|Red) warning of (.+) affecting (.+)$/D', $title, $heading)
            || !preg_match('/^(.+): (.+) valid from (\d{4}) (\w{3}) (\d{1,2}) (\w{3}) to (\d{4}) (\w{3}) (\d{1,2}) (\w{3})$/D', $description, $window)
            || $window[1] !== $title) throw new RuntimeException('Unknown warning format.');
        $year = (int)substr($query['date'], 0, 4);
        if ($year < 2020 || $year > 2100) throw new RuntimeException('Invalid warning year.');
        $start = warningUtc($window[3], $window[4], $window[5], $window[6], $year);
        // At New Year the end may belong to the following year, with a different weekday.
        $endYear = $window[6] === 'Dec' && $window[10] === 'Jan' ? $year + 1 : $year;
        $end = warningUtc($window[7], $window[8], $window[9], $window[10], $endYear);
        if ($start->format('Y-m-d') !== $query['date'] || $end <= $start || $end->getTimestamp() - $start->getTimestamp() > 14 * 86400)
            throw new RuntimeException('Invalid warning interval.');
        $uk = new DateTimeZone('Europe/London');
        $result[] = ['id' => $query['id'], 'title' => $title, 'description' => $description,
            'level' => strtolower($heading[1]), 'weather' => $heading[2], 'region' => $heading[3],
            'areas' => array_map('trim', explode(',', $window[2])), 'url' => $url,
            'startsAt' => $start->format('c'), 'endsAt' => $end->format('c'),
            'startsOn' => $start->setTimezone($uk)->format('Y-m-d'),
            'endsOn' => $end->modify('-1 second')->setTimezone($uk)->format('Y-m-d')];
    }
    return $result;
}

function weatherWarningsSnapshot(array $config, array $preferences): array
{
    $town = $preferences['town'];
    $location = $town['label'] ?? '';
    $off = ['status' => 'unconfigured', 'updatedAt' => null, 'data' => null, 'location' => $location];
    if ($town === null || !preg_match('/(?:United Kingdom|UK)$/i', $location)) return $off;
    $names = array_map('warningAreaKey', explode(',', $location));
    $names = array_values(array_diff($names, ['united kingdom', 'uk', 'england', 'scotland', 'wales', 'northern ireland']));
    if (!$names) return $off;
    $directory = dirname($config['SESSION_DIRECTORY']) . '/public-feeds';
    $feed = cachedPublicFeed($directory, 'met-office-rss-uk-v1', 300, 900, static fn() => weatherWarningNotices(
        publicFeedBody('https://www.metoffice.gov.uk/public/data/PWSCache/WarningsRSS/Region/UK', 'application/rss+xml, application/xml')));
    $matches = [];
    foreach ($feed['data'] ?? [] as $notice) {
        if (strtotime($notice['endsAt']) <= time()) continue;
        $areas = array_values(array_filter($notice['areas'], static fn($area) => in_array(warningAreaKey($area), $names, true)));
        if (!$areas) continue;
        // One warning can be repeated in the RSS for several regions.
        if (!isset($matches[$notice['id']])) $matches[$notice['id']] = $notice + ['matchedAreas' => $areas];
        else $matches[$notice['id']]['matchedAreas'] = array_values(array_unique(array_merge($matches[$notice['id']]['matchedAreas'], $areas)));
    }
    $rank = ['red' => 0, 'amber' => 1, 'yellow' => 2];
    $notices = array_values($matches);
    usort($notices, static fn($a, $b) => ($rank[$a['level']] <=> $rank[$b['level']]) ?: strcmp($a['startsAt'], $b['startsAt']));
    return array_replace($feed, ['location' => $location, 'data' => $feed['data'] === null ? null : $notices]);
}
