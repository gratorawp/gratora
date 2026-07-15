<?php
/**
 * PHPUnit bootstrap for the `integration` suite.
 *
 * Boots WordPress against an isolated test database created by
 * `bin/install-wp-tests.sh`. WP core lives at $WP_CORE_DIR (defaults to
 * /tmp/wordpress); test scaffolding ships in the wp-phpunit composer package
 * (vendor/wp-phpunit/wp-phpunit).
 *
 * Environment variables (all optional):
 *   WP_TESTS_DIR    Path to wp-tests-config.php directory.
 *                   Defaults to /tmp/wordpress-tests-lib.
 *   WP_PHPUNIT__DIR Path to the wp-phpunit package (set by composer plugin if
 *                   installed correctly). Defaults to vendor/wp-phpunit/wp-phpunit.
 *
 * First-time setup:
 *   bin/install-wp-tests.sh dono_test root '' localhost
 */

declare(strict_types=1);

$tests_dir = getenv('WP_TESTS_DIR') ?: (static function (): string {
    // Candidates in preference order: the persistent home install (immune to
    // macOS temp purges), then the historical temp locations. A candidate only
    // counts if its config exists AND the WP core its ABSPATH points at is
    // still on disk - temp cleanup can take one without the other.
    $home       = getenv('HOME') ?: '';
    $candidates = array_filter([
        $home !== '' ? $home . '/.dono-wp-tests/wordpress-tests-lib' : null,
        sys_get_temp_dir() . '/wordpress-tests-lib',
        '/tmp/wordpress-tests-lib',
    ]);
    foreach ($candidates as $dir) {
        $config = $dir . '/wp-tests-config.php';
        if (! file_exists($config)) {
            continue;
        }
        if (preg_match("/define\(\s*'ABSPATH',\s*'([^']+)'/", (string) file_get_contents($config), $m)
            && ! file_exists($m[1] . 'wp-settings.php')) {
            continue;
        }
        return $dir;
    }
    return sys_get_temp_dir() . '/wordpress-tests-lib';
})();
$phpunit_dir = getenv('WP_PHPUNIT__DIR') ?: __DIR__ . '/../vendor/wp-phpunit/wp-phpunit';

if (! file_exists($tests_dir . '/wp-tests-config.php')) {
    fwrite(STDERR, "\n");
    fwrite(STDERR, "Test config not found at {$tests_dir}/wp-tests-config.php.\n");
    fwrite(STDERR, "\n");
    fwrite(STDERR, "First-time setup:\n");
    fwrite(STDERR, "  bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host]\n");
    fwrite(STDERR, "\n");
    fwrite(STDERR, "Example:\n");
    fwrite(STDERR, "  bin/install-wp-tests.sh dono_test root '' localhost\n");
    fwrite(STDERR, "\n");
    exit(1);
}

if (! file_exists($phpunit_dir . '/includes/functions.php')) {
    fwrite(STDERR, "wp-phpunit not found at {$phpunit_dir}. Did you run `composer install`?\n");
    exit(1);
}

// Point wp-phpunit at our generated test config so it doesn't go looking in
// its own vendor directory. The constant must be defined (not an env var) and
// contains the full file path.
if (! defined('WP_TESTS_CONFIG_FILE_PATH')) {
    define('WP_TESTS_CONFIG_FILE_PATH', $tests_dir . '/wp-tests-config.php');
}

require_once $phpunit_dir . '/includes/functions.php';

// Load the plugin inside WP's "must-use" phase so it is active for every test,
// then immediately create the dono_* tables. boot() runs on plugins_loaded
// (which fires AFTER muplugins_loaded) and eagerly constructs services such as
// IdentityHasher that read wp_dono_system_settings, so the schema must exist
// before boot - hence migrating here rather than on wp_loaded.
tests_add_filter('muplugins_loaded', static function (): void {
    require dirname(__DIR__) . '/dono.php';
    \Dono\Foundation\Plugin::migrateSchema();
});

// Full activation (capabilities, onboarding seed, portal page, rewrite rules)
// once WordPress is fully loaded. Migrations re-run here harmlessly (idempotent).
tests_add_filter('wp_loaded', static function (): void {
    \Dono\Foundation\Plugin::onActivation();
}, 1);

// Per-test isolation rides WP_UnitTestCase's transaction (see
// IntegrationTestCase), so no test commits. This one-shot truncate only
// guards the suite against rows a previous, pre-isolation run may have
// committed into the persistent test DB; it keeps count-based assertions
// deterministic regardless of DB history. No test depends on bootstrap seed
// data (ActivationTest re-runs activation itself).
tests_add_filter('wp_loaded', static function (): void {
    global $wpdb;
    $like   = $wpdb->esc_like($wpdb->prefix . 'dono_') . '%';
    $tables = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $like));
    foreach ($tables as $table) {
        $wpdb->query('TRUNCATE TABLE `' . str_replace('`', '', $table) . '`');
    }
}, 5);

require $phpunit_dir . '/includes/bootstrap.php';
