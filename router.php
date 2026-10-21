<?php
declare(strict_types=1);

// Local PHP development server: every request goes through the API.
// Never return false here, which would expose files from the repository.
$_SERVER['SCRIPT_NAME'] = '/index.php';
require __DIR__ . '/index.php';
