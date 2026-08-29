<?php

declare(strict_types=1);

namespace GiveFlow\Admin;

use GiveFlow\Foundation\Config\SystemSetting;
use GiveFlow\Foundation\Modules\ModuleManager;
use GiveFlow\Gateways\GatewayManager;

/**
 * What a support request needs to know about a site, in one place.
 *
 * Everything here is read only and safe to paste in public: gateway and
 * encryption state are reported as whether a thing is configured, never as the
 * thing itself, because this screen exists to be copied into a ticket.
 *
 * @since 1.0.0
 */
final class SystemReport
{
    /** Tables worth counting: the ones a support answer usually turns on. */
    private const COUNTED = [
        'giveflow_donations',
        'giveflow_donors',
        'giveflow_campaigns',
        'giveflow_forms',
        'giveflow_recurring_plans',
        'giveflow_funds',
        'giveflow_refunds',
        'giveflow_receipts',
    ];

    private const EXTENSIONS = [
        'curl', 'mbstring', 'openssl', 'json', 'intl', 'bcmath', 'sodium', 'zip', 'gd', 'dom',
    ];

    /** @since 1.0.0 */
    public function __construct(
        private ModuleManager $modules,
        private GatewayManager $gateways,
    ) {
    }

    /**
     * @return list<array{title:string, rows:list<array{label:string, value:string}>}>
     *
     * @since 1.0.0
     */
    public function sections(): array
    {
        return [
            ['title' => __('GiveFlow', 'giveflow-fundraising-campaigns'),      'rows' => $this->giveflow()],
            ['title' => __('Add-ons', 'giveflow-fundraising-campaigns'),       'rows' => $this->addOns()],
            ['title' => __('Payments', 'giveflow-fundraising-campaigns'),      'rows' => $this->payments()],
            ['title' => __('WordPress', 'giveflow-fundraising-campaigns'),     'rows' => $this->wordpress()],
            ['title' => __('Server', 'giveflow-fundraising-campaigns'),        'rows' => $this->server()],
            ['title' => __('Database', 'giveflow-fundraising-campaigns'),      'rows' => $this->database()],
            ['title' => __('Active plugins', 'giveflow-fundraising-campaigns'), 'rows' => $this->plugins()],
        ];
    }

    /** @return list<array{label:string, value:string}> */
    private function giveflow(): array
    {
        // Presence, never the value. The key decrypts every donor record on the
        // site, and this screen is written to be pasted into a ticket.
        $keyHeld = SystemSetting::exists('encryption_key_v1');
        $keyLost = SystemSetting::read('encryption_key_lost_at');

        $rows = [
            self::row(__('Version', 'giveflow-fundraising-campaigns'), defined('GIVEFLOW_VERSION') ? GIVEFLOW_VERSION : 'unknown'),
            self::row(__('Encryption key', 'giveflow-fundraising-campaigns'), self::yesNo($keyHeld)),
        ];

        // Loud on purpose: without the key the encrypted columns cannot be read
        // back, so a support answer starts here rather than anywhere else.
        if (is_string($keyLost) && $keyLost !== '') {
            $rows[] = self::row(__('Encryption key lost at', 'giveflow-fundraising-campaigns'), $keyLost);
        }

        return $rows;
    }

    /** @return list<array{label:string, value:string}> */
    private function addOns(): array
    {
        $rows = [];
        foreach ($this->modules->all() as $id => $module) {
            if ($id === 'core') {
                continue;
            }
            $rows[] = self::row(
                (string) $module->name(),
                sprintf('%s (%s)', (string) $module->version(), (string) $module->tier())
            );
        }

        // An add-on the site has installed but core refused to boot explains a
        // missing feature better than anything else on this screen.
        foreach ($this->modules->incompatible() as $id => $pair) {
            $rows[] = self::row(
                (string) $id,
                sprintf(
                    /* translators: 1: installed core version, 2: the version constraint the add-on asked for */
                    __('not loaded: core %1$s does not satisfy %2$s', 'giveflow-fundraising-campaigns'),
                    (string) ($pair[0] ?? '?'),
                    (string) ($pair[1] ?? '?')
                )
            );
        }

        return $rows ?: [self::row(__('Installed', 'giveflow-fundraising-campaigns'), __('None', 'giveflow-fundraising-campaigns'))];
    }

