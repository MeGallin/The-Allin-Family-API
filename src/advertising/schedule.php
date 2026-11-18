<?php
declare(strict_types=1);

function advertInWindow(array $schedule, int $now): bool
{
    $local = (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone($schedule['timezone']))->format('H:i');
    $start = $schedule['windowStart']; $end = $schedule['windowEnd'];
    return $start <= $end ? $local >= $start && $local <= $end : $local >= $start || $local <= $end;
}

function advertNextEligible(array $schedule, int $promotedAt): int
{
    if ($schedule['mode'] === 'interval') return $promotedAt + $schedule['intervalSeconds'];
    $zone = new DateTimeZone($schedule['timezone']);
    $date = (new DateTimeImmutable('@' . $promotedAt))->setTimezone($zone);
    // At most one daily promotion. Gaps move forward using IANA normalisation;
    // a repeated wall time is not revisited after the day's promotion.
    for ($days = 0; $days < 3; $days++) {
        if ($days === 0 && $date->format('H:i') >= $schedule['dailyAt']) continue;
        $day = $date->modify('+' . $days . ' days')->format('Y-m-d');
        $target = new DateTimeImmutable($day . ' ' . $schedule['dailyAt'], $zone);
        if ($target->getTimestamp() > $promotedAt) return $target->getTimestamp();
    }
    throw new RuntimeException('Unable to determine daily schedule.');
}
