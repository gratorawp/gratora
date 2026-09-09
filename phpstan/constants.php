<?php

/**
 * Static-analysis constants. Paths must resolve real require targets; gratora.php needs
 * WordPress to run.
 */

declare(strict_types=1);

define('GRATORA_VERSION', '0.0.0');
define('GRATORA_DB_VERSION', '0.0.0');
define('GRATORA_FILE', dirname(__DIR__) . '/gratora.php');
define('GRATORA_DIR', dirname(__DIR__) . '/');
define('GRATORA_URL', 'https://example.test/');

// Prefer the test WordPress install; a standalone CI checkout has no WordPress parent
// directory.
define('ABSPATH', (static function (): string {
    $home       = getenv('HOME') ?: '';
    $candidates = array_values(array_filter([
        getenv('WP_CORE_DIR') ?: null,
        $home !== '' ? $home . '/.gratora-wp-tests/wordpress' : null,
        dirname(__DIR__, 4),
    ]));

    foreach ($candidates as $dir) {
        if (is_file(rtrim($dir, '/') . '/wp-admin/includes/plugin.php')) {
            return rtrim($dir, '/') . '/';
        }
    }

    return rtrim(end($candidates) ?: sys_get_temp_dir(), '/') . '/';
})());
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);
define('WEEK_IN_SECONDS', 604800);
define('MONTH_IN_SECONDS', 2592000);
define('YEAR_IN_SECONDS', 31536000);
define('WP_PLUGIN_DIR', '/wp-content/plugins');