    /** @return list<array{label:string, value:string}> */
    private function payments(): array
    {
        $rows = [];
        foreach ($this->gateways->all() as $gateway) {
            // canCharge(), not the credentials: whether this gateway could take
            // a donation right now is the whole question, and the keys are not
            // ours to print.
            $rows[] = self::row(
                (string) $gateway->label(),
                $gateway->canCharge()
                    ? __('ready', 'giveflow-fundraising-campaigns')
                    : __('not configured', 'giveflow-fundraising-campaigns')
            );
        }

        return $rows ?: [self::row(__('Gateways', 'giveflow-fundraising-campaigns'), __('None registered', 'giveflow-fundraising-campaigns'))];
    }

    /** @return list<array{label:string, value:string}> */
    private function wordpress(): array
    {
        $theme  = wp_get_theme();
        $parent = $theme->parent();

        return [
            self::row(__('Version', 'giveflow-fundraising-campaigns'), get_bloginfo('version')),
            self::row(__('Site URL', 'giveflow-fundraising-campaigns'), site_url()),
            self::row(__('Home URL', 'giveflow-fundraising-campaigns'), home_url()),
            self::row(__('REST root', 'giveflow-fundraising-campaigns'), esc_url_raw(rest_url('giveflow/v1/'))),
            self::row(__('Multisite', 'giveflow-fundraising-campaigns'), self::yesNo(is_multisite())),
            self::row(__('Locale', 'giveflow-fundraising-campaigns'), get_locale()),
            self::row(__('Timezone', 'giveflow-fundraising-campaigns'), wp_timezone_string()),
            self::row(__('Permalinks', 'giveflow-fundraising-campaigns'), (string) get_option('permalink_structure') ?: __('plain', 'giveflow-fundraising-campaigns')),
            self::row(__('Theme', 'giveflow-fundraising-campaigns'), sprintf(
                '%s %s%s',
                (string) $theme->get('Name'),
                (string) $theme->get('Version'),
                $parent ? ' (child of ' . (string) $parent->get('Name') . ')' : ''
            )),
            self::row(__('Block theme', 'giveflow-fundraising-campaigns'), self::yesNo(wp_is_block_theme())),
            self::row(__('Memory limit', 'giveflow-fundraising-campaigns'), self::constantValue('WP_MEMORY_LIMIT')),
            self::row(__('Debug mode', 'giveflow-fundraising-campaigns'), self::yesNo(defined('WP_DEBUG') && WP_DEBUG)),
            // Action Scheduler rides WP-cron, so a site with this on has a
            // backlog that never drains and a screen that has to say so.
            self::row(__('WP-Cron disabled', 'giveflow-fundraising-campaigns'), self::yesNo(defined('DISABLE_WP_CRON') && DISABLE_WP_CRON)),
        ];
    }

