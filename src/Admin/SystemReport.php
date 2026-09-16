<?php

declare(strict_types=1);

namespace Gratora\Admin;

use Gratora\Foundation\Config\SystemSetting;
use Gratora\Foundation\Http\ClientIp;
use Gratora\Foundation\Modules\ModuleManager;
use Gratora\Foundation\Uninstall\DataEraser;
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
            ['title' => __('Gratora', 'gratora-donation-platform'),      'rows' => $this->gratora()],
            ['title' => __('Add-ons', 'gratora-donation-platform'),       'rows' => $this->addOns()],
            ['title' => __('Payments', 'gratora-donation-platform'),      'rows' => $this->payments()],
            ['title' => __('WordPress', 'gratora-donation-platform'),     'rows' => $this->wordpress()],
            ['title' => __('Server', 'gratora-donation-platform'),        'rows' => $this->server()],
            ['title' => __('Database', 'gratora-donation-platform'),      'rows' => $this->database()],
            ['title' => __('Active plugins', 'gratora-donation-platform'), 'rows' => $this->plugins()],
        ];
    }

    /** @return list<array{label:string, value:string}> */
    private function gratora(): array
    {
        // Report key presence only; this output is shared with support.
        $keyHeld = SystemSetting::exists('encryption_key_v1');
        $keyLost = SystemSetting::read('encryption_key_lost_at');

        $rows = [
            self::row(__('Version', 'gratora-donation-platform'), defined('GRATORA_VERSION') ? GRATORA_VERSION : 'unknown'),
            self::row(__('Encryption key', 'gratora-donation-platform'), self::yesNo($keyHeld)),
        ];

        if (is_string($keyLost) && $keyLost !== '') {
            $rows[] = self::row(__('Encryption key lost at', 'gratora-donation-platform'), $keyLost);
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
                    __('not loaded: core %1$s does not satisfy %2$s', 'gratora-donation-platform'),
                    (string) ($pair[0] ?? '?'),
                    (string) ($pair[1] ?? '?')
                )
            );
        }

        foreach (self::dormantAddOns() as $row) {
            $rows[] = $row;
        }

        return $rows ?: [self::row(__('Add-ons', 'gratora-donation-platform'), __('None', 'gratora-donation-platform'))];
    }

    /**
     * Gratora add-ons on disk that are switched off.
     *
     * A deactivated add-on registers no module, so the registry cannot see it,
     * yet the scheduled jobs it left behind are on this same screen and the
     * data it wrote is still in the database. Whether one is switched off is
     * usually the answer to the ticket this report is pasted into.
     *
     * Recognised by the gratora- text domain every add-on carries, whether or
     * not it declares Requires Plugins.
     *
     * @return list<array{label:string, value:string}>
     *
     * @since 1.0.0
     */
    private static function dormantAddOns(): array
    {
        // The folder, not plugin_basename(): that returns an absolute path for
        // a checkout outside the plugin directory, and would match nothing.
        $here   = basename(dirname(GRATORA_FILE));
        $active = self::activePluginFiles();

        $rows = [];

        foreach (self::installedPlugins() as $file => $data) {
            $file = (string) $file;

            if (dirname($file) === $here || in_array($file, $active, true)) {
                continue;
            }

            if (! str_starts_with((string) ($data['TextDomain'] ?? ''), 'gratora-')) {
                continue;
            }

            $version = (string) ($data['Version'] ?? '');

            $rows[] = self::row(
                (string) ($data['Name'] ?? $file),
                sprintf(
                    /* translators: %s: the add-on's version, or "unknown" when its header carries none */
                    __('%s (installed, switched off)', 'gratora-donation-platform'),
                    $version !== '' ? $version : __('unknown', 'gratora-donation-platform')
                )
            );
        }

        return $rows;
    }

    /**
     * @return array<string, array<string, mixed>>
     *
     * @since 1.0.0
     */
    private static function installedPlugins(): array
    {
        return get_plugins();
    }

    /**
     * @return list<string>
     *
     * @since 1.0.0
     */
    private static function activePluginFiles(): array
    {
        $active = (array) get_option('active_plugins', []);

        if (is_multisite()) {
            $active = array_merge($active, array_keys((array) get_site_option('active_sitewide_plugins', [])));
        }

        return array_values(array_map('strval', $active));
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
                    ? __('ready', 'gratora-donation-platform')
                    : __('not configured', 'gratora-donation-platform')
            );
        }

        return $rows ?: [self::row(__('Gateways', 'gratora-donation-platform'), __('None registered', 'gratora-donation-platform'))];
    }

    /** @return list<array{label:string, value:string}> */
    private function wordpress(): array
    {
        $theme  = wp_get_theme();
        $parent = $theme->parent();

        return [
            self::row(__('Version', 'gratora-donation-platform'), get_bloginfo('version')),
            self::row(__('Site URL', 'gratora-donation-platform'), site_url()),
            self::row(__('Home URL', 'gratora-donation-platform'), home_url()),
            self::row(__('REST root', 'gratora-donation-platform'), esc_url_raw(rest_url('gratora/v1/'))),
            self::row(__('Multisite', 'gratora-donation-platform'), self::yesNo(is_multisite())),
            self::row(__('Locale', 'gratora-donation-platform'), get_locale()),
            self::row(__('Timezone', 'gratora-donation-platform'), wp_timezone_string()),
            self::row(__('Permalinks', 'gratora-donation-platform'), (string) get_option('permalink_structure') ?: __('plain', 'gratora-donation-platform')),
            self::row(__('Theme', 'gratora-donation-platform'), sprintf(
                '%s %s%s',
                (string) $theme->get('Name'),
                (string) $theme->get('Version'),
                $parent ? ' (child of ' . (string) $parent->get('Name') . ')' : ''
            )),
            self::row(__('Block theme', 'gratora-donation-platform'), self::yesNo(wp_is_block_theme())),
            self::row(__('Memory limit', 'gratora-donation-platform'), self::constantValue('WP_MEMORY_LIMIT')),
            self::row(__('Debug mode', 'gratora-donation-platform'), self::yesNo(defined('WP_DEBUG') && WP_DEBUG)),
            // Action Scheduler depends on WP-cron.
            self::row(__('WP-Cron disabled', 'gratora-donation-platform'), self::yesNo(defined('DISABLE_WP_CRON') && DISABLE_WP_CRON)),
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
            self::row(__('PHP version', 'gratora-donation-platform'), PHP_VERSION),
            self::row(__('PHP interface', 'gratora-donation-platform'), PHP_SAPI),
            self::row(__('Web server', 'gratora-donation-platform'), $software !== '' ? $software : __('unknown', 'gratora-donation-platform')),
            self::row(__('HTTPS', 'gratora-donation-platform'), self::yesNo(is_ssl())),
            // The shape, never the address: this screen is written to be pasted
            // into a ticket, and a visitor's IP is theirs. It still answers the
            // only question an admin has here, which is whether the proxy
            // configuration is doing anything: declare ranges and see this flip
            // to "forwarded header", or it is not matching your edge.
            self::row(
                __('Trusted proxies', 'gratora-donation-platform'),
                ($count = count(ClientIp::trustedProxies())) > 0
                    /* translators: %d: number of declared CIDR ranges */
                    ? sprintf(_n('%d range', '%d ranges', $count, 'gratora-donation-platform'), $count)
                    : __('none declared', 'gratora-donation-platform')
            ),
            self::row(
                __('Visitor address from', 'gratora-donation-platform'),
                ClientIp::resolve() !== ClientIp::remote()
                    ? __('forwarded header', 'gratora-donation-platform')
                    : __('REMOTE_ADDR', 'gratora-donation-platform')
            ),
            self::row(
                __('Undeclared proxy in front', 'gratora-donation-platform'),
                self::yesNo(ClientIp::looksProxied())
            ),
            self::row(__('Memory limit', 'gratora-donation-platform'), (string) ini_get('memory_limit')),
            self::row(__('Max execution time', 'gratora-donation-platform'), (string) ini_get('max_execution_time')),
            self::row(__('Upload max filesize', 'gratora-donation-platform'), (string) ini_get('upload_max_filesize')),
            self::row(__('Post max size', 'gratora-donation-platform'), (string) ini_get('post_max_size')),
            self::row(__('Max input vars', 'gratora-donation-platform'), (string) ini_get('max_input_vars')),
            self::row(
                __('Missing PHP extensions', 'gratora-donation-platform'),
                $missing === [] ? __('None', 'gratora-donation-platform') : implode(', ', $missing)
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
        //
        // Read off the migrations core registers rather than a list kept here,
        // so a table added to the schema is checked without this file being
        // touched. The schema notice only fires while the version stamp is
        // behind, so a table lost after a clean install is reported nowhere
        // else.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $version = (string) $wpdb->get_var('SELECT VERSION()');

        $rows = [
            self::row(__('Server', 'gratora-donation-platform'), $version !== '' ? $version : __('unknown', 'gratora-donation-platform')),
            self::row(__('Charset', 'gratora-donation-platform'), (string) $wpdb->charset),
            self::row(__('Collation', 'gratora-donation-platform'), (string) $wpdb->collate),
            self::row(__('Table prefix', 'gratora-donation-platform'), (string) $wpdb->prefix),
        ];

        foreach ((new DataEraser())->coreTables() as $base) {
            $table  = $wpdb->prefix . $base;
            $exists = (string) $wpdb->get_var(
                $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))
            ) === $table;

            $rows[] = self::row(
                $table,
                $exists
                    ? sprintf(
                        /* translators: %s: a row count */
                        __('%s rows', 'gratora-donation-platform'),
                        number_format_i18n((int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $table)))
                    )
                    : __('MISSING', 'gratora-donation-platform')
            );
        }
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

        return $rows;
    }

    /** @return list<array{label:string, value:string}> */
    private function plugins(): array
    {
        $all    = self::installedPlugins();
        $active = self::activePluginFiles();

        $rows = [];
        foreach ($active as $file) {
            $data = $all[$file] ?? null;
            if ($data === null) {
                // Active but not on disk, which is itself worth reporting.
                $rows[] = self::row((string) $file, __('active, but the file is missing', 'gratora-donation-platform'));
                continue;
            }
            $rows[] = self::row((string) $data['Name'], (string) $data['Version']);
        }

        foreach (get_mu_plugins() as $data) {
            $rows[] = self::row(
                (string) $data['Name'],
                sprintf(
                    /* translators: %s: plugin version */
                    __('%s (must-use)', 'gratora-donation-platform'),
                    (string) $data['Version']
                )
            );
        }

        return $rows ?: [self::row(__('Active', 'gratora-donation-platform'), __('None', 'gratora-donation-platform'))];
    }

    /** @return array{label:string, value:string} */
    private static function row(string $label, string $value): array
    {
        return ['label' => $label, 'value' => $value];
    }

    private static function yesNo(bool $value): string
    {
        return $value
            ? __('Yes', 'gratora-donation-platform')
            : __('No', 'gratora-donation-platform');
    }

    private static function constantValue(string $name): string
    {
        return defined($name) ? (string) constant($name) : __('not set', 'gratora-donation-platform');
    }
}
