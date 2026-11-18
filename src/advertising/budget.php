<?php
declare(strict_types=1);

function advertUsageKeys(int $now): array { return ['d:' . gmdate('Y-m-d', $now), 'm:' . gmdate('Y-m', $now)]; }

function advertReserveCall(array &$state, string $kind, int $now): array
{
    $c = $state['config']; $spec = $kind === 'review' ? $c['review'] : $c['generation'];
    $checked = strtotime($c['prices']['checkedAt'] . 'T00:00:00Z');
    if ($checked > strtotime(gmdate('Y-m-d', $now) . 'T00:00:00Z') || $now > $checked + $c['prices']['validDays'] * 86400) throw new HttpError(422, 'Check current model prices before preparing an advert.');
    $price = $c['prices']['models'][$spec['model']];
    // UTF-8 prompt bytes + schema/system overhead are bounded by the engine to
    // 24,000 tokens conservatively (one token per byte plus overhead).
    $input = 24000; $output = $spec['maxOutputTokens'];
    // Standard input ceiling includes up to2.5x cache-write pricing. Treat this
    // as a conservative ceiling, not an exact provider invoice estimate.
    $price['input'] = (int)ceil($price['input'] * 2.5);
    $micros = (int)ceil(($input * $price['input'] + $output * $price['output']) / 1000000);
    $reservation = ['micros' => $micros, 'calls' => 1, 'tokens' => $input + $output, 'images' => 0];
    if ($kind === 'image') {
        if (!$c['image']['enabled']) throw new HttpError(409, 'Image generation is disabled.');
        $reservation = ['micros' => $c['image']['reservationMicros'], 'calls' => 1, 'tokens' => 0, 'images' => 1];
    }
    $keys = advertUsageKeys($now);
    // Keep only current UTC day/month buckets: bounded counters, not an archive.
    $state['usage'] = array_intersect_key($state['usage'], array_flip($keys));
    foreach ($keys as $index => $key) {
        $counter = $state['usage'][$key] ?? ['micros' => 0, 'calls' => 0, 'tokens' => 0, 'images' => 0];
        foreach ($reservation as $metric => $amount) {
            $cap = $c['budget'][($index === 0 ? 'daily' : 'monthly') . ucfirst($metric)];
            if ($cap < ($counter[$metric] ?? 0) + $amount) throw new HttpError(429, 'The advertising budget or usage cap has been reached.', 'advert_budget');
        }
    }
    foreach ($keys as $key) {
        $state['usage'][$key] ??= ['micros' => 0, 'calls' => 0, 'tokens' => 0];
        foreach ($reservation as $metric => $amount) $state['usage'][$key][$metric] = ($state['usage'][$key][$metric] ?? 0) + $amount;
    }
    return $reservation + ['keys' => $keys, 'inputLimit' => $input, 'outputLimit' => $output, 'price' => $price];
}

function advertSettleCall(array &$state, array $grant, ?array $usage): bool
{
    if ($grant['kind'] === 'image') {
        // The Images API has no output-token ceiling. This reservation is an
        // estimate, not an absolute provider-invoice guarantee.
        if (!$usage) return false;
        foreach (['textInputTokens', 'imageInputTokens', 'imageOutputTokens', 'textOutputTokens'] as $key) {
            if (!is_int($usage[$key] ?? null) || $usage[$key] < 0 || $usage[$key] > 10000000) return false;
        }
        $cost = (int)ceil(($usage['textInputTokens'] * 5000000 + $usage['imageInputTokens'] * 8000000 + $usage['imageOutputTokens'] * 30000000 + $usage['textOutputTokens'] * 30000000) / 1000000);
        $reserved = $grant['reservation'];
        foreach ($reserved['keys'] as $key) if (isset($state['usage'][$key])) {
            $state['usage'][$key]['micros'] += $cost - $reserved['micros'];
            $state['usage'][$key]['imageTokens'] = ($state['usage'][$key]['imageTokens'] ?? 0) + $usage['textInputTokens'] + $usage['imageInputTokens'] + $usage['imageOutputTokens'] + $usage['textOutputTokens'];
        }
        if ($cost > $reserved['micros']) {
            $state['config']['paused'] = true;
            $state['lastOutcome'] = 'image_estimate_exceeded_paused';
        }
        return true;
    }
    // Unknown/malformed outcomes retain the full reservation, even after restart.
    if (!$usage || !is_int($usage['inputTokens'] ?? null) || !is_int($usage['outputTokens'] ?? null)
        || $usage['inputTokens'] < 0 || $usage['outputTokens'] < 0 || $usage['inputTokens'] > $grant['reservation']['inputLimit'] || $usage['outputTokens'] > $grant['reservation']['outputLimit']) return false;
    $r = $grant['reservation'];
    $cost = (int)ceil(($usage['inputTokens'] * $r['price']['input'] + $usage['outputTokens'] * $r['price']['output']) / 1000000);
    foreach ($r['keys'] as $key) {
        if (!isset($state['usage'][$key])) continue;
        $state['usage'][$key]['micros'] -= max(0, $r['micros'] - $cost);
        $state['usage'][$key]['tokens'] -= max(0, $r['tokens'] - $usage['inputTokens'] - $usage['outputTokens']);
    }
    return true;
}
