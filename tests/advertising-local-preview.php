<?php
declare(strict_types=1);
// Historical local sample command. Retired: it must not load application settings or state.
if (PHP_SAPI !== 'cli') { http_response_code(410); exit; }
fwrite(STDERR, "Retired advertising sample command. Use Gary's advertising admin and the normal local worker. No settings, state or provider calls were accessed.\n");
exit(1);
