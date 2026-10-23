<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__) . '/src/weather-warnings.php';
$passed = 0;
function checkWarning(bool $condition, string $label): void {
    global $passed;
    if (!$condition) throw new RuntimeException($label);
    $passed++;
    echo 'PASS: ', $label, PHP_EOL;
}
function warningFeed(string $start = '0900 Fri 23 Oct', string $end = '2000 Fri 23 Oct', string $date = '2026-10-23'): string {
    return '<rss><channel><title>Met Office warnings for UK</title><item>'
        . '<title>Yellow warning of Wind affecting London and South East England</title>'
        . '<description>Yellow warning of Wind affecting London and South East England: Surrey, Greater London valid from '
        . $start . ' to ' . $end . '</description>'
        . '<link>https://www.metoffice.gov.uk/weather/warnings-and-advice/uk-warnings#?date='
        . $date . '&amp;id=12345678-1234-1234-1234-123456789abc</link></item></channel></rss>';
}
function rejectWarning(string $xml, string $label): void {
    try { weatherWarningNotices($xml); } catch (RuntimeException $error) { checkWarning(true, $label); return; }
    throw new RuntimeException($label);
}
$notice = weatherWarningNotices(warningFeed())[0];
checkWarning($notice['startsAt'] === '2026-10-23T09:00:00+00:00' && $notice['endsAt'] === '2026-10-23T20:00:00+00:00', 'RSS clocks remain UTC');
checkWarning($notice['startsOn'] === '2026-10-23' && $notice['areas'] === ['Surrey', 'Greater London'], 'UK dates and coverage preserved');
checkWarning(warningAreaKey(' Greater London! ') === 'london' && warningAreaKey('County of Surrey Council') === 'surrey', 'Area aliases normalised after punctuation');
$boundary = weatherWarningNotices(warningFeed('2300 Fri 23 Oct', '2300 Sat 24 Oct'))[0];
checkWarning($boundary['startsOn'] === '2026-10-24' && $boundary['endsOn'] === '2026-10-24', 'BST midnight end is exclusive');
$newYear = weatherWarningNotices(warningFeed('2300 Thu 31 Dec', '0100 Fri 1 Jan', '2026-12-31'))[0];
checkWarning($newYear['endsAt'] === '2027-01-01T01:00:00+00:00', 'Year rollover validates next-year weekday');
checkWarning(weatherWarningNotices('<rss><channel><title>Met Office warnings for UK</title></channel></rss>') === [], 'Valid empty feed stays empty');
rejectWarning(warningFeed('0900 Thu 23 Oct'), 'Wrong weekday rejected');
rejectWarning(warningFeed('0900 Fri 23 Oct', '0800 Fri 23 Oct'), 'Reversed interval rejected');
rejectWarning(warningFeed('0900 Fri 23 Oct', '2000 Sat 7 Nov'), 'Excessive interval rejected');
rejectWarning(str_replace('www.metoffice.gov.uk', 'metoffice.gov.uk.attacker.example', warningFeed()), 'Untrusted host rejected');
rejectWarning(str_replace('https://www.', 'http://www.', warningFeed()), 'Non-HTTPS link rejected');
rejectWarning('<!DOCTYPE rss [<!ENTITY file SYSTEM "file:///secret">]>' . warningFeed(), 'XML entities rejected');
rejectWarning('<rss>broken', 'Malformed feed rejected');
rejectWarning(str_repeat('x', 1048577), 'Oversized feed rejected');
echo $passed, ' weather warning checks passed.', PHP_EOL;
