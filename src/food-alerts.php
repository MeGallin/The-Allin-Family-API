<?php
declare(strict_types=1);
require_once __DIR__ . '/public-feeds.php';

/** Only link to HTTPS FSA notices, never arbitrary URLs supplied by a provider. */
function foodAlertUrl(mixed $value): ?string
{
    if (!is_string($value) || strlen($value) > 2000 || preg_match('/[\x00-\x20\x7f]/', $value)) return null;
    $url = parse_url($value);
    if (!is_array($url) || ($url['scheme'] ?? '') !== 'https'
        || !in_array(strtolower($url['host'] ?? ''), ['food.gov.uk', 'www.food.gov.uk', 'alerts.food.gov.uk'], true)
        || isset($url['user']) || isset($url['pass']) || isset($url['port'])) return null;
    return $value;
}

/** Public provider text is displayed as plain text, never trusted HTML. */
function foodAlertText(mixed $value): ?string
{
    if (!is_string($value) || strlen($value) > 30000 || preg_match('//u', $value) !== 1) return null;
    $text = preg_replace('/<br\s*\/?\s*>|<\/p>|<\/li>/i', "\n", $value);
    $text = trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    return $text === '' ? null : $text;
}

/** Accept the provider's one-object or object-list representations. */
function foodAlertObjects(mixed $value, string $field): array
{
    if (!is_array($value) || count($value) > 100) return [];
    if (isset($value[$field]) || array_filter(array_keys($value), 'is_string')) return [$value];
    return array_values(array_filter($value, 'is_array'));
}

function foodAlertBatchText(mixed $value): ?string
{
    if (!is_array($value)) return foodAlertText($value);
    if (count($value) > 100) return null;
    $dates = array_values(array_filter(array_map('foodAlertText', $value), static fn($text) => $text !== null));
    return $dates ? implode('; ', $dates) : null;
}

function foodAlertDetails(array $item): array
{
    $products = [];
    foreach (foodAlertObjects($item['productDetails'] ?? null, 'productName') as $product) {
        $name = foodAlertText($product['productName'] ?? null);
        if ($name === null) continue;
        $batches = [];
        foreach (foodAlertObjects($product['batchDescription'] ?? null, '@id') as $batch) {
            $fields = ['batchCode' => foodAlertText($batch['batchCode'] ?? null),
                'bestBefore' => foodAlertBatchText($batch['bestBeforeDescription'] ?? $batch['bestBeforeDate'] ?? null),
                'useBy' => foodAlertBatchText($batch['useByDescription'] ?? $batch['useByDate'] ?? null)];
            if (array_filter($fields, static fn($value) => $value !== null)) $batches[] = $fields;
        }
        $products[] = ['name' => $name, 'packSize' => foodAlertText($product['packSizeDescription'] ?? null),
            'productCode' => foodAlertText($product['productCode'] ?? null), 'batches' => $batches];
    }
    $risks = [];
    foreach (foodAlertObjects($item['problem'] ?? null, 'riskStatement') as $problem) {
        $risk = foodAlertText($problem['riskStatement'] ?? null);
        if ($risk !== null) $risks[] = $risk;
    }
    return ['products' => $products, 'riskStatements' => array_values(array_unique($risks)),
        'consumerAdvice' => foodAlertText($item['consumerAdvice'] ?? null)];
}

function foodAlertsFromProvider(array $raw): array
{
    if (!isset($raw['items']) || !is_array($raw['items']) || count($raw['items']) > 100) {
        throw new RuntimeException('Invalid food alerts response.');
    }
    $alerts = [];
    foreach ($raw['items'] as $item) {
        if (!is_array($item)) continue;
        $id = $item['notation'] ?? '';
        $title = $item['title'] ?? '';
        $date = $item['created'] ?? '';
        $status = $item['status']['@id'] ?? '';
        if (!is_string($id) || !preg_match('/^FSA-(AA|PRIN|FAFA)-\d+-\d{4}$/D', $id, $match)
            || !is_string($title) || trim($title) === '' || strlen($title) > 1000 || preg_match('//u', $title) !== 1
            || !is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)
            || !checkdate((int)substr($date, 5, 2), (int)substr($date, 8, 2), (int)substr($date, 0, 4))
            || $date > gmdate('Y-m-d') || !is_string($status) || !str_ends_with($status, '/published')) continue;
        $url = foodAlertUrl($item['alertURL'] ?? null);
        if ($url === null) continue;
        $alerts[$id] = ['id' => $id, 'title' => trim($title), 'publishedAt' => $date, 'url' => $url,
            'kind' => ['AA' => 'allergy', 'PRIN' => 'recall', 'FAFA' => 'food-alert'][$match[1]],
            'details' => foodAlertDetails($item)];
    }
    // Never interpret malformed/withdrawn-only results as confirmation of no alerts.
    if ($raw['items'] && !$alerts) throw new RuntimeException('No usable published food alerts.');
    $alerts = array_values($alerts);
    usort($alerts, static fn(array $a, array $b): int => strcmp($b['publishedAt'], $a['publishedAt']));
    return array_slice($alerts, 0, 3);
}

function foodAlertsSnapshot(array $config): array
{
    // Shared public cache, independent of user preferences; no family data leaves the API.
    return cachedPublicFeed(dirname($config['SESSION_DIRECTORY']) . '/public-feeds', 'fsa-alerts-v2', 1800, 21600,
        static fn(): array => foodAlertsFromProvider(publicFeedJson(
            'https://data.food.gov.uk/food-alerts/id?_limit=20&_sort=-created&_view=full')));
}
