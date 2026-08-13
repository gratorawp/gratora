<?php

declare(strict_types=1);

namespace Dono\Rest\Admin;

use Dono\Campaigns\Campaign;
use Dono\Analytics\ErrorLog;
use Dono\Async\AsyncDispatcher;
use Dono\Analytics\Event;
use Dono\Currency\FxBackfill;
use Dono\Settings\SecretRedactor;
use Dono\Foundation\Maintenance\TestDataPurger;
use Dono\Foundation\Transfer\CsvImporter;
use Dono\Foundation\Transfer\DataExporter;
use Dono\Foundation\Transfer\DataImporter;
use Dono\Foundation\Upgrade\UpgradeRunner;
use Dono\Donations\AggregateSyncer;
use Dono\Donors\Donor;
use Dono\Donors\DonorRetention;
use Dono\Forms\Form;
use Dono\Foundation\Auth\Capabilities;
use Dono\Funds\Fund;
use WP_REST_Response;
use WP_REST_Server;
use Dono\Vendor\Queryable\DB;
use Dono\Vendor\Queryable\ModelQueryBuilder;

/**
 * Admin endpoints for system info, settings export, settings import, and
 * recomputing denormalized aggregates (admin UI wrapper over the
 * `wp dono recompute-aggregates` CLI).
 *
 * @since 1.0.0
 */
final class ToolsController
{
    private const NAMESPACE = 'dono/v1';

    /** @since 1.0.0 */
    public function __construct(
        private AggregateSyncer $aggregates,
        private \Dono\Mail\Mailer $mailer,
        private FxBackfill $fxBackfill,
        private UpgradeRunner $upgrades,
        private DataExporter $exporter,
        private DataImporter $importer,
        private CsvImporter $csv,
        private TestDataPurger $testData,
    ) {
    }

