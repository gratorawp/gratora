<?php

declare(strict_types=1);

namespace FundKit\Admin;

use FundKit\Foundation\Config\SystemSetting;
use FundKit\Foundation\Modules\ModuleManager;
use FundKit\Gateways\GatewayManager;

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
        'fundkit_donations',
        'fundkit_donors',
        'fundkit_campaigns',
        'fundkit_forms',
        'fundkit_recurring_plans',
        'fundkit_funds',
        'fundkit_refunds',
        'fundkit_receipts',
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
            ['title' => __('Fundraising Toolkit', 'fundraising-toolkit'),      'rows' => $this->fundkit()],
            ['title' => __('Add-ons', 'fundraising-toolkit'),       'rows' => $this->addOns()],
            ['title' => __('Payments', 'fundraising-toolkit'),      'rows' => $this->payments()],
            ['title' => __('WordPress', 'fundraising-toolkit'),     'rows' => $this->wordpress()],
            ['title' => __('Server', 'fundraising-toolkit'),        'rows' => $this->server()],
            ['title' => __('Database', 'fundraising-toolkit'),      'rows' => $this->database()],
            ['title' => __('Active plugins', 'fundraising-toolkit'), 'rows' => $this->plugins()],
        ];
    }

    /** @return list<array{label:string, value:string}> */
    private function fundkit(): array
    {
        // Presence, never the value. The key decrypts every donor record on the
        // site, and this screen is written to be pasted into a ticket.
        $keyHeld = SystemSetting::exists('encryption_key_v1');
        $keyLost = SystemSetting::read('encryption_key_lost_at');

        $rows = [
            self::row(__('Version', 'fundraising-toolkit'), defined('FUNDKIT_VERSION') ? FUNDKIT_VERSION : 'unknown'),
            self::row(__('Encryption key', 'fundraising-toolkit'), self::yesNo($keyHeld)),
        ];

        // Loud on purpose: without the key the encrypted columns cannot be read
        // back, so a support answer starts here rather than anywhere else.
        if (is_string($keyLost) && $keyLost !== '') {
            $rows[] = self::row(__('Encryption key lost at', 'fundraising-toolkit'), $keyLost);
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
                    __('not loaded: core %1$s does not satisfy %2$s', 'fundraising-toolkit'),
                    (string) ($pair[0] ?? '?'),
                    (string) ($pair[1] ?? '?')
                )
            );
        }

        return $rows ?: [self::row(__('Installed', 'fundraising-toolkit'), __('None', 'fundraising-toolkit'))];
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
                    ? __('ready', 'fundraising-toolkit')
                    : __('not configured', 'fundraising-toolkit')
            );
        }

        return $rows ?: [self::row(__('Gateways', 'fundraising-toolkit'), __('None registered', 'fundraising-toolkit'))];
    }

    /** @return list<array{label:string, value:string}> */
    private function wordpress(): array
    {
        $theme  = wp_get_theme();
        $parent = $theme->parent();

        return [
            self::row(__('Version', 'fundraising-toolkit'), get_bloginfo('version')),
            self::row(__('Site URL', 'fundraising-toolkit'), site_url()),
            self::row(__('Home URL', 'fundraising-toolkit'), home_url()),
            self::row(__('REST root', 'fundraising-toolkit'), esc_url_raw(rest_url('fundkit/v1/'))),
            self::row(__('Multisite', 'fundraising-toolkit'), self::yesNo(is_multisite())),
            self::row(__('Locale', 'fundraising-toolkit'), get_locale()),
            self::row(__('Timezone', 'fundraising-toolkit'), wp_timezone_string()),
            self::row(__('Permalinks', 'fundraising-toolkit'), (string) get_option('permalink_structure') ?: __('plain', 'fundraising-toolkit')),
            self::row(__('Theme', 'fundraising-toolkit'), sprintf(
                '%s %s%s',
                (string) $theme->get('Name'),
                (string) $theme->get('Version'),
                $parent ? ' (child of ' . (string) $parent->get('Name') . ')' : ''
            )),
            self::row(__('Block theme', 'fundraising-toolkit'), self::yesNo(wp_is_block_theme())),
            self::row(__('Memory limit', 'fundraising-toolkit'), self::constantValue('WP_MEMORY_LIMIT')),
            self::row(__('Debug mode', 'fundraising-toolkit'), self::yesNo(defined('WP_DEBUG') && WP_DEBUG)),
            // Action Scheduler rides WP-cron, so a site with this on has a
            // backlog that never drains and a screen that has to say so.
            self::row(__('WP-Cron disabled', 'fundraising-toolkit'), self::yesNo(defined('DISABLE_WP_CRON') && DISABLE_WP_CRON)),
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
            self::row(__('PHP version', 'fundraising-toolkit'), PHP_VERSION),
            self::row(__('PHP interface', 'fundraising-toolkit'), PHP_SAPI),
            self::row(__('Web server', 'fundraising-toolkit'), $software !== '' ? $software : __('unknown', 'fundraising-toolkit')),
            self::row(__('HTTPS', 'fundraising-toolkit'), self::yesNo(is_ssl())),
            self::row(__('Memory limit', 'fundraising-toolkit'), (string) ini_get('memory_limit')),
            self::row(__('Max execution time', 'fundraising-toolkit'), (string) ini_get('max_execution_time')),
            self::row(__('Upload max filesize', 'fundraising-toolkit'), (string) ini_get('upload_max_filesize')),
            self::row(__('Post max size', 'fundraising-toolkit'), (string) ini_get('post_max_size')),
            self::row(__('Max input vars', 'fundraising-toolkit'), (string) ini_get('max_input_vars')),
            self::row(
                __('Missing PHP extensions', 'fundraising-toolkit'),
                $missing === [] ? __('None', 'fundraising-toolkit') : implode(', ', $missing)
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
            self::row(__('Server', 'fundraising-toolkit'), $version !== '' ? $version : __('unknown', 'fundraising-toolkit')),
            self::row(__('Charset', 'fundraising-toolkit'), (string) $wpdb->charset),
            self::row(__('Collation', 'fundraising-toolkit'), (string) $wpdb->collate),
            self::row(__('Table prefix', 'fundraising-toolkit'), (string) $wpdb->prefix),
        ];

        foreach (self::COUNTED as $base) {
            $table  = $wpdb->prefix . $base;
            $exists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;

            $rows[] = self::row(
                $table,
                $exists
                    ? sprintf(
                        /* translators: %s: a row count */
                        __('%s rows', 'fundraising-toolkit'),
                        number_format_i18n((int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`")) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is built from $wpdb->prefix and a constant in this file, never from input.
                    )
                    : __('MISSING', 'fundraising-toolkit')
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
                $rows[] = self::row((string) $file, __('active, but the file is missing', 'fundraising-toolkit'));
                continue;
            }
            $rows[] = self::row((string) $data['Name'], (string) $data['Version']);
        }

        foreach (get_mu_plugins() as $data) {
            $rows[] = self::row(
                (string) $data['Name'],
                sprintf(
                    /* translators: %s: plugin version */
                    __('%s (must-use)', 'fundraising-toolkit'),
                    (string) $data['Version']
                )
            );
        }

        return $rows ?: [self::row(__('Active', 'fundraising-toolkit'), __('None', 'fundraising-toolkit'))];
    }

    /** @return array{label:string, value:string} */
    private static function row(string $label, string $value): array
    {
        return ['label' => $label, 'value' => $value];
    }

    private static function yesNo(bool $value): string
    {
        return $value
            ? __('Yes', 'fundraising-toolkit')
            : __('No', 'fundraising-toolkit');
    }

    private static function constantValue(string $name): string
    {
        return defined($name) ? (string) constant($name) : __('not set', 'fundraising-toolkit');
    }
}
