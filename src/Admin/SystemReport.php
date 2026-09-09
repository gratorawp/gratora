<?php

declare(strict_types=1);

namespace Gratora\Admin;

use Gratora\Foundation\Config\SystemSetting;
use Gratora\Foundation\Http\ClientIp;
use Gratora\Foundation\Modules\ModuleManager;
use Gratora\Gateways\GatewayManager;

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
    private const COUNTED = [
        'gratora_donations',
        'gratora_donors',
        'gratora_campaigns',
        'gratora_forms',
        'gratora_recurring_plans',
        'gratora_funds',
        'gratora_refunds',
        'gratora_receipts',
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
            ['title' => __('Gratora', 'gratora'),      'rows' => $this->gratora()],
            ['title' => __('Add-ons', 'gratora'),       'rows' => $this->addOns()],
            ['title' => __('Payments', 'gratora'),      'rows' => $this->payments()],
            ['title' => __('WordPress', 'gratora'),     'rows' => $this->wordpress()],
            ['title' => __('Server', 'gratora'),        'rows' => $this->server()],
            ['title' => __('Database', 'gratora'),      'rows' => $this->database()],
            ['title' => __('Active plugins', 'gratora'), 'rows' => $this->plugins()],
        ];
    }

    /** @return list<array{label:string, value:string}> */
    private function gratora(): array
    {
        // Report key presence only; this output is shared with support.
        $keyHeld = SystemSetting::exists('encryption_key_v1');
        $keyLost = SystemSetting::read('encryption_key_lost_at');

        $rows = [
            self::row(__('Version', 'gratora'), defined('GRATORA_VERSION') ? GRATORA_VERSION : 'unknown'),
            self::row(__('Encryption key', 'gratora'), self::yesNo($keyHeld)),
        ];

        if (is_string($keyLost) && $keyLost !== '') {
            $rows[] = self::row(__('Encryption key lost at', 'gratora'), $keyLost);
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

        foreach ($this->modules->incompatible() as $id => $pair) {
            $rows[] = self::row(
                (string) $id,
                sprintf(
                    /* translators: 1: installed core version, 2: the version constraint the add-on asked for */
                    __('not loaded: core %1$s does not satisfy %2$s', 'gratora'),
                    (string) ($pair[0] ?? '?'),
                    (string) ($pair[1] ?? '?')
                )
            );
        }

        return $rows ?: [self::row(__('Installed', 'gratora'), __('None', 'gratora'))];
    }

    /** @return list<array{label:string, value:string}> */
    private function payments(): array
    {
        $rows = [];
        foreach ($this->gateways->all() as $gateway) {
            // Report charge readiness without exposing credentials.
            $rows[] = self::row(
                (string) $gateway->label(),
                $gateway->canCharge()
                    ? __('ready', 'gratora')
                    : __('not configured', 'gratora')
            );
        }

        return $rows ?: [self::row(__('Gateways', 'gratora'), __('None registered', 'gratora'))];
    }

    /** @return list<array{label:string, value:string}> */
    private function wordpress(): array
    {
        $theme  = wp_get_theme();
        $parent = $theme->parent();

        return [
            self::row(__('Version', 'gratora'), get_bloginfo('version')),
            self::row(__('Site URL', 'gratora'), site_url()),
            self::row(__('Home URL', 'gratora'), home_url()),
            self::row(__('REST root', 'gratora'), esc_url_raw(rest_url('gratora/v1/'))),
            self::row(__('Multisite', 'gratora'), self::yesNo(is_multisite())),
            self::row(__('Locale', 'gratora'), get_locale()),
            self::row(__('Timezone', 'gratora'), wp_timezone_string()),
            self::row(__('Permalinks', 'gratora'), (string) get_option('permalink_structure') ?: __('plain', 'gratora')),
            self::row(__('Theme', 'gratora'), sprintf(
                '%s %s%s',
                (string) $theme->get('Name'),
                (string) $theme->get('Version'),
                $parent ? ' (child of ' . (string) $parent->get('Name') . ')' : ''
            )),
            self::row(__('Block theme', 'gratora'), self::yesNo(wp_is_block_theme())),
            self::row(__('Memory limit', 'gratora'), self::constantValue('WP_MEMORY_LIMIT')),
            self::row(__('Debug mode', 'gratora'), self::yesNo(defined('WP_DEBUG') && WP_DEBUG)),
            // Action Scheduler depends on WP-cron.
            self::row(__('WP-Cron disabled', 'gratora'), self::yesNo(defined('DISABLE_WP_CRON') && DISABLE_WP_CRON)),
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
            self::row(__('PHP version', 'gratora'), PHP_VERSION),
            self::row(__('PHP interface', 'gratora'), PHP_SAPI),
            self::row(__('Web server', 'gratora'), $software !== '' ? $software : __('unknown', 'gratora')),
            self::row(__('HTTPS', 'gratora'), self::yesNo(is_ssl())),
            // The shape, never the address: this screen is written to be pasted
            // into a ticket, and a visitor's IP is theirs. It still answers the
            // only question an admin has here, which is whether the proxy
            // configuration is doing anything: declare ranges and see this flip
            // to "forwarded header", or it is not matching your edge.
            self::row(
                __('Trusted proxies', 'gratora'),
                ($count = count(ClientIp::trustedProxies())) > 0
                    /* translators: %d: number of declared CIDR ranges */
                    ? sprintf(_n('%d range', '%d ranges', $count, 'gratora'), $count)
                    : __('none declared', 'gratora')
            ),
            self::row(
                __('Visitor address from', 'gratora'),
                ClientIp::resolve() !== ClientIp::remote()
                    ? __('forwarded header', 'gratora')
                    : __('REMOTE_ADDR', 'gratora')
            ),
            self::row(
                __('Undeclared proxy in front', 'gratora'),
                self::yesNo(ClientIp::looksProxied())
            ),
            self::row(__('Memory limit', 'gratora'), (string) ini_get('memory_limit')),
            self::row(__('Max execution time', 'gratora'), (string) ini_get('max_execution_time')),
            self::row(__('Upload max filesize', 'gratora'), (string) ini_get('upload_max_filesize')),
            self::row(__('Post max size', 'gratora'), (string) ini_get('post_max_size')),
            self::row(__('Max input vars', 'gratora'), (string) ini_get('max_input_vars')),
            self::row(
                __('Missing PHP extensions', 'gratora'),
                $missing === [] ? __('None', 'gratora') : implode(', ', $missing)
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
            self::row(__('Server', 'gratora'), $version !== '' ? $version : __('unknown', 'gratora')),
            self::row(__('Charset', 'gratora'), (string) $wpdb->charset),
            self::row(__('Collation', 'gratora'), (string) $wpdb->collate),
            self::row(__('Table prefix', 'gratora'), (string) $wpdb->prefix),
        ];

        foreach (self::COUNTED as $base) {
            $table  = $wpdb->prefix . $base;
            $exists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;

            $rows[] = self::row(
                $table,
                $exists
                    ? sprintf(
                        /* translators: %s: a row count */
                        __('%s rows', 'gratora'),
                        number_format_i18n((int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`")) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is built from $wpdb->prefix and a constant in this file, never from input.
                    )
                    : __('MISSING', 'gratora')
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
                $rows[] = self::row((string) $file, __('active, but the file is missing', 'gratora'));
                continue;
            }
            $rows[] = self::row((string) $data['Name'], (string) $data['Version']);
        }

        foreach (get_mu_plugins() as $data) {
            $rows[] = self::row(
                (string) $data['Name'],
                sprintf(
                    /* translators: %s: plugin version */
                    __('%s (must-use)', 'gratora'),
                    (string) $data['Version']
                )
            );
        }

        return $rows ?: [self::row(__('Active', 'gratora'), __('None', 'gratora'))];
    }

    /** @return array{label:string, value:string} */
    private static function row(string $label, string $value): array
    {
        return ['label' => $label, 'value' => $value];
    }

    private static function yesNo(bool $value): string
    {
        return $value
            ? __('Yes', 'gratora')
            : __('No', 'gratora');
    }

    private static function constantValue(string $name): string
    {
        return defined($name) ? (string) constant($name) : __('not set', 'gratora');
    }
}