    /** @return list<array{label:string, value:string}> */
    private function server(): array
    {
        $missing = array_values(array_filter(
            self::EXTENSIONS,
            static fn (string $ext): bool => ! extension_loaded($ext)
        ));

        $software = isset($_SERVER['SERVER_SOFTWARE'])
            ? sanitize_text_field(wp_unslash($_SERVER['SERVER_SOFTWARE']))
            : '';

        return [
            self::row(__('PHP version', 'giveflow-fundraising-campaigns'), PHP_VERSION),
            self::row(__('PHP interface', 'giveflow-fundraising-campaigns'), PHP_SAPI),
            self::row(__('Web server', 'giveflow-fundraising-campaigns'), $software !== '' ? $software : __('unknown', 'giveflow-fundraising-campaigns')),
            self::row(__('HTTPS', 'giveflow-fundraising-campaigns'), self::yesNo(is_ssl())),
            self::row(__('Memory limit', 'giveflow-fundraising-campaigns'), (string) ini_get('memory_limit')),
            self::row(__('Max execution time', 'giveflow-fundraising-campaigns'), (string) ini_get('max_execution_time')),
            self::row(__('Upload max filesize', 'giveflow-fundraising-campaigns'), (string) ini_get('upload_max_filesize')),
            self::row(__('Post max size', 'giveflow-fundraising-campaigns'), (string) ini_get('post_max_size')),
            self::row(__('Max input vars', 'giveflow-fundraising-campaigns'), (string) ini_get('max_input_vars')),
            self::row(
                __('Missing PHP extensions', 'giveflow-fundraising-campaigns'),
                $missing === [] ? __('None', 'giveflow-fundraising-campaigns') : implode(', ', $missing)
            ),
        ];
    }

    /** @return list<array{label:string, value:string}> */
    private function database(): array
    {
        global $wpdb;

        // The one place the query builder cannot answer: server metadata, and
        // whether a table this plugin expects is actually there. A missing table
        // is invisible everywhere else until something fails on a donor.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $version = (string) $wpdb->get_var('SELECT VERSION()');

        $rows = [
            self::row(__('Server', 'giveflow-fundraising-campaigns'), $version !== '' ? $version : __('unknown', 'giveflow-fundraising-campaigns')),
            self::row(__('Charset', 'giveflow-fundraising-campaigns'), (string) $wpdb->charset),
            self::row(__('Collation', 'giveflow-fundraising-campaigns'), (string) $wpdb->collate),
            self::row(__('Table prefix', 'giveflow-fundraising-campaigns'), (string) $wpdb->prefix),
        ];

        foreach (self::COUNTED as $base) {
            $table  = $wpdb->prefix . $base;
            $exists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;

            $rows[] = self::row(
                $table,
                $exists
                    ? sprintf(
                        /* translators: %s: a row count */
                        __('%s rows', 'giveflow-fundraising-campaigns'),
                        number_format_i18n((int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`")) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is built from $wpdb->prefix and a constant in this file, never from input.
                    )
                    : __('MISSING', 'giveflow-fundraising-campaigns')
            );
        }
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

        return $rows;
    }

    /** @return list<array{label:string, value:string}> */
    private function plugins(): array
    {
        if (! function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all    = get_plugins();
        $active = (array) get_option('active_plugins', []);
        if (is_multisite()) {
            $active = array_merge($active, array_keys((array) get_site_option('active_sitewide_plugins', [])));
        }

        $rows = [];
        foreach ($active as $file) {
            $data = $all[$file] ?? null;
            if ($data === null) {
                // Active but not on disk, which is itself worth reporting.
                $rows[] = self::row((string) $file, __('active, but the file is missing', 'giveflow-fundraising-campaigns'));
                continue;
            }
            $rows[] = self::row((string) $data['Name'], (string) $data['Version']);
        }

        foreach (get_mu_plugins() as $data) {
            $rows[] = self::row(
                (string) $data['Name'],
                sprintf(
                    /* translators: %s: plugin version */
                    __('%s (must-use)', 'giveflow-fundraising-campaigns'),
                    (string) $data['Version']
                )
            );
        }

        return $rows ?: [self::row(__('Active', 'giveflow-fundraising-campaigns'), __('None', 'giveflow-fundraising-campaigns'))];
    }

    /** @return array{label:string, value:string} */
    private static function row(string $label, string $value): array
    {
        return ['label' => $label, 'value' => $value];
    }

    private static function yesNo(bool $value): string
    {
        return $value
            ? __('Yes', 'giveflow-fundraising-campaigns')
            : __('No', 'giveflow-fundraising-campaigns');
    }

    private static function constantValue(string $name): string
    {
        return defined($name) ? (string) constant($name) : __('not set', 'giveflow-fundraising-campaigns');
    }
}
