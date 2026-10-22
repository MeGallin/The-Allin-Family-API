<?php
declare(strict_types=1);

/** Shared calendar-day bounds for admin charts and their usage drill-down. */
function familyReportingWindow(int $days): array
{
    $zone = new DateTimeZone('Europe/London');
    $end = new DateTimeImmutable('tomorrow', $zone);
    return ['zone'=>$zone,'start'=>$end->modify('-'.$days.' days'),'end'=>$end];
}

/** One validated period contract for charts and their drill-downs. */
function familyReportingPeriod(array $query, bool $allowAll = false): int
{
    $period = $query['days'] ?? '30';
    $allowed = $allowAll ? ['7','30','90','0'] : ['7','30','90'];
    if (!is_string($period) || !in_array($period, $allowed, true)) {
        throw new HttpError(422, 'Choose a reporting period.');
    }
    return (int)$period;
}

function familyReportingBounds(int $days): array
{
    $window = familyReportingWindow($days);
    $utc = new DateTimeZone('UTC');
    return [
        'since' => $days === 0 ? '1970-01-01 00:00:00' : $window['start']->setTimezone($utc)->format('Y-m-d H:i:s'),
        'until' => $window['end']->setTimezone($utc)->format('Y-m-d H:i:s'),
        'from' => $days === 0 ? null : $window['start']->format('Y-m-d'),
        'through' => $window['end']->modify('-1 day')->format('Y-m-d'),
    ];
}
