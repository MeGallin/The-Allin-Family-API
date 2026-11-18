<?php
declare(strict_types=1);

function advertSelectFeature(array &$state, array $features, ?string $currentId): array
{
    // Smooth weighted round-robin over eligible features. Keep only bounded
    // metadata, not prior advert bodies. Current is skipped when alternatives
    // exist; a sole eligible feature remains a valid manual/automatic target.
    $ids = array_flip(array_column($features, 'id'));
    $credits = array_intersect_key($state['rotationCredits'] ?? [], $ids);
    $alternatives = array_values(array_filter($features, static fn(array $feature): bool => $feature['id'] !== $currentId));
    $pool = $alternatives ?: $features;
    if (!$pool) throw new HttpError(422, 'No eligible advertising features.');
    $sum = array_sum(array_column($features, 'weight'));
    $total = 0;
    foreach ($features as $feature) {
        $id = $feature['id']; $credit = $credits[$id] ?? 0;
        if (!is_int($credit) || abs($credit) > 100000) throw new RuntimeException('Invalid rotation metadata.');
        // No immediate repeat makes >50% share impossible. Cap only that
        // dominant effective weight; all other weight ratios remain intact.
        $weight = count($features) === 1 ? $feature['weight'] : min($feature['weight'], $sum - $feature['weight']);
        $credits[$id] = max(-100000, min(100000, $credit + $weight));
        $total += $weight;
    }
    $selected = null;
    foreach ($pool as $feature) {
        $id = $feature['id'];
        if ($selected === null || $credits[$id] > $credits[$selected['id']]) $selected = $feature;
    }
    $credits[$selected['id']] = max(-100000, $credits[$selected['id']] - $total);
    $state['rotationCredits'] = $credits;
    return $selected;
}

function advertNextLayout(array $allowed, ?string $current): string
{
    $index = array_search($current, $allowed, true);
    return $allowed[$index === false ? 0 : ($index + 1) % count($allowed)];
}
