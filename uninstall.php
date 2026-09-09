<?php

declare(strict_types=1);

use Gratora\Analytics\ErrorLog;
use Gratora\Foundation\Uninstall\DataEraser;

defined('WP_UNINSTALL_PLUGIN') || exit;

// Both, the way gratora.php loads them. WordPress includes this file without the
// plugin, and every model extends a Strauss-prefixed base class that the
// composer autoloader alone cannot resolve: one require short, the erase
// fatals on the first query it makes.
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/vendor/vendor-prefixed/autoload.php';

if (! DataEraser::requested()) {
    return;
}

if (! is_multisite()) {
    try {
        (new DataEraser())->erase();
    } catch (Throwable $e) {
        // Reachable as a retry over a site the deactivation half-erased, so it
        // is guarded the way the network loop below already is.
        ErrorLog::toDebugLog('deleting data on uninstall failed: ' . $e->getMessage());
    }

    return;
}

// Scoped so the loop variable cannot leak when this file is read at global scope.
(static function (): void {
    // Scoped to this network: a multi-network install's other networks are not
    // what was uninstalled.
    $sites = get_sites([
        'fields'     => 'ids',
        'number'     => 0,
        'network_id' => get_current_network_id(),
    ]);

    foreach ($sites as $siteId) {
        switch_to_blog((int) $siteId);
        try {
            (new DataEraser())->erase();
        } catch (Throwable $e) {
            // A site the plugin was never active on has no tables to drop, and
            // an add-on listening on gratora.uninstall can throw for reasons of
            // its own. Whatever the reason, one site cannot end the wipe: every
            // site after it would silently keep its donors while the owner was
            // told the data was gone.
            ErrorLog::toDebugLog("deleting data on site {$siteId} failed: " . $e->getMessage());
        } finally {
            // Without this the switch outlives a failure and every site after it
            // is erased against the wrong blog's tables.
            restore_current_blog();
        }
    }
})();
