<?php
declare(strict_types=1);

/** Shared calendar-day bounds for admin charts and their usage drill-down. */
function familyReportingWindow(int $days): array
{
    $zone = new DateTimeZone('Europe/London');
    $end = new DateTimeImmutable('tomorrow', $zone);
    return ['zone'=>$zone,'start'=>$end->modify('-'.$days.' days'),'end'=>$end];
}
