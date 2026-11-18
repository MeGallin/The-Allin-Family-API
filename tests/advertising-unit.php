<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/advertising.php';
$directory = sys_get_temp_dir() . '/allin-advert-test-' . bin2hex(random_bytes(12));
$environment = ['APP_ENV' => 'test', 'ADVERTISING_DIRECTORY' => $directory, 'ADVERTISING_SIGNING_SECRET' => str_repeat('s', 64)];
$checks = 0;
function check(bool $value, string $label): void { global $checks; if (!$value) throw new RuntimeException($label); $checks++; }
function fails(callable $fn, ?int $status = null): void {
    try { $fn(); } catch (Throwable $e) { check($status === null || ($e instanceof HttpError && $e->status === $status), 'Expected failure status.'); return; }
    throw new RuntimeException('Expected operation to fail.');
}
function act(array $env, string $action, array $extra = [], ?int $now = null): void {
    $s = advertGetAdmin($env);
    advertAdminAction($env, ['action' => $action, 'version' => $s['version'], 'draftVersion' => $s['draftVersion'], 'configRevision' => $s['configRevision'], 'candidateId' => $s['candidate']['id'] ?? null] + $extra, $now, 42);
}
function settle(array $env, array $job, int $now, ?array $usage = ['inputTokens' => 100, 'outputTokens' => 100]): array {
    advertTransaction($env, static function (array &$s) use ($job, $now, $usage): void {
        advertMachineMutation($s, $job + ['action' => 'begin'], $now);
        advertMachineMutation($s, $job + ['action' => 'settle', 'usage' => $usage], $now);
    });
    $f = $job['feature'];
    $payload = ['featureId' => $f['id'], 'ctaId' => $f['ctaId'], 'format' => 'headline', 'headline' => 'A little space for everyday life', 'message' => $f['facts'][0]['text'], 'scenario' => '', 'items' => [], 'factIds' => [$f['facts'][0]['id']]];
    advertFinishJob($env, $job, ['status' => 'ready', 'payload' => $payload], $now);
    return $payload;
}
try {
    $now = time();
    $default = advertDefaultConfig();
    check(advertValidateConfig($default) === $default, 'Default config validates.');
    $emptyExclusions = $default;
    $emptyExclusions['brand']['exclusions'] = [];
    foreach ($emptyExclusions['features'] as &$feature) $feature['exclusions'] = []; unset($feature);
    check(advertValidateConfig($emptyExclusions) === $emptyExclusions, 'Cleared brand and feature exclusions are valid empty lists.');
    check(!$default['enabled'] && array_sum($default['budget']) === 0, 'Safe zero-budget disabled defaults.');
    check(count($default['features']) === 15 && !in_array('quiz', array_column($default['features'], 'id'), true), 'Only implemented features.');
    foreach (['2026-02-31', '2026-13-01', '9999-01-01'] as $date) { $bad = $default; $bad['prices']['checkedAt'] = $date; fails(fn() => advertValidateConfig($bad), 422); }
    $bad = $default; $bad['features'][0]['expiresAt'] = '2026-02-31T09:00:00Z'; fails(fn() => advertValidateConfig($bad), 422);
    $bad = $default; $bad['features'][0]['facts'][0]['text'] = '<script>bad</script>'; fails(fn() => advertValidateConfig($bad), 422);
    $bad = $default; $bad['generation']['model'] = 'arbitrary'; fails(fn() => advertValidateConfig($bad), 422);
    $bad = $default; $bad['generation']['effort'] = 'ultra'; fails(fn() => advertValidateConfig($bad), 422);
    $bad = $default; $bad['features'][0]['ctaId'] = 'https://evil.invalid'; fails(fn() => advertValidateConfig($bad), 422);
    check(advertClaimJob($environment, true, $now) === null, 'Disabled claim makes no job.');
    advertTransaction($environment, static function (array &$s) use ($now): void {
        $s['config']['enabled'] = true; $s['config']['prices']['checkedAt'] = gmdate('Y-m-d', $now);
        $s['config']['schedule']['mode'] = 'interval'; $s['config']['schedule']['intervalSeconds'] = 60;
        $s['config']['schedule']['windowStart'] = '00:00'; $s['config']['schedule']['windowEnd'] = '23:59';
        foreach ($s['config']['budget'] as &$cap) $cap = 10000000; unset($cap);
        $s['draft'] = $s['config'];
    });
    $jobA = advertClaimJob($environment, true, $now);
    check($jobA !== null && advertClaimJob($environment, true, $now) === null, 'Lease prevents duplicate preparation.');
    $reserved = advertGetAdmin($environment)['usage'];
    check(array_values($reserved)[0]['micros'] >= 6000, 'Input reservation includes2.5x cache-write ceiling.');
    $payloadA = settle($environment, $jobA, $now);
    $s = advertGetAdmin($environment);
    check($s['candidate'] !== null && !$s['candidate']['approved'] && $s['current'] === null, 'Manual approval initial candidate.');
    $staleVersion = $s['version'];
    act($environment, 'approve', [], $now);
    fails(fn() => advertAdminAction($environment, ['action' => 'publish', 'version' => $staleVersion], $now, 42), 409);
    act($environment, 'publish', [], $now);
    $a = advertGetAdmin($environment)['current'];
    check($a['payload'] === $payloadA && $a['promotedAt'] === $now, 'A promoted from actual time.');
    check(advertGetAdmin($environment)['nextEligibleAt'] === $now + 60, 'Interval from actual promotion.');
    $jobB = advertClaimJob($environment, true, $now + 1);
    check($jobB !== null && $jobB['feature']['id'] !== $jobA['feature']['id'], 'B prepares a different eligible feature while A remains.');
    $payloadB = settle($environment, $jobB, $now + 1);
    check(advertGetAdmin($environment)['current']['id'] === $a['id'], 'A stays while B prepares.');
    act($environment, 'approve', [], $now + 1);
    fails(fn() => act($environment, 'publish', [], $now + 1), 409);
    act($environment, 'publish', [], $now + 60);
    $s = advertGetAdmin($environment);
    check($s['current']['payload'] === $payloadB && $s['current']['id'] !== $a['id'] && $s['candidate'] === null, 'Atomic swap B removes A body slot.');
    $jobC = advertClaimJob($environment, true, $now + 61);
    check($jobC !== null, 'C can prepare next cycle after B promotion.');
    $before = advertGetAdmin($environment);
    act($environment, 'pause', [], $now + 61);
    advertFinishJob($environment, $jobC, ['status' => 'ready', 'payload' => $payloadA], $now + 62);
    check(advertGetAdmin($environment)['candidate'] === null, 'Cancelled/fenced result cannot persist.');
    act($environment, 'resume', [], $now + 62);
    check(advertClaimJob($environment, true, $now + 70) === null, 'Cancellation blocks overlapping calls until old deadline.');
    $future = $now + 300;
    $job = advertClaimJob($environment, true, $future);
    check($job !== null, 'Restart sees durable current and can claim after fence deadline.');
    $raw = json_encode($job + ['action' => 'begin'], JSON_THROW_ON_ERROR);
    $timestamp = (string)($future + 120); $nonce = str_repeat('a', 32);
    $headers = ['time' => $timestamp, 'nonce' => $nonce, 'signature' => advertSignature($environment['ADVERTISING_SIGNING_SECRET'], $timestamp, $nonce, 'POST', '/advertising/machine', $raw)];
    check(isset(advertMachineRequest($environment, $raw, $headers, $future)['ok']), 'Signed future-skew boundary accepted once.');
    fails(fn() => advertMachineRequest($environment, $raw, $headers, $future + 121), 409);
    $invalid = $headers; $invalid['signature'] = str_repeat('0', 64); fails(fn() => advertMachineRequest($environment, $raw, $invalid, $future), 401);
    $usageBefore = advertGetAdmin($environment)['usage'];
    advertFinishJob($environment, $job, null, $future + 1);
    $s = advertGetAdmin($environment);
    check($s['config']['paused'] && $s['usage'] === $usageBefore && $s['current'] !== null, 'Unknown outcome retains reservations, pauses, preserves current.');
    act($environment, 'resume', [], $future + 2);
    check(advertClaimJob($environment, true, $future + 40) === null, 'Unknown response fence survives resume/backoff until the entire old deadline.');
    check(!str_contains(json_encode($s['audit']), $payloadA['message']), 'Metadata audit excludes advert bodies.');
    check(in_array(42, array_column($s['audit'], 'actorId'), true), 'Admin attribution retained.');
    $f = $jobA['feature']; $badPayload = $payloadA; $badPayload['message'] = 'Save 99 pounds'; fails(fn() => advertValidPayload($badPayload, $f, $default), 422);
    $badPayload = $payloadA; $badPayload['url'] = 'evil'; fails(fn() => advertValidPayload($badPayload, $f, $default), 422);
    advertTransaction($environment, static function (array &$s): void {
        $id = $s['current']['payload']['featureId'];
        foreach ($s['config']['features'] as &$f) if ($f['id'] === $id) $f['enabled'] = false;
        unset($f);
    });
    check(advertPublicSnapshot($environment)['current'] === null, 'Invalid feature hides and deletes current body.');
    $daily = $default['schedule']; $daily['dailyAt'] = '01:30';
    $gap = advertNextEligible($daily, strtotime('2026-03-28T09:00:00Z'));
    check(gmdate('c', $gap) === '2026-03-29T01:30:00+00:00', 'DST missing01:30 moves to02:30BST.');
    $overlap = advertNextEligible($daily, strtotime('2026-10-25T00:30:00Z'));
    check($overlap > strtotime('2026-10-25T23:00:00Z'), 'Repeated local01:30 cannot publish twice on one date.');
    $window = $daily; $window['windowStart'] = '23:00'; $window['windowEnd'] = '02:00';
    check(advertInWindow($window, strtotime('2026-11-17T00:30:00Z')) && !advertInWindow($window, strtotime('2026-11-17T12:00:00Z')), 'Overnight publication windows.');
    foreach (['https://service.onrender.com:443', 'https://user@service.onrender.com', 'https://service.onrender.com?q=1', 'https://service.onrender.com#x', 'http://service.onrender.com'] as $url) fails(fn() => advertNodeRequest($environment + ['ADVERTISING_NODE_URL' => $url], '/v1/status', [], 1), 503);
    file_put_contents($directory . '/state-deadbeef.tmp', 'orphan body');
    advertGetAdmin($environment);
    check(!is_file($directory . '/state-deadbeef.tmp'), 'Orphan temporary payload removed.');
    fails(fn() => advertDirectory(array_replace($environment, ['ADVERTISING_DIRECTORY' => dirname(__DIR__)])));
    fails(fn() => advertDirectory(array_replace($environment, ['ADVERTISING_DIRECTORY' => dirname(__DIR__, 5)])));
    check(advertKnownWebRoot('/home/example/public_html/api') === '/home/example/public_html', 'CLI derives cPanel public root without DOCUMENT_ROOT.');
    check(advertKnownWebRoot('C:/xampp/htdocs/project/api') === 'C:/xampp/htdocs', 'CLI derives broad XAMPP root.');
    $beforeHash = advertFeatureHash(advertCatalogue()[0]); $frequency = advertCatalogue()[0]; $frequency['weight'] = 12;
    check(advertFeatureHash($frequency) === $beforeHash, 'Weight changes do not invalidate published facts.');
    $noKey = $environment; $noKey['ADVERTISING_SIGNING_SECRET'] = ''; fails(fn() => advertMachineRequest($noKey, '{}', [], $now), 401);
    $zero = advertInitialState(); fails(function () use (&$zero, $now): void { advertReserveCall($zero, 'generation', $now); }, 429);
    $stale = advertInitialState(); $stale['config']['prices']['checkedAt'] = '2020-01-01'; fails(function () use (&$stale, $now): void { advertReserveCall($stale, 'generation', $now); }, 422);
    echo json_encode(['passed' => $checks, 'providerCalls' => 0], JSON_THROW_ON_ERROR), PHP_EOL;
} finally {
    // Exact generated files only; never recursively remove a supplied directory.
    foreach (['state.json', 'state.lock', 'state-deadbeef.tmp'] as $file) if (is_file($directory . '/' . $file)) unlink($directory . '/' . $file);
    if (is_dir($directory)) rmdir($directory);
}
