<?php

/**
 * Constants static analysis needs and cannot get any other way.
 *
 * The plugin's own are defined at the top of fundkit.php from
 * plugin_dir_path(), which cannot run outside WordPress, so the real file
 * cannot be bootstrapped here. WordPress's time constants and ABSPATH are not
 * in the stub package. Only the fact that they exist matters, except the paths,
 * which have to point at the real plugin root so `require` targets resolve.
 */

declare(strict_types=1);

define('FUNDKIT_VERSION', '0.0.0');
define('FUNDKIT_DB_VERSION', '0.0.0');
define('FUNDKIT_FILE', dirname(__DIR__) . '/fundkit.php');
define('FUNDKIT_DIR', dirname(__DIR__) . '/');
define('FUNDKIT_URL', 'https://example.test/');

// Analysis resolves `require ABSPATH . 'wp-admin/...'` targets, so this has to
// be a real WordPress. Deriving it from this file's position only holds on a
// machine where the plugin sits inside one: on CI the checkout is standalone
// and four levels up is the runner's home, where every require target is
// missing. Prefer the install the test environment provisions.
define('ABSPATH', (static function (): string {
    $home       = getenv('HOME') ?: '';
    $candidates = array_values(array_filter([
        getenv('WP_CORE_DIR') ?: null,
        $home !== '' ? $home . '/.fundkit-wp-tests/wordpress' : null,
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
