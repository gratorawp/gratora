<?php

declare(strict_types=1);

use Dono\Foundation\Uninstall\DataEraser;

defined('WP_UNINSTALL_PLUGIN') || exit;

require_once __DIR__ . '/vendor/autoload.php';

if (! DataEraser::requested()) {
    return;
}

if (! is_multisite()) {
    (new DataEraser())->erase();
    return;
}

foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $siteId) {
    switch_to_blog((int) $siteId);
    (new DataEraser())->erase();
    restore_current_blog();
}
