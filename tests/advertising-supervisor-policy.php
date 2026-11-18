<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bin/local-advertising/start-policy.php';
$state = ['config' => ['enabled' => true, 'paused' => false, 'schedule' => ['preparationLeadSeconds' => 180]], 'current' => ['id' => 'synthetic'], 'nextEligibleAt' => 1000];
$cases = [
    [$state, 819, true], [$state, 820, false], [$state, 999, false], [$state, 1000, false], [$state, 1100, false],
    [array_replace($state, ['current' => null]), 100, false],
    [array_replace($state, ['nextEligibleAt' => null]), 100, false],
];
$paused = $state; $paused['config']['paused'] = true;
$disabled = $state; $disabled['config']['enabled'] = false;
$cases[] = [$paused, 1200, true]; $cases[] = [$disabled, 1200, true];
foreach ($cases as [$input, $now, $expected]) {
    if (advertisingSupervisorSafeToArm($input, $now) !== $expected) throw new RuntimeException('Initial start safety policy failed.');
}
echo json_encode(['passed' => count($cases), 'providerCalls' => 0]), "\n";
