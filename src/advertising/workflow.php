<?php
declare(strict_types=1);

function advertJobTimeout(array $config): int
{
    $review = $config['review']['enabled']; $image = $config['image']['enabled'] ?? false;
    $bridgeCalls = 2 + ($review ? 3 : 0) + ($image ? 4 : 0);
    return $config['generation']['timeoutSeconds'] * (1 + (int)$review) + ($image ? $config['image']['timeoutSeconds'] : 0) + $bridgeCalls * 8 + 30;
}

function advertClaimJob(array $environment, bool $manual = false, ?int $instant = null): ?array
{
    $now = $instant ?? time();
    return advertTransaction($environment, static function (array &$s) use ($now, $manual): ?array {
        advertHousekeeping($s, $now);
        $c = $s['config'];
        if (!$c['enabled'] || $c['paused'] || $s['lease'] || $now < ($s['blockedUntil'] ?? 0)) return null;
        if (!$manual && $c['schedule']['idlePolicy'] !== 'keep' && $now - $s['lastSeenAt'] >= $c['schedule']['idleSeconds']) return null;
        if (!$manual && !advertInWindow($c['schedule'], $now)) return null;
        if ($s['candidate']) {
            if (!$manual && $s['candidate']['approved'] && ($s['current'] === null || $now >= ($s['nextEligibleAt'] ?? PHP_INT_MAX))) {
                advertPromote($s, $now);
                // Commit this paid candidate independently. A next-job budget
                // refusal must never roll back a valid publication.
                return null;
            }
            else return null;
        }
        if (!$manual && $s['current'] !== null && $now < ($s['nextEligibleAt'] ?? PHP_INT_MAX) - $c['schedule']['preparationLeadSeconds']) return null;
        if ($now < $s['retryAt']) return null;
        if ($s['attempts'] > $c['generation']['retries']) {
            $s['lastOutcome'] = 'retry_limit_paused'; $s['config']['paused'] = true;
            advertAudit($s, 'retry_limit_paused', $now); return null;
        }
        $features = advertEligibleFeatures($c, $now);
        if (!$features) { $s['lastOutcome'] = 'no_eligible_features'; return null; }
        // Refuse a cycle before even its text call when the optional review or
        // image count/currency reservation cannot fit. One live lease ensures
        // these phase budgets cannot be consumed by a competing cycle.
        $estimated = $s;
        advertReserveCall($estimated, 'generation', $now);
        if ($c['review']['enabled']) advertReserveCall($estimated, 'review', $now);
        if ($c['image']['enabled']) advertReserveCall($estimated, 'image', $now);
        $feature = advertSelectFeature($s, $features, $s['current']['payload']['featureId'] ?? null);
        $layout = $c['image']['enabled'] ? advertNextLayout($c['image']['layouts'], $s['current']['payload']['composition'] ?? null) : null;
        // Reserve generation before exposing a job; review reserves independently
        // just before its call. Both share daily/monthly atomic counters.
        $reservation = advertReserveCall($s, 'generation', $now);
        $s['fence']++; $s['attempts']++;
        $id = bin2hex(random_bytes(16)); $grant = bin2hex(random_bytes(16));
        $s['lease'] = ['id' => $id, 'fence' => $s['fence'], 'configRevision' => $s['configRevision'],
            'expiresAt' => $now + advertJobTimeout($c) + 20, 'feature' => $feature, 'composition' => $layout,
            'grants' => [$grant => ['kind' => 'generation', 'begun' => false, 'settled' => false, 'reservation' => $reservation]]];
        $s['lastOutcome'] = 'preparing'; advertAudit($s, 'prepare_claimed', $now);
        $jobConfig = array_intersect_key($c, array_flip(['brand', 'formats', 'limits', 'generation', 'review', 'image']));
        if ($layout !== null) $jobConfig['image']['layouts'] = [$layout];
        return ['jobId' => $id, 'fence' => $s['fence'], 'configRevision' => $s['configRevision'], 'grantId' => $grant,
            'feature' => $feature, 'config' => $jobConfig,
            'previous' => $s['current']['payload'] ?? null];
    });
}

function advertLease(array &$s, array $body, int $now): array
{
    $lease = $s['lease'];
    if (!$lease || ($body['jobId'] ?? null) !== $lease['id'] || ($body['fence'] ?? null) !== $lease['fence']
        || $lease['configRevision'] !== $s['configRevision'] || $lease['expiresAt'] <= $now
        || !$s['config']['enabled'] || $s['config']['paused']) throw new HttpError(409, 'This advertising job is no longer current.', 'advert_fenced');
    return $lease;
}

