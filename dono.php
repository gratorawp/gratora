<?php
/**
 * Plugin Name: Dono Fundraising Platform
 * Plugin URI: https://getdono.com
 * Description: A complete fundraising platform for WordPress
 * Version: 1.0.0
 * Requires at least: 7.0
 * Requires PHP: 8.1
 * Author: Dono
 * Author URI: https://getdono.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: dono-fundraising-platform
 * Domain Path: /languages
 */

declare(strict_types=1);

use Dono\Admin\Pages\FormsPage;
use Dono\Cli\CliCommands;
use Dono\Foundation\Plugin;

if (! defined('ABSPATH')) {
    exit;
}

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/vendor/vendor-prefixed/autoload.php';
require_once __DIR__ . '/vendor/woocommerce/action-scheduler/action-scheduler.php';

define('DONO_VERSION', '1.0.0');
define('DONO_DB_VERSION', '1.0.0');
define('DONO_FILE', __FILE__);
define('DONO_DIR', plugin_dir_path(__FILE__));
define('DONO_URL', plugin_dir_url(__FILE__));

register_activation_hook(__FILE__, [ Plugin::class, 'onActivation']);
register_deactivation_hook(__FILE__, [ Plugin::class, 'onDeactivation']);

add_action('plugins_loaded', static function (): void {
    Plugin::boot();

    if (defined('WP_CLI') && WP_CLI) {
        $cli = new CliCommands();
        WP_CLI::add_command('dono migrate', [$cli, 'migrate']);
        WP_CLI::add_command('dono recompute-aggregates', [$cli, 'recompute_aggregates']);
        WP_CLI::add_command('dono seed', [$cli, 'seed']);
        WP_CLI::add_command('dono demo-seed', [$cli, 'demo_seed']);
        WP_CLI::add_command('dono e2e-seed', [$cli, 'e2e_seed']);
    }
});

add_filter('show_admin_bar', static function ($show) {
    if ( FormsPage::isFormEditView()) {
        return false;
    }
    return $show;
});
