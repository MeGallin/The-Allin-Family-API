<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__) . '/src/food-alerts.php';
$passed = 0;
function checkFood(bool $condition, string $label): void {
    global $passed;
    if (!$condition) throw new RuntimeException($label);
    $passed++;
    echo 'PASS: ', $label, PHP_EOL;
}
function notice(string $id, string $date): array {
    return ['notation' => $id, 'created' => $date, 'title' => 'A recalled product',
        'status' => ['@id' => 'http://data.food.gov.uk/codes/alerts/def/status/published'],
        'alertURL' => 'https://alerts.food.gov.uk/news-alerts/alert/' . strtolower($id)];
}
function rejectFood(callable $callback, string $label): void {
    try { $callback(); } catch (RuntimeException $error) { checkFood(true, $label); return; }
    throw new RuntimeException($label);
}
$directory = dirname(__DIR__) . '/.local/food-alerts-test-' . bin2hex(random_bytes(10));
mkdir($directory, 0700);
try {
    $a = notice('FSA-AA-1-2020', '2020-01-01');
    $b = notice('FSA-PRIN-2-2020', '2020-01-02');
    $c = notice('FSA-FAFA-3-2020', '2020-01-03');
    $d = notice('FSA-AA-4-2020', '2020-01-04');
    $result = foodAlertsFromProvider(['items' => [$a, $d, $b, $c, $d]]);
    checkFood(count($result) === 3 && array_column($result, 'id') === [$d['notation'], $c['notation'], $b['notation']], 'Three latest distinct notices in date order');
    checkFood(array_column($result, 'kind') === ['allergy', 'food-alert', 'recall'], 'FSA notice types mapped accurately');
    checkFood(foodAlertsFromProvider(['items' => []]) === [], 'Valid empty feed distinguished from failure');
    $withdrawn = $a; $withdrawn['status']['@id'] = 'http://data.food.gov.uk/codes/alerts/def/status/withdrawn';
    checkFood(count(foodAlertsFromProvider(['items' => [$withdrawn, $b]])) === 1, 'Withdrawn alerts excluded');
    $rich = $a;
    $rich['productDetails'] = [['productName' => 'Example snack', 'packSizeDescription' => '100g', 'productCode' => 'S1',
        'batchDescription' => [['batchCode' => 'B123', 'useByDescription' => 'All use by dates',
            'bestBeforeDate' => ['2026-11-01', '2026-11-02']]]]];
    $rich['problem'] = [['riskStatement' => 'Contains undeclared milk.'], ['riskStatement' => 'Contains undeclared milk.']];
    $rich['consumerAdvice'] = '<p>Do not eat.</p><p>Return &amp; refund.</p>';
    $details = foodAlertDetails($rich);
    checkFood($details['products'][0]['name'] === 'Example snack' && $details['products'][0]['packSize'] === '100g'
        && $details['products'][0]['productCode'] === 'S1', 'Affected products retain names, sizes and product codes');
    $batch = $details['products'][0]['batches'][0];
    checkFood($batch['batchCode'] === 'B123' && $batch['useBy'] === 'All use by dates', 'Batch codes and date descriptions preserved');
    checkFood($batch['bestBefore'] === '2026-11-01; 2026-11-02', 'Multiple provider dates are retained');
    checkFood($details['riskStatements'] === ['Contains undeclared milk.'], 'Risk statements retained without duplicates');
    checkFood($details['consumerAdvice'] === "Do not eat.\nReturn & refund.", 'Official advice paragraphs and entities become readable plain text');
    $single = foodAlertDetails(['productDetails' => ['productName' => 'One item', 'batchDescription' => ['batchCode' => 'SINGLE']],
        'problem' => ['riskStatement' => 'A stated risk']]);
    checkFood($single['products'][0]['batches'][0]['batchCode'] === 'SINGLE' && $single['riskStatements'] === ['A stated risk'], 'Single-object products, batches and problems supported');
    checkFood(foodAlertDetails(['productDetails' => 'invalid', 'problem' => 123, 'consumerAdvice' => []]) ===
        ['products' => [], 'riskStatements' => [], 'consumerAdvice' => null], 'Missing or malformed optional details never invent advice');
    checkFood(foodAlertText("\xff") === null && foodAlertText(str_repeat('x', 30001)) === null, 'Invalid or oversized provider text rejected');
    checkFood(foodAlertText('<strong>Read &amp; return</strong>') === 'Read & return', 'Provider markup is not exposed as HTML');
    checkFood(foodAlertsFromProvider(['items' => [$rich]])[0]['details'] === $details, 'Published feed includes normalised expandable detail');
    foreach (['javascript:alert(1)', 'https://food.gov.uk.attacker.example/notice', 'https://attacker.example/notice', 'http://www.food.gov.uk/notice', 'https://user@food.gov.uk/notice', 'https://food.gov.uk:444/notice', "https://food.gov.uk/\nnotice"] as $url) {
        checkFood(foodAlertUrl($url) === null, 'Unsafe/non-FSA URL rejected');
    }
    foreach (['https://food.gov.uk/a', 'https://www.food.gov.uk/a', 'https://alerts.food.gov.uk/a'] as $url) {
        checkFood(foodAlertUrl($url) === $url, 'Official HTTPS FSA link retained');
    }
    $invalid = $a; $invalid['created'] = '2020-02-30';
    rejectFood(static fn() => foodAlertsFromProvider(['items' => [$invalid]]), 'Impossible date rejected');
    $invalid['created'] = '2999-01-01';
    rejectFood(static fn() => foodAlertsFromProvider(['items' => [$invalid]]), 'Future notice rejected');
    rejectFood(static fn() => foodAlertsFromProvider(['items' => [$withdrawn]]), 'Withdrawn-only payload never claims no alerts');
    rejectFood(static fn() => foodAlertsFromProvider(['error' => 'upstream error']), 'Malformed provider payload rejected');
    $calls = 0;
    $load = static function () use (&$calls, $a): array { $calls++; return foodAlertsFromProvider(['items' => [$a]]); };
    $first = cachedPublicFeed($directory, 'food-unit', 1800, 21600, $load);
    checkFood($first === cachedPublicFeed($directory, 'food-unit', 1800, 21600, $load) && $calls === 1, 'Shared 30-minute cache prevents repeated provider requests');
    $path = $directory . '/' . hash('sha256', 'food-unit') . '.json';
    checkFood(is_file($path), 'Successful refresh persists a regular cache file');
    $windowsStyle = str_replace('\\', '//', (string)realpath($directory));
    checkFood(cachedPublicFeed($windowsStyle, 'food-unit', 1800, 21600, $load) === $first && $calls === 1, 'Configured Windows double-slash paths share the persisted cache');
    file_put_contents($path, json_encode(['fetchedAt' => time() - 1801, 'data' => $first['data']]));
    $fail = static function () use (&$calls): array { $calls++; throw new RuntimeException('Simulated outage'); };
    $stale = cachedPublicFeed($directory, 'food-unit', 1800, 21600, $fail);
    $retry = cachedPublicFeed($directory, 'food-unit', 1800, 21600, $fail);
    checkFood($stale['status'] === 'stale' && $stale === $retry && $calls === 2, 'Outage retains timestamped stale notices with retry backoff');
    file_put_contents($path, json_encode(['fetchedAt' => time() - 21601, 'data' => $first['data']]));
    checkFood(cachedPublicFeed($directory, 'food-unit', 1800, 21600, $fail)['status'] === 'unavailable', 'Expired notices are not displayed');
    $lock = fopen($path . '.lock', 'c'); flock($lock, LOCK_EX);
    file_put_contents($path, json_encode(['fetchedAt' => time() - 1801, 'data' => $first['data']]));
    $before = $calls;
    checkFood(cachedPublicFeed($directory, 'food-unit', 1800, 21600, $load)['status'] === 'stale' && $calls === $before, 'Concurrent refresh returns promptly without a duplicate request');
    flock($lock, LOCK_UN); fclose($lock);
    echo "Food alerts: $passed checks passed; synthetic notices only, no network or database writes.", PHP_EOL;
} finally {
    foreach (glob($directory . '/*') as $path) if (is_file($path)) unlink($path);
    rmdir($directory);
}
