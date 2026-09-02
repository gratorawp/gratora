<?php
/**
 * Plugin Name: Fundraising Toolkit - Donation & Fundraising Platform
 * Plugin URI: https://fundkit.net
 * Description: Donation & Fundraising Platform for WordPress
 * Version: 1.0.0
 * Requires at least: 7.0
 * Requires PHP: 8.1
 * Author: Fundraising Toolkit
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: fundraising-toolkit
 * Domain Path: /languages
 */

/**
 * FundKit, a fundraising platform for WordPress.
 *
 * Copyright (C) 2026 FundKit
 *
 * This program is free software; you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation; either version 2 of the License, or (at your option)
 * any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
 * more details.
 *
 * You should have received a copy of the GNU General Public License along with
 * this program; if not, write to the Free Software Foundation, Inc., 51
 * Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 */

declare(strict_types=1);

use FundKit\Cli\CliCommands;
use FundKit\Foundation\Database\WordPressSchema;
use FundKit\Foundation\Plugin;

if (! defined('ABSPATH')) {
    exit;
}

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/vendor/vendor-prefixed/autoload.php';
require_once __DIR__ . '/vendor/woocommerce/action-scheduler/action-scheduler.php';

WordPressSchema::register();

define('FUNDKIT_VERSION', '1.0.0');
define('FUNDKIT_DB_VERSION', '1.1.0');
define('FUNDKIT_FILE', __FILE__);
define('FUNDKIT_DIR', plugin_dir_path(__FILE__));
define('FUNDKIT_URL', plugin_dir_url(__FILE__));

register_activation_hook(__FILE__, [ Plugin::class, 'onPluginActivated']);
register_deactivation_hook(__FILE__, [ Plugin::class, 'onDeactivation']);

add_action('plugins_loaded', static function (): void {
    Plugin::boot();

    if (defined('WP_CLI') && WP_CLI) {
        $cli = new CliCommands();
        WP_CLI::add_command('fundkit migrate', [$cli, 'migrate']);
        WP_CLI::add_command('fundkit recompute-aggregates', [$cli, 'recompute_aggregates']);
        WP_CLI::add_command('fundkit seed', [$cli, 'seed']);
        WP_CLI::add_command('fundkit demo-seed', [$cli, 'demo_seed']);
        WP_CLI::add_command('fundkit e2e-seed', [$cli, 'e2e_seed']);
    }
});
