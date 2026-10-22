<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors', '0');
ini_set('zend.exception_ignore_args', '1');
require __DIR__ . '/release/workflow.php';
$options = getopt('', ['action:', 'config:', 'credentials:', 'database:', 'site-root:', 'migrations:', 'plan:', 'confirm-plan:', 'dump:', 'client:', 'restore-database:', 'release:', 'verified', 'adopt-history', 'allow-data-change']);
$locks = null;
try {
    releaseAssert(in_array($options['action'] ?? '', ['plan', 'apply', 'status', 'resume'], true), 'Choose --action plan, apply, status or resume.');
    $context = releaseConnect($options);
    $locks = releaseLocks($context);
    switch ($options['action']) {
        case 'plan':
            $plan = createReleasePlan($context, $options);
            $path = $context['root'] . '/plan-' . $plan['id'] . '.json';
            echo json_encode(['plan' => $path, 'confirmPlan' => hash_file('sha256', $path), 'pending' => $plan['pending'], 'allowDataChange' => $plan['allowDataChange']], JSON_THROW_ON_ERROR) . PHP_EOL;
            break;
        case 'apply':
            $state = applyReleasePlan($context, $options);
            echo json_encode(['release' => $state['id'], 'phase' => $state['phase'], 'existingDataPreserved' => $state['existingDataPreserved']], JSON_THROW_ON_ERROR) . PHP_EOL;
            break;
        case 'status':
            releaseAssert(isset($options['release']), 'Supply --release.');
            $state = inspectRelease($context, $options['release']);
            echo json_encode(array_intersect_key($state, array_flip(['id', 'phase', 'failedDuring', 'existingDataPreserved'])), JSON_THROW_ON_ERROR) . PHP_EOL;
            break;
        case 'resume':
            resumeRelease($context, $options);
            echo "Maintenance ended after explicit verification.\n";
    }
} catch (Throwable $error) {
    // SQL/utility error details may contain private data: keep them out of stdout.
    fwrite(STDERR, "DATABASE RELEASE FAILED: " . ($error instanceof PDOException ? 'Database connection/query failed; inspect the private setup.' : $error->getMessage()) . PHP_EOL);
    $exitCode = 1;
} finally {
    if ($locks !== null) releaseUnlock($context, $locks);
}
exit($exitCode ?? 0);
