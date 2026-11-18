<?php
declare(strict_types=1);

function advertAdminAction(array $environment, array $body, ?int $instant = null, ?int $actorId = null): array
{
    $now = $instant ?? time();
    return advertTransaction($environment, static function (array &$s) use ($body, $now, $actorId): array {
        advertHousekeeping($s, $now);
        if (($body['version'] ?? null) !== $s['version']) throw new HttpError(409, 'Advertising changed elsewhere. Reload before continuing.', 'advert_conflict');
        $action = $body['action'] ?? '';
        if (in_array($action, ['apply', 'pause', 'resume', 'disable'], true) && $s['lease']) {
            // Fence cancelled work immediately but do not overlap a potentially
            // in-flight provider call with new work before its bounded deadline.
            $s['blockedUntil'] = max($s['blockedUntil'] ?? 0, $s['lease']['expiresAt']);
        }
        if ($action === 'save-draft') {
            if (($body['draftVersion'] ?? null) !== $s['draftVersion']) throw new HttpError(409, 'The advertising draft changed. Reload it first.');
            $s['draft'] = advertValidateConfig($body['config'] ?? null); $s['draftVersion']++;
        } elseif ($action === 'apply') {
            if (($body['draftVersion'] ?? null) !== $s['draftVersion'] || ($body['configRevision'] ?? null) !== $s['configRevision']) throw new HttpError(409, 'The advertising settings changed. Reload first.');
            $s['config'] = advertValidateConfig($s['draft']); $s['configRevision']++;
            $s['candidate'] = null; $s['lease'] = null; $s['attempts'] = 0; $s['retryAt'] = 0;
            if (!$s['config']['enabled']) { $s['current'] = null; $s['nextEligibleAt'] = null; }
            elseif ($s['current']) $s['nextEligibleAt'] = advertNextEligible($s['config']['schedule'], $s['current']['promotedAt']);
            advertHousekeeping($s, $now);
        } elseif (in_array($action, ['pause', 'resume', 'disable'], true)) {
            if ($action === 'disable') {
                $s['config']['enabled'] = false; $s['draft']['enabled'] = false;
                $s['current'] = null; $s['candidate'] = null; $s['nextEligibleAt'] = null;
                $s['configRevision']++; $s['draftVersion']++;
            } else {
                $s['config']['paused'] = $action === 'pause'; $s['draft']['paused'] = $s['config']['paused'];
                if ($action === 'resume') { $s['attempts'] = 0; $s['retryAt'] = $now + $s['config']['generation']['backoffSeconds']; }
            }
            $s['lease'] = null;
        } elseif (in_array($action, ['approve', 'reject', 'publish'], true)) {
            if (!$s['candidate'] || ($body['candidateId'] ?? null) !== $s['candidate']['id']) throw new HttpError(409, 'The displayed candidate is no longer current.');
            if ($action === 'approve') $s['candidate']['approved'] = true;
            elseif ($action === 'reject') { $s['candidate'] = null; $s['retryAt'] = $now + $s['config']['generation']['backoffSeconds']; }
            else advertPromote($s, $now);
        } elseif ($action !== 'prepare') throw new HttpError(404, 'Unknown advertising action.');
        $s['lastOutcome'] = $action;
        advertAudit($s, $action, $now, $actorId);
        return ['ok' => true];
    });
}

function advertGetAdmin(array $environment): array
{
    return advertTransaction($environment, static function (array &$s) use ($environment): array {
        advertHousekeeping($s, time()); return advertAdminSnapshot($s, $environment);
    });
}
