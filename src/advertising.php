<?php
declare(strict_types=1);
foreach (['catalogue', 'config', 'store', 'schedule', 'budget', 'assets', 'rotation', 'publication', 'workflow', 'machine', 'admin'] as $advertModule) require_once __DIR__ . '/advertising/' . $advertModule . '.php';
unset($advertModule);