    /** @since 1.0.0 */
    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/admin/tools/info', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'info'],
            'permission_callback' => [$this, 'canAccess'],
        ]);

        // Export leaks gateway secrets and import restores the role-capability
        // mapping + secrets, so both need full admin, not the delegatable
        // dono_manage_settings (which a scoped role could otherwise use to
        // read the webhook secret or grant itself capabilities via import).
        register_rest_route(self::NAMESPACE, '/admin/tools/export', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'export'],
            'permission_callback' => [$this, 'canManage'],
        ]);

        // Everything the org owns, in one file that restores on another site.
        // Same permission as settings export: it carries donor PII.
        register_rest_route(self::NAMESPACE, '/admin/tools/export-all', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'exportAll'],
            'permission_callback' => [$this, 'canManage'],
        ]);

        // Two steps on purpose: nothing is written until the admin has seen
        // what their mapping would do.
        register_rest_route(self::NAMESPACE, '/admin/tools/csv-inspect', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'csvInspect'],
            'permission_callback' => [$this, 'canManage'],
        ]);

        register_rest_route(self::NAMESPACE, '/admin/tools/csv-import', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'csvImport'],
            'permission_callback' => [$this, 'canManage'],
        ]);

        register_rest_route(self::NAMESPACE, '/admin/tools/import', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'import'],
            'permission_callback' => [$this, 'canManage'],
        ]);

        register_rest_route(self::NAMESPACE, '/admin/tools/run-upgrades', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'runUpgrades'],
            'permission_callback' => [$this, 'canManage'],
        ]);

        register_rest_route(self::NAMESPACE, '/admin/tools/recalculate', [
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

        register_rest_route(self::NAMESPACE, '/admin/tools/purge-test-data', [
            'methods'             => WP_REST_Server::CREATABLE,
            // manage_options, not the settings capability: this deletes rows,
            // and a settings manager is trusted with configuration, not with
            // the ledger.
            'permission_callback' => [$this, 'canManage'],
            'callback'            => [$this, 'purgeTestData'],
            'args'                => [
                'confirmation' => ['type' => 'string', 'default' => ''],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/admin/tools/log', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'log'],
                'permission_callback' => [$this, 'canAccess'],
                'args'                => [
                    'page'     => ['type' => 'integer', 'default' => 1, 'minimum' => 1],
                    'per_page' => ['type' => 'integer', 'default' => 25, 'minimum' => 1, 'maximum' => 100],
                    'source'   => ['type' => 'string', 'default' => ''],
                    'status'   => ['type' => 'string', 'enum' => ['', 'failed'], 'default' => ''],
                    'orderby'  => ['type' => 'string', 'enum' => self::LOG_ORDER_COLUMNS, 'default' => 'occurred_at'],
                    'order'    => ['type' => 'string', 'enum' => ['asc', 'desc'], 'default' => 'desc'],
                ],
            ],
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [$this, 'clearLog'],
                'permission_callback' => [$this, 'canManage'],
                'args'                => [
                    'source' => ['type' => 'string', 'default' => ''],
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

    /** Inbound gateway deliveries, written as `webhook.<gateway id>`. */
    private const WEBHOOK_PREFIX = 'webhook.';

    /** Columns the list may be ordered by. Nothing outside this reaches the query. */
    private const LOG_ORDER_COLUMNS = ['occurred_at', 'type'];

    /**
     * A delivery that was refused at the signature, and one that verified and
     * then threw, are both failures. A verified delivery Dono has no handler
     * for is not, and it is the common case, so it must not be swept in here.
     *
     * Compared as text rather than as JSON: MariaDB has no JSON type and
     * rejects CAST(x AS JSON) as a syntax error. JSON_UNQUOTE gives 'true' for
     * a JSON boolean and JSON_TYPE gives 'NULL' for a JSON null on both
     * engines. The column is LONGTEXT, so JSON_VALID guards the one case MySQL
     * raises an error on and MariaDB answers NULL to.
     */
    private const WEBHOOK_FAILED_SQL =
        "(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(payload), payload, NULL), '\$.verified')), 'false')"
        . " NOT IN ('true', '1')"
        . " OR JSON_TYPE(JSON_EXTRACT(IF(JSON_VALID(payload), payload, NULL), '\$.error')) NOT IN ('NULL'))";

    /**
     * Paged log, newest first unless asked otherwise: what Dono could not
     * finish and what the gateways sent, optionally narrowed to one source or
     * to the failures.
     *
     * @since 1.0.0
     */
    public function log(\WP_REST_Request $request): WP_REST_Response
    {
        $page    = max(1, (int) $request['page']);
        $perPage = max(1, min(100, (int) $request['per_page']));
        $source  = self::logSource((string) $request['source']);
        $failed  = (string) $request['status'] === 'failed';

        $orderBy = in_array((string) $request['orderby'], self::LOG_ORDER_COLUMNS, true)
            ? (string) $request['orderby']
            : 'occurred_at';
        $order = strtolower((string) $request['order']) === 'asc' ? 'ASC' : 'DESC';

        $total = self::logQuery($source, $failed)->count();

        $rows = self::logQuery($source, $failed)
            ->orderBy($orderBy, $order)
            // Entries recorded in the same second, and whole families sharing a
            // type, would otherwise page in an order the engine is free to
            // change between requests.
            ->orderBy('id', $order)
            ->limit($perPage)
            ->offset(($page - 1) * $perPage)
            ->getAll();

        return new WP_REST_Response([
            'items'          => array_map([self::class, 'logRow'], $rows),
            'total'          => $total,
            'page'           => $page,
            'per_page'       => $perPage,
            'sources'        => self::logSources(),
            'retention_days' => self::retentionDays(),
        ], 200);
    }

    /**
     * Clears the whole log, or one source of it when the screen is showing
     * one, so failures and deliveries can be cleared apart.
     *
     * @since 1.0.0
     */
    public function clearLog(\WP_REST_Request $request): WP_REST_Response
    {
        // Diagnostics only. The rest of this table is the record of what
        // happened to people's money: the donor timelines read from it, so do
        // the dashboard figures, and a button labelled Clear log must not be
        // the thing that erases a donation's history.
        $query = Event::query()->where(static function ($q): void {
            $q->whereLike('type', ErrorLog::PREFIX . '%')
                ->orWhereLike('type', self::WEBHOOK_PREFIX . '%');
        });

        $source = self::logSource((string) $request['source']);
        if ($source !== '' && self::isDiagnostic($source)) {
            $query = Event::query()->whereLike('type', $source . '%');
        }

        $deleted = $query->delete();

        return new WP_REST_Response(['ok' => true, 'deleted' => (int) $deleted->affectedRows], 200);
    }

    /** @since 1.0.0 */
    private static function isDiagnostic(string $source): bool
    {
        return str_starts_with($source, ErrorLog::PREFIX)
            || str_starts_with($source, self::WEBHOOK_PREFIX);
    }

    /**
     * dono_events carries every domain's history, most of it holding donor
     * detail this screen has no business serving. Anything outside the two
     * families it reads is dropped, so a hand-written source can neither widen
     * the list nor widen a delete.
     *
     * @since 1.0.0
     */
    private static function logSource(string $raw): string
    {
        $source = preg_replace('/[^a-z0-9_.\-]/', '', strtolower(trim($raw))) ?: '';

        return self::isDiagnostic($source) ? $source : '';
    }

    /** @since 1.0.0 */
    private static function logQuery(string $source, bool $failedOnly): ModelQueryBuilder
    {
        $query = Event::query();

        if ($source !== '') {
            $query->whereLike('type', $source . '%');
        } else {
            $query->where(static function ($q): void {
                $q->whereLike('type', ErrorLog::PREFIX . '%')
                    ->orWhereLike('type', self::WEBHOOK_PREFIX . '%');
            });
        }

        // Every recorded error is a failure; a delivery has to be read for it.
        if ($failedOnly) {
            $query->where(static function ($q): void {
                $q->whereLike('type', ErrorLog::PREFIX . '%')
                    ->orWhere(static function ($q): void {
                        // Raw first: it contributes no AND connector, so
                        // anything before it runs straight into the fragment.
                        $q->whereRaw(self::WEBHOOK_FAILED_SQL)
                            ->whereLike('type', self::WEBHOOK_PREFIX . '%');
                    });
            });
        }

        return $query;
    }

    /**
     * @return array<string,mixed>
     *
     * @since 1.0.0
     */
    private static function logRow(Event $e): array
    {
        if (str_starts_with((string) $e->type, self::WEBHOOK_PREFIX)) {
            return self::deliveryRow($e);
        }

        return self::errorRow($e);
    }

    /**
     * @return array<string,mixed>
     *
     * @since 1.0.0
     */
    private static function errorRow(Event $e): array
    {
        $payload = is_array($e->payload) ? $e->payload : [];
        $message = (string) ($payload['message'] ?? '');
        unset($payload['message']);

        // ErrorLog promotes these to columns, so they are absent from the
        // payload and have to be folded back in or the one id that identifies
        // the failing record is unreachable from this screen.
        foreach (['donation_id', 'donor_id', 'campaign_id', 'form_id', 'recurring_plan_id'] as $col) {
            if (! empty($e->{$col})) {
                $payload = [$col => (int) $e->{$col}] + $payload;
            }
        }

        return [
            'id'          => (int) $e->id,
            'kind'        => 'error',
            'source'      => substr((string) $e->type, strlen(ErrorLog::PREFIX)),
            'message'     => $message !== '' ? $message : __('No detail recorded.', 'dono-fundraising-platform'),
            'context'     => $payload,
            'occurred_at' => (string) $e->occurred_at,
        ];
    }

    /**
     * A delivery reads out of its payload alone: which gateway, which event,
     * and the three facts the screen turns into an outcome.
     *
     * @return array<string,mixed>
     *
     * @since 1.0.0
     */
    private static function deliveryRow(Event $e): array
    {
        $payload = is_array($e->payload) ? $e->payload : [];
        $event   = trim((string) ($payload['event_type'] ?? ''));
        $error   = trim((string) ($payload['error'] ?? ''));

        return [
            'id'          => (int) $e->id,
            'kind'        => 'webhook',
            'source'      => substr((string) $e->type, strlen(self::WEBHOOK_PREFIX)),
            'message'     => $event !== '' ? $event : __('Unnamed event.', 'dono-fundraising-platform'),
            'verified'    => (bool) ($payload['verified'] ?? false),
            'processed'   => (bool) ($payload['processed'] ?? false),
            'error'       => $error !== '' ? $error : null,
            'context'     => [],
            'occurred_at' => (string) $e->occurred_at,
        ];
    }

    /**
     * Types present in the log, so the filter offers what is actually there
     * rather than every source Dono can emit and every gateway it supports.
     * Empty also tells the screen that nothing has been recorded at all, which
     * is not the same answer as nothing matching the current filters.
     *
     * @return list<string>
     *
     * @since 1.0.0
     */
    private static function logSources(): array
    {
        $rows = Event::query()
            ->select('type')
            ->distinct()
            ->where(static function ($q): void {
                $q->whereLike('type', ErrorLog::PREFIX . '%')
                    ->orWhereLike('type', self::WEBHOOK_PREFIX . '%');
            })
            ->orderBy('type', 'ASC')
            ->getAll();

        $types = array_map(static fn ($e): string => (string) $e->type, $rows);

        return array_values(array_unique(array_filter($types)));
    }

    /**
     * How far back the list can reach: the pruner drops older entries, so an
     * absent one is only proof of nothing happening inside this window. Read
     * through the option and filter the pruner runs on, and 0 where a site
     * disabled it.
     *
     * @since 1.0.0
     */
    private static function retentionDays(): int
    {
        $privacy = get_option('dono_privacy', []);
        $stored  = is_array($privacy) ? (int) ($privacy['event_retention_days'] ?? 730) : 730;
        $days    = (int) apply_filters('dono.event.retention_days', $stored);

        return $days > 0 ? $days : 0;
    }

    /**
     * Send a test email through the configured sender + transport so the
     * admin can verify deliverability without waiting for a real donation.
     * Defaults to the current user's WP email when no `to` is provided.
     *
     * @since 1.0.0
     */
    public function sendTestEmail(\WP_REST_Request $request): WP_REST_Response|\WP_Error
    {
        $to = trim((string) ($request['to'] ?? ''));
        if ($to === '') {
            $user = wp_get_current_user();
            $to = (string) ($user->user_email ?? '');
        }
        if (! is_email($to)) {
            return new \WP_Error('dono_invalid_email', __('Provide a valid recipient email.', 'dono-fundraising-platform'), ['status' => 422]);
        }

        $subject = __('Dono test email', 'dono-fundraising-platform');
        $body    = '<p>' . esc_html__('This is a test email from Dono.', 'dono-fundraising-platform') . '</p>'
                 . '<p>' . esc_html__('If it landed in your inbox, your sender + transport settings are working.', 'dono-fundraising-platform') . '</p>'
                 . '<p style="color:#6b7280;font-size:12px">'
                 . esc_html(sprintf(
                     /* translators: %s: site URL */
                     __('Sent at %1$s from %2$s', 'dono-fundraising-platform'),
                     gmdate('c'),
                     site_url()
                 ))
                 . '</p>';

        // wp_mail swallows the PHPMailer exception and returns a bare false, so
        // the reason only ever reaches this action. Without capturing it the
        // admin is told the send failed and nothing about why, which is the one
        // thing they need.
        $reason = '';
        $capture = static function ($error) use (&$reason): void {
            if ($error instanceof \WP_Error) {
                $reason = (string) $error->get_error_message();
            }
        };
        add_action('wp_mail_failed', $capture);

        try {
            $ok = $this->mailer->sendRaw($to, $subject, $body, ['html' => true]);
        } finally {
            remove_action('wp_mail_failed', $capture);
        }

        if (! $ok) {
            return new \WP_Error(
                'dono_test_send_failed',
                $reason !== ''
                    ? sprintf(
                        /* translators: %s: the mail server's own error message. */
                        __('The mail server refused it: %s', 'dono-fundraising-platform'),
                        $reason
                    )
                    : __('wp_mail() returned false and reported no reason. The site most likely has no mail transport configured: install an SMTP plugin or check your host\'s mail logs.', 'dono-fundraising-platform'),
                ['status' => 500]
            );
        }

        return new WP_REST_Response(['ok' => true, 'to' => $to], 200);
    }

    /**
     * Recompute denormalized aggregates from source-of-truth donation rows.
     * Synchronous; the underlying syncs are idempotent and read-then-write
     * only on the derived counters.
     *
     * @since 1.0.0
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
        // aggregate passes have to run whatever the scope was. Without it, a
        // currency-only pass writes base amounts, rebuilds nothing, and reports
        // success while every total stays wrong.
        $rebuildAll = $scope === 'all' || $converted > 0;

        if ($rebuildAll || $scope === 'donors') {
            foreach (self::eachId('dono_donors') as $id) {
                $this->aggregates->syncDonor($id);
                $counts['donors']++;
            }
        }
        if ($rebuildAll || $scope === 'funds') {
            foreach (self::eachId('dono_funds') as $id) {
                $this->aggregates->syncFund($id);
                $counts['funds']++;
            }
        }
        if ($rebuildAll || $scope === 'campaigns') {
            foreach (self::eachId('dono_campaigns') as $id) {
                $this->aggregates->syncCampaign($id);
                $counts['campaigns']++;
            }
        }
        if ($rebuildAll || $scope === 'forms') {
            foreach (self::eachId('dono_forms') as $id) {
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

    /** How many ids to hold at once while walking a table. */
    private const RECALC_CHUNK = 500;

    /**
     * Every id in a table, a chunk at a time. Ids only, never hydrated models,
     * so the memory a rebuild needs does not grow with the org.
     *
     * @return \Generator<int>
     *
     * @since 1.0.0
     */
    private static function eachId(string $table): \Generator
    {
        $after = 0;

        while (true) {
            $rows = DB::table($table)
                ->select('id')
                ->where('id', $after, '>')
                ->orderBy('id')
                ->limit(self::RECALC_CHUNK)
                ->getAll();

            if (! $rows) {
                return;
            }

            foreach ($rows as $row) {
                // DB::table() yields plain rows, not hydrated models.
                $after = (int) (is_array($row) ? ($row['id'] ?? 0) : $row->id);
                if ($after <= 0) {
                    return;
                }
                yield $after;
            }
        }
    }

    private const SETTINGS_OPTIONS = [
        'dono_org_profile',
        'dono_currency_locale',
        'dono_org_brand',
        'dono_gateway_config',
        'dono_privacy',
        'dono_roles',
        'dono_consents',
        'dono_receipt_settings',
        'dono_email_settings',
        'dono_reference_settings',
    ];

    /**
     * Served straight to the client rather than through WP_REST_Response: the
     * body is built a page at a time, and handing it back as one string would
     * put the whole site in memory to say it did not need to be.
     *
     * @since 1.0.0
     */
    public function exportAll(): void
    {
        $out = fopen('php://temp/maxmemory:8388608', 'r+');
        $this->exporter->writeJson($out);
        rewind($out);

        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="dono-export-' . gmdate('Y-m-d') . '.json"');
        fpassthru($out);
        fclose($out);
        exit;
    }

    /** @since 1.0.0 */
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
            if ($value === null) {
                continue;
            }

            // An export is a file people attach to support tickets and commit
            // to repositories. dono_gateway_config holds the Stripe webhook
            // signing secret, which is the only authentication on the webhook
            // route, so it leaves masked or not at all.
            $data['settings'][$opt] = is_array($value)
                ? SecretRedactor::redact($value)
                : $value;
        }

        return new WP_REST_Response($data, 200);
    }

    /** @since 1.0.0 */
    public function csvInspect(\WP_REST_Request $request): WP_REST_Response|\WP_Error
    {
        $csv = (string) ($request->get_json_params()['csv'] ?? '');
        if (trim($csv) === '') {
            return new \WP_Error('dono_invalid_csv', __('That file is empty.', 'dono-fundraising-platform'), ['status' => 422]);
        }

        return new WP_REST_Response($this->csv->inspect($csv) + ['fields' => CsvImporter::FIELDS], 200);
    }

    /** @since 1.0.0 */
    public function csvImport(\WP_REST_Request $request): WP_REST_Response|\WP_Error
    {
        $body    = (array) $request->get_json_params();
        $csv     = (string) ($body['csv'] ?? '');
        $mapping = is_array($body['mapping'] ?? null) ? array_map('strval', $body['mapping']) : [];
        $dryRun  = (bool) ($body['dry_run'] ?? true);

        if (trim($csv) === '') {
            return new \WP_Error('dono_invalid_csv', __('That file is empty.', 'dono-fundraising-platform'), ['status' => 422]);
        }

        $result = $this->csv->import($csv, $mapping, $dryRun);

        // A real run brings in years of history, so the retention sweep waits
        // rather than acting on it that night. Keyed on what the importer
        // actually returns: donors arrive without a donation between them, and
        // they are the rows the sweep reads.
        $landed = ((int) ($result['donations_imported'] ?? 0))
            + ((int) ($result['donors_created'] ?? 0));

        if (! $dryRun && $landed > 0) {
            DonorRetention::deferBy();
        }

        return new WP_REST_Response($result, 200);
    }

    /** @since 1.0.0 */
    public function import(\WP_REST_Request $request): WP_REST_Response|\WP_Error
    {
        $body = (array) $request->get_json_params();

        // A file carrying tables is a full export. Data first, so a settings
        // import cannot be mistaken for a restore that quietly dropped
        // everything except the settings.
        $records = null;
        if (is_array($body['tables'] ?? null)) {
            $records = $this->importer->import($body);
            DonorRetention::deferBy();
        }

        $settings = is_array($body['settings'] ?? null) ? $body['settings'] : null;
        if ($settings === null) {
            if ($records !== null) {
                return new WP_REST_Response(['imported' => true, 'records' => $records, 'settings_applied' => 0], 200);
            }

            return new \WP_Error('dono_invalid_import', __('No settings payload found.', 'dono-fundraising-platform'), ['status' => 422]);
        }

        $erasureWasOn = self::erasureIsOn();

        $applied = 0;
        foreach (self::SETTINGS_OPTIONS as $opt) {
            if (! array_key_exists($opt, $settings)) {
                continue;
            }

            $incoming = $settings[$opt];

            // A masked value in the file means "whatever is already stored",
            // so importing an export cannot wipe the secrets it could not
            // carry.
            if (is_array($incoming)) {
                $stored   = get_option($opt, []);
                $incoming = SecretRedactor::restore($incoming, is_array($stored) ? $stored : []);
            }

            update_option($opt, $incoming);
            $applied++;
        }

        // A file can carry automatic erasure switched on, and it lands by
        // option write rather than through the settings screen, so the screen's
        // own re-arm never sees it. Without this, restoring a backup onto a
        // site whose activation stamp is months past sweeps that same night.
        if (! $erasureWasOn && self::erasureIsOn()) {
            DonorRetention::deferBy();
        }

        if (isset($settings['dono_roles']['mapping']) && is_array($settings['dono_roles']['mapping'])) {
            Capabilities::applyMapping($settings['dono_roles']['mapping']);
        }

        return new WP_REST_Response([
            'ok'       => true,
            'applied'  => $applied,
            'imported' => $records !== null,
            'records'  => $records,
        ], 200);
    }

    /**
     * Read the way the sweep itself reads it, straight off the option, so the
     * two cannot disagree about whether erasure is armed.
     *
     * @since 1.0.0
     */
    private static function erasureIsOn(): bool
    {
        $privacy = get_option('dono_privacy', []);

        return is_array($privacy) && ! empty($privacy['erase_inactive_donors']);
    }

    /** @since 1.0.0 */
    public function purgeTestData(\WP_REST_Request $request): WP_REST_Response|\WP_Error
    {
        // Typed, not clicked: the button is one keystroke away from a ledger
        // that cannot be restored, and there is no undo behind it.
        if (strtoupper(trim((string) $request->get_param('confirmation'))) !== 'DELETE') {
            return new \WP_Error(
                'dono_confirmation_required',
                __('Type DELETE to confirm.', 'dono-fundraising-platform'),
                ['status' => 400]
            );
        }

        return new WP_REST_Response($this->testData->purge(), 200);
    }

    /** @since 1.0.0 */
    public function canAccess(): bool
    {
        return Capabilities::userCan('dono_manage_settings');
    }

    /** @since 1.0.0 */
    public function canManage(): bool
    {
        return current_user_can('manage_options');
    }

    /**
     * Scope slug => label.
     *
     * Add-ons denormalize their own counters from the same donation rows and
     * drift the same way, so they can add a scope rather than ship a second
     * Recalculate button. They pass a label with it: the screen lists whatever
     * this returns, and a scope nobody can name is a scope nobody can pick.
     *
     * @return array<string,string>
     *
     * @since 1.0.0
     */
    public static function scopes(): array
    {
        $core = [
            'all'       => __('Everything', 'dono-fundraising-platform'),
            'currency'  => __('Currency conversions', 'dono-fundraising-platform'),
            'donors'    => __('Donors', 'dono-fundraising-platform'),
            'funds'     => __('Funds', 'dono-fundraising-platform'),
            'campaigns' => __('Campaigns', 'dono-fundraising-platform'),
            'forms'     => __('Forms', 'dono-fundraising-platform'),
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

    /**
     * Drain the outstanding data migrations here and now.
     *
     * The queue is the normal path; this is the way back when cron is dead,
     * which is not a rare state on shared hosting and is not something a site
     * owner can fix from the plugin. Bounded so the request still returns:
     * whatever is left stays pending and the button can be pressed again.
     *
     * @since 1.0.0
     */
    public function runUpgrades(): WP_REST_Response
    {
        $steps = 0;
        while ($steps < 25 && $this->upgrades->step()) {
            $steps++;
        }

        return new WP_REST_Response([
            'remaining' => array_map(
                static fn ($r): array => ['id' => $r->id(), 'description' => $r->description()],
                $this->upgrades->pending()
            ),
        ], 200);
    }

    /** @since 1.0.0 */
    public function info(): WP_REST_Response
    {
        // Action Scheduler, not WP-Cron: every Dono job is queued through
        // AsyncDispatcher into the 'dono' group, and nothing in the plugin
        // calls wp_schedule_event, so _get_cron_array() would report nothing
        // queued on a site with a backlog.
        $cronEvents = [];
        if (function_exists('as_get_scheduled_actions')) {
            $pending = \as_get_scheduled_actions([
                'group'    => AsyncDispatcher::GROUP,
                'status'   => \ActionScheduler_Store::STATUS_PENDING,
                'per_page' => 50,
                'orderby'  => 'date',
                'order'    => 'ASC',
            ], 'OBJECT');

            foreach ((array) $pending as $action) {
                if (! is_object($action) || ! method_exists($action, 'get_hook')) {
                    continue;
                }
                $date = method_exists($action, 'get_schedule') ? $action->get_schedule()?->get_date() : null;
                $cronEvents[] = [
                    'hook' => (string) $action->get_hook(),
                    'next' => $date instanceof \DateTimeInterface ? $date->format('c') : '',
                ];
            }
        }

        return new WP_REST_Response([
            'version'   => defined('DONO_VERSION') ? DONO_VERSION : 'unknown',
            'php'       => PHP_VERSION,
            'wp'        => get_bloginfo('version'),
            'rest_root' => esc_url_raw(rest_url('dono/v1/')),
            'site_url'  => site_url(),
            'cron'      => $cronEvents,
            // Real payments sitting outside every total because no rate exists
            // for their currency. Empty on a healthy site, which is why the
            // screen only says anything when it is not.
            'unconverted_donations' => FxBackfill::pending(),
            // A data migration that never runs leaves the site quietly
            // half-migrated, and Action Scheduler rides WP-cron, which plenty
            // of hosts disable. The screen has to be able to say so.
            'pending_upgrades'      => array_map(
                static fn ($r): array => [
                    'id'          => $r->id(),
                    'description' => $r->description(),
                    'failure'     => UpgradeRunner::failures()[$r->id()] ?? null,
                ],
                $this->upgrades->pending()
            ),
            'test_data'             => $this->testData->preview(),
            'recalc_scopes'         => array_map(
                static fn ($slug, $label): array => ['value' => $slug, 'label' => $label],
                array_keys(self::scopes()),
                array_values(self::scopes())
            ),
        ], 200);
    }

}
