<?php
declare(strict_types=1);

function advertValidPayload(mixed $payload, array $feature, array $config): array
{
    $keys = ['featureId', 'ctaId', 'format', 'headline', 'message', 'scenario', 'items', 'factIds'];
    if ($config['image']['enabled'] ?? false) { $keys[] = 'imageBrief'; $keys[] = 'composition'; }
    $p = advertObject($payload, $keys);
    if ($config['image']['enabled'] ?? false) {
        advertText($p['imageBrief'], $config['image']['maxPromptCharacters'], 'visual brief');
        if (mb_strlen($p['imageBrief']) < 40) throw new HttpError(422, 'The visual brief needs a concrete creative concept.');
        advertEnum($p['composition'], $config['image']['layouts'], 'campaign composition');
        if (!is_string($p['headline'] ?? null)) throw new HttpError(422, 'The campaign needs a short headline.');
        $words = preg_match_all('/[\p{L}\p{N}]+(?:[\x{0027}\x{2019}\x{002d}\x{2010}-\x{2015}][\p{L}\p{N}]+)*/u', $p['headline']);
        if ($words === false || $words < 2 || $words > $config['image']['maxHeadlineWords']) throw new HttpError(422, 'The campaign headline exceeds its approved word limit.');
    }
    if ($p['featureId'] !== $feature['id'] || $p['ctaId'] !== $feature['ctaId']) throw new HttpError(422, 'The advert does not match its approved feature.');
    advertText($p['headline'], $config['limits']['headline'], 'headline');
    advertEnum($p['format'], $config['formats'], 'format');
    $facts = array_column($feature['facts'], 'text', 'id');
    $p['factIds'] = advertStrings($p['factIds'], $config['limits']['maxItems'], 12, 'fact references');
    if (!$p['factIds'] || count(array_unique($p['factIds'])) !== count($p['factIds'])) throw new HttpError(422, 'The advert needs distinct approved facts.');
    foreach ($p['factIds'] as $id) if (!isset($facts[$id])) throw new HttpError(422, 'The advert references an unavailable fact.');
    advertText($p['message'], $config['limits']['message'], 'message');
    advertText($p['scenario'], 240, 'illustrative scenario', $p['format'] === 'headline');
    $p['items'] = advertStrings($p['items'], $config['limits']['maxItems'], $config['limits']['item'], 'items');
    if (($p['format'] === 'headline' && $p['items'] !== []) || ($p['format'] !== 'headline' && count($p['items']) < 2) || ($p['format'] === 'comparison' && count($p['items']) !== 2)) throw new HttpError(422, 'The advert format does not match its approved limits.');
    $approved = implode(' ', array_values($facts)) . ' ' . $feature['title'];
    $copy = $p['headline'] . ' ' . $p['message'] . ' ' . $p['scenario'] . ' ' . implode(' ', $p['items']);
    preg_match_all('/\d+(?:[.,]\d+)*/u', $approved, $knownNumbers);
    preg_match_all('/\d+(?:[.,]\d+)*/u', $copy, $numbers);
    if (array_diff($numbers[0], $knownNumbers[0]) || (preg_match('/[$£€]/u', $copy) && !preg_match('/[$£€]/u', $approved))) throw new HttpError(422, 'The advert contains an unapproved number or price.');
    // References and structural checks constrain output; they do not establish
    // semantic truth. Manual approval and optional independent model review do.
    return $p;
}

function advertPayloadCurrent(array $record, array $config, int $now): bool
{
    foreach (advertEligibleFeatures($config, $now) as $feature) {
        if ($feature['id'] === ($record['payload']['featureId'] ?? null) && hash_equals(advertFeatureHash($feature), $record['featureHash'] ?? '')) return true;
    }
    return false;
}

function advertHousekeeping(array &$state, int $now): void
{
    foreach (['current', 'candidate'] as $slot) {
        if ($state[$slot] !== null && (!advertPayloadCurrent($state[$slot], $state['config'], $now)
            || ($slot === 'candidate' && $state[$slot]['configRevision'] !== $state['configRevision']))) {
            $state[$slot] = null;
            advertAudit($state, $slot . '_invalidated', $now);
        }
    }
    if ($state['lease'] !== null && $state['lease']['expiresAt'] <= $now) {
        $begun = array_filter($state['lease']['grants'], static fn(array $grant): bool => $grant['begun'] && (!$grant['settled'] || ($grant['unknown'] ?? false)));
        $state['lease'] = null;
        $state['lastOutcome'] = $begun ? 'unknown_outcome_paused' : 'lease_expired';
        if ($begun) $state['config']['paused'] = true;
        $state['retryAt'] = $now + $state['config']['generation']['backoffSeconds'];
        advertAudit($state, $state['lastOutcome'], $now);
    }
}

function advertPromote(array &$state, int $now): void
{
    $candidate = $state['candidate'];
    if (!$state['config']['enabled'] || $state['config']['paused'] || !$candidate || !$candidate['approved']
        || $candidate['configRevision'] !== $state['configRevision'] || !advertPayloadCurrent($candidate, $state['config'], $now)) throw new HttpError(409, 'There is no approved current candidate to publish.');
    if ($state['current'] && ($state['nextEligibleAt'] ?? PHP_INT_MAX) > $now) throw new HttpError(409, 'The next publication time has not arrived.');
    if (!advertInWindow($state['config']['schedule'], $now)) throw new HttpError(409, 'Publication is outside the configured window.');
    $candidate['promotedAt'] = $now;
    $state['current'] = $candidate; // Old content is replaced, not archived.
    $state['candidate'] = null;
    $state['nextEligibleAt'] = advertNextEligible($state['config']['schedule'], $now);
    $state['attempts'] = 0;
    $state['retryAt'] = 0;
    $state['lastOutcome'] = 'published';
    advertAudit($state, 'published', $now);
}

function advertPublicSnapshot(array $environment, ?int $instant = null): array
{
    $now = $instant ?? time();
    return advertTransaction($environment, static function (array &$s) use ($now): array {
        advertHousekeeping($s, $now);
        $s['lastSeenAt'] = $now;
        $enabled = $s['config']['enabled'];
        $references = [];
        if ($enabled) foreach (advertEligibleFeatures($s['config'], $now) as $feature) $references[$feature['id']] = advertFeatureHash($feature);
        $current = $enabled ? $s['current'] : null;
        if ($current) {
            $feature = array_values(array_filter($s['config']['features'], static fn(array $f): bool => $f['id'] === $current['payload']['featureId']))[0];
            $current['validUntil'] = $feature['expiresAt'] === null ? null : strtotime($feature['expiresAt']);
        }
        return ['version' => $s['version'], 'enabled' => $enabled, 'references' => (object)$references, 'current' => $current,
            'serverTime' => $now];
    });
}

function advertAdminSnapshot(array $s, array $environment): array
{
    // Never return machine grants, nonces, signing secrets or environment values.
    return array_intersect_key($s, array_flip(['version', 'configRevision', 'draftVersion', 'config', 'draft', 'current', 'candidate', 'nextEligibleAt', 'retryAt', 'lastOutcome', 'usage', 'audit']))
        + ['models' => advertModels(), 'provider' => 'openai', 'credentials' => ['bridgeConfigured' => strlen($environment['ADVERTISING_SIGNING_SECRET'] ?? '') >= 32],
        'working' => $s['lease'] !== null || time() < ($s['blockedUntil'] ?? 0), 'excludedProposals' => ['countdown', 'adventure', 'recommendations', 'walking', 'quiz']];
}
