<?php

declare(strict_types=1);

use Dono\Foundation\Uninstall\DataEraser;

defined('WP_UNINSTALL_PLUGIN') || exit;

// Both, the way dono.php loads them. WordPress includes this file without the
// plugin, and every model extends a Strauss-prefixed base class that the
// composer autoloader alone cannot resolve: one require short, the erase
// fatals on the first query it makes.
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/vendor/vendor-prefixed/autoload.php';

if (! DataEraser::requested()) {
    return;
}

if (! is_multisite()) {
    (new DataEraser())->erase();
    return;
}

// Scoped so the loop variable cannot leak when this file is read at global scope.
(static function (): void {
    foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $siteId) {
        switch_to_blog((int) $siteId);
        try {
            (new DataEraser())->erase();
        } finally {
            // Without this the switch outlives a failure and every site after it
            // is erased against the wrong blog's tables.
            restore_current_blog();
        }
    }
})();
