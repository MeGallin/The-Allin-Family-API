<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php'; require dirname(__DIR__) . '/src/advertising.php';
$checks = 0;
$check = static function (bool $value, string $label) use (&$checks): void { if (!$value) throw new RuntimeException($label); $checks++; };
$features = advertCatalogue(); $s = advertInitialState(); $current = 'nonograms'; $seen = [];
for ($i = 0; $i < 45; $i++) {
    $selected = advertSelectFeature($s, $features, $current)['id'];
    $check($selected !== $current, 'No immediate repeat with alternatives.');
    $seen[$selected] = ($seen[$selected] ?? 0) + 1; $current = $selected;
}
$check(count($seen) === 15, 'All15 enabled services receive turns.');
$check(max($seen) - min($seen) <= 1, 'Equal-weight coverage is balanced.');
$weighted = array_slice($features, 0, 3); $weighted[0]['weight'] = 3; $weighted[1]['weight'] = 2; $weighted[2]['weight'] = 1;
$s = advertInitialState(); $counts = [];
for ($i = 0; $i < 600; $i++) { $id = advertSelectFeature($s, $weighted, null)['id']; $counts[$id] = ($counts[$id] ?? 0) + 1; }
$check(array_values($counts) === [300, 200, 100], 'Smooth weighted allocation honors3:2:1 without starvation.');
$s = advertInitialState(); $counts = []; $current = null;
for ($i = 0; $i < 1200; $i++) { $id = advertSelectFeature($s, $weighted, $current)['id']; $check($id !== $current, 'Weighted rotation avoids repeat.'); $counts[$id] = ($counts[$id] ?? 0) + 1; $current = $id; }
$check($counts[$weighted[0]['id']] > $counts[$weighted[1]['id']] && $counts[$weighted[1]['id']] > $counts[$weighted[2]['id']], 'Weights influence real current-excluding rotation, all services participate.');
$extreme = $features; $extreme[0]['weight'] = 100; $s = advertInitialState(); $counts = []; $current = null;
for ($i = 0; $i < 2800; $i++) { $id = advertSelectFeature($s, $extreme, $current)['id']; $counts[$id] = ($counts[$id] ?? 0) + 1; $current = $id; }
$check(count($counts) === 15 && min($counts) > 0 && $counts[$extreme[0]['id']] <= 1400, 'Extreme weights remain fair under no-repeat share ceiling.');
$only = [$features[0]]; $check(advertSelectFeature($s, $only, $only[0]['id'])['id'] === $only[0]['id'], 'Sole eligible feature still works.');
$config = advertDefaultConfig(); $config['features'][0]['enabled'] = false; $config['features'][1]['expiresAt'] = '2000-01-01T00:00:00Z';
$eligible = advertEligibleFeatures($config, time()); $check(count($eligible) === 13, 'Disabled/expired services excluded.');
$allowed = ['poster', 'split', 'image-led']; $layout = 'image-led'; $cycle = [];
for ($i = 0; $i < 6; $i++) { $layout = advertNextLayout($allowed, $layout); $cycle[] = $layout; }
$check($cycle === ['poster', 'split', 'image-led', 'poster', 'split', 'image-led'], 'Visible allowed layout cycle.');
$check(advertNextLayout(['split'], 'split') === 'split' && advertNextLayout(['split', 'poster'], 'image-led') === 'split', 'Admin layout restriction authoritative.');
$directory = sys_get_temp_dir() . '/allin-rotation-test-' . bin2hex(random_bytes(10));
$env = ['APP_ENV' => 'test', 'ADVERTISING_DIRECTORY' => $directory]; $now = time();
try {
    advertTransaction($env, static function (array &$state) use ($now): void {
        $state['config']['enabled'] = true; $state['config']['manualApproval'] = false;
        $state['config']['schedule']['mode'] = 'interval'; $state['config']['schedule']['intervalSeconds'] = 180;
        $feature = $state['config']['features'][0];
        $payload = ['featureId' => $feature['id'], 'ctaId' => $feature['ctaId'], 'format' => 'headline', 'headline' => 'A shared little effort', 'message' => $feature['facts'][0]['text'], 'scenario' => '', 'items' => [], 'factIds' => ['fact-1']];
        $state['candidate'] = ['id' => str_repeat('a', 32), 'payload' => $payload, 'featureHash' => advertFeatureHash($feature), 'configRevision' => $state['configRevision'], 'preparedAt' => $now, 'promotedAt' => null, 'approved' => true];
        // All zero caps: publication must still commit independently.
    });
    $check(advertClaimJob($env, false, $now) === null, 'Promotion tick creates no next job.');
    $published = advertGetAdmin($env);
    $check($published['current']['id'] === str_repeat('a', 32) && $published['candidate'] === null, 'AUTO01 exhausted budgets cannot roll back paid publication.');
    $check($published['nextEligibleAt'] === $now + 180, 'One cadence from actual promotion.');
    $public = advertPublicSnapshot($env, $now);
    $check($public['version'] === $published['version'], 'Public snapshots carry monotonic mutation version.');
    advertTransaction($env, static function (array &$state) use ($now): void {
        $state['config']['image']['enabled'] = true; $state['config']['prices']['checkedAt'] = gmdate('Y-m-d', $now);
        $state['config']['budget']['dailyMicros'] = $state['config']['budget']['monthlyMicros'] = 10000000;
        $state['config']['budget']['dailyCalls'] = $state['config']['budget']['monthlyCalls'] = 60;
        $state['config']['budget']['dailyTokens'] = $state['config']['budget']['monthlyTokens'] = 200000;
        $state['config']['budget']['dailyImages'] = $state['config']['budget']['monthlyImages'] = 0;
    });
    try { advertClaimJob($env, true, $now + 1); throw new RuntimeException('Image cap must refuse before a text call.'); }
    catch (HttpError $e) { $check($e->errorCode === 'advert_budget', 'Full-cycle preflight catches image cap before text request.'); }
    $check(advertGetAdmin($env)['usage'] === [], 'Refused full cycle makes no reservation/call count.');
    $legacy = advertInitialState(); unset($legacy['rotationCredits']); $legacy['current'] = $published['current']; $legacy['usage'] = ['d:' . gmdate('Y-m-d') => ['micros' => 259441, 'calls' => 12, 'tokens' => 6570, 'images' => 5]];
    file_put_contents($directory . '/state.json', json_encode($legacy));
    $upgraded = advertGetAdmin($env);
    $check($upgraded['current'] === $legacy['current'] && $upgraded['usage'] === $legacy['usage'], 'Rotation metadata upgrade preserves actual pair and usage.');
    echo json_encode(['passed' => $checks, 'providerCalls' => 0]), PHP_EOL;
} finally {
    foreach (['state.json', 'state.lock'] as $file) if (is_file($directory . '/' . $file)) unlink($directory . '/' . $file);
    if (is_dir($directory)) rmdir($directory);
}
