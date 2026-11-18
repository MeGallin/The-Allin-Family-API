<?php
declare(strict_types=1);

function advertisingSupervisorSafeToArm(array $state, int $now): bool
{
    $config = $state['config'];
    return !$config['enabled'] || $config['paused'] || ($state['current'] !== null && $state['nextEligibleAt'] !== null
        && $state['nextEligibleAt'] - $config['schedule']['preparationLeadSeconds'] > $now);
}
