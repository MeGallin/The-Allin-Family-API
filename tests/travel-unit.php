<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/travel.php';
$passed = 0;
function checkTravel(bool $ok, string $label): void {
    global $passed;
    if (!$ok) throw new RuntimeException($label);
    $passed++;
    echo 'PASS: ', $label, PHP_EOL;
}
function rejectTravel(callable $fn, string $label): void {
    try {$fn();} catch (RuntimeException $error) {checkTravel(true, $label);return;}
    throw new RuntimeException($label);
}
function travelFixture(): array {
    return array_map(static fn($line) => ['id' => $line['id'], 'lineStatuses' => [
        ['statusSeverity' => 10, 'statusSeverityDescription' => 'Good Service', 'validityPeriods' => []]]], travelLines());
}
$now = strtotime('2026-10-23T12:00:00Z');
$raw = travelFixture();
checkTravel(travelNotices($raw, false, $now) === [], 'Complete healthy network has no disruption notices');
$notice = ['statusSeverity' => 9, 'statusSeverityDescription' => 'Minor Delays', 'reason' => '<b>Delayed</b> &amp; slow',
    'disruption' => ['category' => 'RealTime'], 'validityPeriods' => [
        ['fromDate' => '2026-10-23T10:00:00Z', 'toDate' => '2026-10-23T18:00:00Z']]];
$raw[0]['lineStatuses'] = [$notice, $notice];
$result = travelNotices($raw, false, $now);
checkTravel(count($result) === 1 && $result[0]['description'] === 'Delayed & slow', 'Duplicate notices removed and provider markup becomes text');
checkTravel(travelNotices($raw, true, $now) === [], 'Live incidents never become future planned work');
$raw[0]['lineStatuses'][0]['disruption']['category'] = 'PlannedWork';
checkTravel(count(travelNotices($raw, true, $now)) === 1, 'Only dated provider-classified planned work retained');
$raw[0]['lineStatuses'] = [$notice];
$raw[0]['lineStatuses'][0]['validityPeriods'][0]['fromDate'] = '2026-10-24T10:00:00Z';
$raw[0]['lineStatuses'][0]['validityPeriods'][0]['toDate'] = '2026-10-24T18:00:00Z';
checkTravel(travelNotices($raw, false, $now) === [], 'Future validity excluded from current live status');
$raw[0]['lineStatuses'][0]['validityPeriods'] = [];
checkTravel(count(travelNotices($raw, false, $now)) === 1, 'Undated live notice retained for today only');
$raw[0]['lineStatuses'][0]['disruption']['category'] = 'PlannedWork';
checkTravel(travelNotices($raw, true, $now) === [], 'Undated planned work cannot create calendar dates');
rejectTravel(static fn() => travelNotices([], false, $now), 'Empty partial network fails visibly');
rejectTravel(static fn() => travelNotices(array_slice(travelFixture(), 1), false, $now), 'Missing line rejected');
$raw = travelFixture(); $raw[0]['id'] = 'not-a-line';
rejectTravel(static fn() => travelNotices($raw, false, $now), 'Unknown line rejected');
rejectTravel(static fn() => travelInstant('2026-02-30T12:00:00Z'), 'Impossible date rejected');
rejectTravel(static fn() => travelInstant('2026-10-25T01:00:00'), 'Ambiguous timezone rejected');
checkTravel(validatedTravelLines(['victoria','central']) === ['central','victoria'], 'Preferences validated and sorted');
foreach ([['unknown'], ['central','central'], ['central',1], ['id'=>'central'], null] as $bad) {
    rejectTravel(static fn() => validatedTravelLines($bad), 'Invalid selection rejected');
}
checkTravel(travelSnapshot(['SESSION_DIRECTORY'=>'unused'], ['lines'=>[]])['live']['status'] === 'unconfigured', 'Disabled preferences do not call providers');
echo $passed, ' travel checks passed; no network or database writes.', PHP_EOL;
