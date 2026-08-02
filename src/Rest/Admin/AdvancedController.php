<?php

declare(strict_types=1);

namespace Dono\Rest\Admin;

use Dono\Campaigns\Campaign;
use Dono\Currency\FxBackfill;
use Dono\Donations\AggregateSyncer;
use Dono\Donors\Donor;
use Dono\Forms\Form;
use Dono\Foundation\Auth\Capabilities;
use Dono\Funds\Fund;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Admin endpoints for system info, settings export, settings import, and
 * recomputing denormalised aggregates (admin UI wrapper over the
 * `wp dono recompute-aggregates` CLI).
 *
 * @version 1.1.0
 */
final class AdvancedController
{
    private const NAMESPACE = 'dono/v1';

    public function __construct(
        private AggregateSyncer $aggregates,
        private \Dono\Mail\Mailer $mailer,
        private FxBackfill $fxBackfill,
    ) {
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/admin/advanced/info', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'info'],
            'permission_callback' => [$this, 'canAccess'],
        ]);

        // Export leaks gateway secrets and import restores the role-capability
        // mapping + secrets, so both need full admin, not the delegatable
        // dono_manage_settings (which a scoped role could otherwise use to
        // read the webhook secret or grant itself capabilities via import).
        register_rest_route(self::NAMESPACE, '/admin/advanced/export', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'export'],
            'permission_callback' => [$this, 'canManage'],
        ]);

        register_rest_route(self::NAMESPACE, '/admin/advanced/import', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'import'],
            'permission_callback' => [$this, 'canManage'],
        ]);

        register_rest_route(self::NAMESPACE, '/admin/advanced/recalculate', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'recalculate'],
            'permission_callback' => [$this, 'canAccess'],
            'args'                => [
                'scope' => [
                    'type'    => 'string',
                    'enum'    => array_keys(self::scopes()),
                    'default' => 'all',
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/admin/email/test-send', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'sendTestEmail'],
            'permission_callback' => [$this, 'canAccess'],
            'args'                => [
                'to' => [
                    'type'   => 'string',
                    'format' => 'email',
                ],
            ],
        ]);
    }

    /**
     * Send a test email through the configured sender + transport so the
     * admin can verify deliverability without waiting for a real donation.
     * Defaults to the current user's WP email when no `to` is provided.
     */
    public function sendTestEmail(\WP_REST_Request $request): WP_REST_Response|\WP_Error
    {
        $to = trim((string) ($request['to'] ?? ''));
        if ($to === '') {
            $user = wp_get_current_user();
            $to = (string) ($user->user_email ?? '');
        }
        if (! is_email($to)) {
            return new \WP_Error('dono_invalid_email', __('Provide a valid recipient email.', 'dono'), ['status' => 422]);
        }

        $subject = __('Dono test email', 'dono');
        $body    = '<p>' . esc_html__('This is a test email from Dono.', 'dono') . '</p>'
                 . '<p>' . esc_html__('If it landed in your inbox, your sender + transport settings are working.', 'dono') . '</p>'
                 . '<p style="color:#6b7280;font-size:12px">'
                 . esc_html(sprintf(
                     /* translators: %s: site URL */
                     __('Sent at %1$s from %2$s', 'dono'),
                     gmdate('c'),
                     site_url()
                 ))
                 . '</p>';

        $ok = $this->mailer->sendRaw($to, $subject, $body, ['html' => true]);
        if (! $ok) {
            return new \WP_Error(
                'dono_test_send_failed',
                __('wp_mail() returned false. Check your SMTP plugin / server mail logs.', 'dono'),
                ['status' => 500]
            );
        }

        return new WP_REST_Response(['ok' => true, 'to' => $to], 200);
    }

    /**
     * Recompute denormalised aggregates from source-of-truth donation rows.
     * Synchronous; the underlying syncs are idempotent and read-then-write
     * only on the derived counters.
     */
    public function recalculate(\WP_REST_Request $request): WP_REST_Response
    {
        $scope = (string) ($request['scope'] ?? 'all');

        $counts = ['donors' => 0, 'funds' => 0, 'campaigns' => 0, 'forms' => 0];

        // Before the aggregate passes, so a donation that finally has a rate is
        // counted by them rather than waiting for the next run. Recording a
        // donation never blocks on FX, so these rows are real payments sitting
        // outside every total until a rate exists for their currency.
        $converted = 0;
        if ($scope === 'all' || $scope === 'currency') {
            $fx        = $this->fxBackfill->run();
            $converted = $fx['converted'];
            $counts['converted_donations'] = $converted;
            if (($fx['plans'] ?? 0) > 0) {
                $counts['converted_plans'] = (int) $fx['plans'];
            }
            // A plan carries its own base amount, so converting one changes
            // recurring revenue even when no donation moved.
            $converted += (int) ($fx['plans'] ?? 0);
            if ($fx['unconvertible'] > 0) {
                $counts['still_unconvertible'] = $fx['unconvertible'];
            }
        }

        // Converting a donation changes every total it belongs to, so the
        // aggregate passes have to run whatever the scope was. Without this,
        // "Currency conversions" wrote base amounts, rebuilt nothing, and
        // reported success while every total stayed exactly as wrong as before.
        $rebuildAll = $scope === 'all' || $converted > 0;

        if ($rebuildAll || $scope === 'donors') {
            foreach (self::idsOf(Donor::class) as $id) {
                $this->aggregates->syncDonor($id);
                $counts['donors']++;
            }
        }
        if ($rebuildAll || $scope === 'funds') {
            foreach (self::idsOf(Fund::class) as $id) {
                $this->aggregates->syncFund($id);
                $counts['funds']++;
            }
        }
        if ($rebuildAll || $scope === 'campaigns') {
            foreach (self::idsOf(Campaign::class) as $id) {
                $this->aggregates->syncCampaign($id);
                $counts['campaigns']++;
            }
        }
        if ($rebuildAll || $scope === 'forms') {
            foreach (self::idsOf(Form::class) as $id) {
                $this->aggregates->syncForm($id);
                $counts['forms']++;
            }
        }

        // Add-ons recompute theirs from the same source rows. Fired after the
        // core passes so anything derived from a campaign total is rebuilt from
        // a campaign total that is already correct.
        $counts = (array) apply_filters('dono.recalculate.counts', $counts, $rebuildAll ? 'all' : $scope);

        return new WP_REST_Response([
            'ok'     => true,
            'scope'  => $scope,
            'counts' => $counts,
        ], 200);
    }

    /**
     * @param class-string $model
     * @return list<int>
     */
    private static function idsOf(string $model): array
    {
        $rows = $model::query()->getAll();
        return array_values(array_map(static fn ($r) => (int) $r->id, $rows));
    }

    private const SETTINGS_OPTIONS = [
        'dono_org_profile',
        'dono_currency_locale',
        'dono_org_brand',
        'dono_gateway_config',
        'dono_privacy',
        'dono_roles',
        'dono_advanced',
        'dono_consents',
        'dono_receipt_settings',
        'dono_email_settings',
        'dono_reference_settings',
        'dono_telemetry',
    ];

    public function export(): WP_REST_Response
    {
        $data = [
            'exported_at' => gmdate('c'),
            'site_url'    => site_url(),
            'version'     => defined('DONO_VERSION') ? DONO_VERSION : 'unknown',
            'settings'    => [],
        ];
        foreach (self::SETTINGS_OPTIONS as $opt) {
            $value = get_option($opt, null);
            if ($value !== null) $data['settings'][$opt] = $value;
        }
        return new WP_REST_Response($data, 200);
    }

    public function import(\WP_REST_Request $request): WP_REST_Response|\WP_Error
    {
        $body = (array) $request->get_json_params();
        $settings = is_array($body['settings'] ?? null) ? $body['settings'] : null;
        if ($settings === null) {
            return new \WP_Error('dono_invalid_import', __('No settings payload found.', 'dono'), ['status' => 422]);
        }

        $applied = 0;
        foreach (self::SETTINGS_OPTIONS as $opt) {
            if (array_key_exists($opt, $settings)) {
                update_option($opt, $settings[$opt]);
                $applied++;
            }
        }

        if (isset($settings['dono_roles']['mapping']) && is_array($settings['dono_roles']['mapping'])) {
            Capabilities::applyMapping($settings['dono_roles']['mapping']);
        }

        return new WP_REST_Response(['ok' => true, 'applied' => $applied], 200);
    }

    public function canAccess(): bool
    {
        return Capabilities::userCan('dono_manage_settings');
    }

    public function canManage(): bool
    {
        return current_user_can('manage_options');
    }

    /**
     * Scope slug => label.
     *
     * Add-ons denormalise their own counters from the same donation rows and
     * drift the same way, so they can add a scope rather than ship a second
     * Recalculate button. They pass a label with it: the screen lists whatever
     * this returns, and a scope nobody can name is a scope nobody can pick.
     * P2P registered one and it was unreachable, because the dropdown carried
     * its own hardcoded copy of the core six.
     *
     * @return array<string,string>
     */
    public static function scopes(): array
    {
        $core = [
            'all'       => __('Everything', 'dono'),
            'currency'  => __('Currency conversions', 'dono'),
            'donors'    => __('Donors', 'dono'),
            'funds'     => __('Funds', 'dono'),
            'campaigns' => __('Campaigns', 'dono'),
            'forms'     => __('Forms', 'dono'),
        ];

        $added = (array) apply_filters('dono.recalculate.scopes', []);
        foreach ($added as $slug => $label) {
            $slug = strtolower(trim((string) $slug));
            // A slug core already owns is not overridable: an add-on renaming
            // "Everything" would be relabelling a scope it does not implement.
            if ($slug === '' || isset($core[$slug]) || ! is_string($label) || $label === '') {
                continue;
            }
            $core[$slug] = $label;
        }

        return $core;
    }

    public function info(): WP_REST_Response
    {
        global $wpdb;

        $tables = [];
        foreach ((array) $wpdb->get_col("SHOW TABLES LIKE '{$wpdb->prefix}dono_%'") as $t) {
            $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$t}`");
            $tables[] = ['name' => (string) $t, 'rows' => $count];
        }

        $cronEvents = [];
        $crons = _get_cron_array() ?: [];
        foreach ($crons as $timestamp => $hooks) {
            foreach ($hooks as $hook => $_dicts) {
                if (str_starts_with((string) $hook, 'dono.')) {
                    $cronEvents[] = ['hook' => (string) $hook, 'next' => gmdate('c', (int) $timestamp)];
                }
            }
        }

        return new WP_REST_Response([
            'version'   => defined('DONO_VERSION') ? DONO_VERSION : 'unknown',
            'php'       => PHP_VERSION,
            'wp'        => get_bloginfo('version'),
            'rest_root' => esc_url_raw(rest_url('dono/v1/')),
            'site_url'  => site_url(),
            'tables'    => $tables,
            'cron'      => $cronEvents,
            // Real payments sitting outside every total because no rate exists
            // for their currency. Empty on a healthy site, which is why the
            // screen only says anything when it is not.
            'unconverted_donations' => FxBackfill::pending(),
            'recalc_scopes'         => array_map(
                static fn ($slug, $label): array => ['value' => $slug, 'label' => $label],
                array_keys(self::scopes()),
                array_values(self::scopes())
            ),
        ], 200);
    }

}
