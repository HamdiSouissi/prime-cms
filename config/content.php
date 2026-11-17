<?php

declare(strict_types=1);

use Prime\Kernel\Env;

$revisionsMax = (int) Env::get('CONTENT_REVISIONS_MAX', 25);

return [
    'revisions_max' => max(0, $revisionsMax),
    'lock_ttl_seconds' => 120,
    'lock_heartbeat_seconds' => 30,
    'autosave_interval_seconds' => 30,
    'trash_retention_days' => 30,
];