function advertMachineMutation(array &$s, array $body, int $now): array
{
    $lease = advertLease($s, $body, $now);
    $action = $body['action'] ?? '';
    if ($action === 'review-grant' || $action === 'image-grant') {
        $kind = $action === 'review-grant' ? 'review' : 'image';
        $expected = $kind === 'review' ? 1 : 1 + (int)$s['config']['review']['enabled'];
        if (!$s['config'][$kind]['enabled'] || count($lease['grants']) !== $expected || count(array_filter($lease['grants'], static fn(array $g): bool => $g['begun'] && $g['settled'])) !== $expected) throw new HttpError(409, 'The next generation phase is not ready.');
        $reservation = advertReserveCall($s, $kind, $now);
        $id = bin2hex(random_bytes(16));
        $s['lease']['grants'][$id] = ['kind' => $kind, 'begun' => false, 'settled' => false, 'reservation' => $reservation];
        return ['grantId' => $id];
    }
    $id = $body['grantId'] ?? '';
    $grant = $lease['grants'][$id] ?? null;
    if (!$grant) throw new HttpError(409, 'Unknown call grant.');
    if ($action === 'begin') {
        if ($grant['begun']) throw new HttpError(409, 'This call grant has already been used.', 'advert_replay');
        $s['lease']['grants'][$id]['begun'] = true;
        return ['allowed' => true];
    }
    if ($action === 'settle') {
        if (!$grant['begun']) throw new HttpError(409, 'Call grant has not begun.');
        if (!$grant['settled']) {
            $known = advertSettleCall($s, $grant, isset($body['usage']) && is_array($body['usage']) ? $body['usage'] : (isset($body['usage']) && is_object($body['usage']) ? (array)$body['usage'] : null));
            $s['lease']['grants'][$id]['unknown'] = !$known;
            $s['lease']['grants'][$id]['settled'] = true;
        }
        return ['settled' => true];
    }
    throw new HttpError(404, 'Unknown advertising machine action.');
}

function advertFinishJob(array $environment, array $job, ?array $result, ?int $instant = null): void
{
    $now = $instant ?? time();
    advertTransaction($environment, static function (array &$s) use ($job, $result, $now): void {
        try { $lease = advertLease($s, $job, $now); } catch (HttpError $error) { return; }
        $success = ($result['status'] ?? null) === 'ready';
        $settled = count(array_filter($lease['grants'], static fn(array $g): bool => $g['begun'] && $g['settled']));
        if ($success && $settled === 1 + (int)$s['config']['review']['enabled'] + (int)$s['config']['image']['enabled']) {
            try {
                $payload = advertValidPayload($result['payload'] ?? null, $lease['feature'], $s['config']);
                if ($s['config']['image']['enabled'] && $payload['composition'] !== $lease['composition']) throw new HttpError(422, 'This candidate does not use its assigned rotation layout.');
                if ($s['config']['image']['enabled'] && (!isset($lease['image']) || ($result['imageId'] ?? null) !== $lease['image']['id'])) throw new HttpError(422, 'The advert is missing its paired generated image.');
                $s['candidate'] = ['id' => bin2hex(random_bytes(16)), 'payload' => $payload,
                    'featureHash' => advertFeatureHash($lease['feature']), 'configRevision' => $s['configRevision'],
                    'preparedAt' => $now, 'approved' => !$s['config']['manualApproval'], 'promotedAt' => null];
                if ($s['config']['image']['enabled']) $s['candidate']['image'] = $lease['image'];
                $s['lastOutcome'] = 'candidate_ready'; $s['attempts'] = 0;
            } catch (HttpError $error) { $success = false; $s['lastOutcome'] = 'validation_failed'; }
        } else {
            $success = false;
            $s['lastOutcome'] = ($result['status'] ?? null) === 'rejected' ? 'generation_rejected' : 'unknown_outcome_paused';
            if ($s['lastOutcome'] === 'unknown_outcome_paused') {
                $s['config']['paused'] = true;
                $s['blockedUntil'] = max($s['blockedUntil'] ?? 0, $lease['expiresAt']);
            }
        }
        $s['lease'] = null;
        if (!$success) $s['retryAt'] = $now + $s['config']['generation']['backoffSeconds'];
        advertAudit($s, $s['lastOutcome'], $now);
    });
}
