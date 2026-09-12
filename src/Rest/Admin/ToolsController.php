<?php

declare(strict_types=1);

namespace Gratora\Rest\Admin;

use Gratora\Rest\Paging;
use Gratora\Analytics\ErrorLog;
use Gratora\Analytics\Event;
use Gratora\Async\AsyncDispatcher;
use Gratora\Currency\BaseCurrencyLocked;
use Gratora\Currency\FxBackfill;
use Gratora\Donations\AggregateSyncer;
use Gratora\Donors\DonorRetention;
use Gratora\Foundation\Auth\Capabilities;
use Gratora\Foundation\Maintenance\TestDataPurger;
use Gratora\Foundation\Transfer\CsvImporter;
use Gratora\Foundation\Transfer\DataExporter;
use Gratora\Foundation\Transfer\DataImporter;
use Gratora\Foundation\Upgrade\UpgradeRunner;
use Gratora\Settings\SecretRedactor;
use Gratora\Settings\SettingsService;
use Gratora\Vendor\Queryable\DB;
use Gratora\Vendor\Queryable\ModelQueryBuilder;
use WP_REST_Response;
use WP_REST_Server;
use Gratora\Analytics\DonationAudit;

/** @since 1.0.0 */
final class ToolsController
{
    private const NAMESPACE = 'gratora/v1';

    /** @since 1.0.0 */
    public function __construct(
        private AggregateSyncer $aggregates,
        private \Gratora\Mail\Mailer $mailer,
        private FxBackfill $fxBackfill,
        private UpgradeRunner $upgrades,
        private DataExporter $exporter,
        private DataImporter $importer,
        private CsvImporter $csv,
        private TestDataPurger $testData,
        private \Gratora\Admin\SystemReport $report,
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
        // gratora_manage_settings (which a scoped role could otherwise use to
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

    /**
     * The record of what an admin did to a donor. Readable here, and
     * deliberately outside isDiagnostic(), which is what Clear log deletes by.
     */
    private const AUDIT_PREFIX = 'donor.';

    /** Columns the list may be ordered by. Nothing outside this reaches the query. */
    private const LOG_ORDER_COLUMNS = ['occurred_at', 'type'];

    /**
     * A delivery that was refused at the signature, and one that verified and
     * then threw, are both failures. A verified delivery Gratora has no handler
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
     * Paged log, newest first unless asked otherwise: what Gratora could not
     * finish and what the gateways sent, optionally narrowed to one source or
     * to the failures.
     *
     * @since 1.0.0
     */
    public function log(\WP_REST_Request $request): WP_REST_Response
    {
        $page    = Paging::page($request['page'] ?? null);
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
        if ($source !== '') {
            if (! self::isDiagnostic($source)) {
                return new WP_REST_Response(['ok' => true, 'deleted' => 0], 200);
            }

            $query = Event::query()->whereLike('type', $source . '%');
        }

        $deleted = $query->delete();

        return new WP_REST_Response(['ok' => true, 'deleted' => (int) $deleted->affectedRows], 200);
    }

    /** @since 1.0.0 */
    private static function isReadable(string $source): bool
    {
        return self::isDiagnostic($source)
            || str_starts_with($source, self::AUDIT_PREFIX)
            || DonationAudit::is($source);
    }

    /** @since 1.0.0 */
    private static function isDiagnostic(string $source): bool
    {
        return str_starts_with($source, ErrorLog::PREFIX)
            || str_starts_with($source, self::WEBHOOK_PREFIX);
    }

    /**
     * gratora_events carries every domain's history, most of it holding donor
     * detail this screen has no business serving. Anything outside the two
     * families it reads is dropped, so a hand-written source can neither widen
     * the list nor widen a delete.
     *
     * @since 1.0.0
     */
    private static function logSource(string $raw): string
    {
        $source = preg_replace('/[^a-z0-9_.\-]/', '', strtolower(trim($raw))) ?: '';

        return self::isReadable($source) ? $source : '';
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
                    ->orWhereLike('type', self::WEBHOOK_PREFIX . '%')
                    ->orWhereLike('type', self::AUDIT_PREFIX . '%')
                    ->orWhereIn('type', DonationAudit::TYPES);
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

        if (str_starts_with((string) $e->type, self::AUDIT_PREFIX) || DonationAudit::is((string) $e->type)) {
            return self::auditRow($e);
        }

        return self::errorRow($e);
    }

    /**
     * The record of something done TO a donor, which is not a failure.
     *
     * These rows are readable here on purpose, and the error branch stamped
     * them kind 'error' and then cut ErrorLog's prefix off a type that never
     * carried it: an erasure appeared in the log as an error from a source
     * called "redacted", with no message at all.
     *
     * @return array<string,mixed>
     *
     * @since 1.0.0
     */
    private static function auditRow(Event $e): array
    {
        $payload = is_array($e->payload) ? $e->payload : [];
        $who     = trim((string) ($payload['actor_name'] ?? '')) ?: trim((string) ($payload['by'] ?? ''));

        foreach (['donation_id', 'donor_id', 'campaign_id', 'form_id', 'recurring_plan_id'] as $col) {
            if (! empty($e->{$col})) {
                $payload = [$col => (int) $e->{$col}] + $payload;
            }
        }

        $message = $who !== ''
            /* translators: %s: who performed the action, a staff name or "donor". */
            ? sprintf(__('Recorded by %s.', 'gratora-donation-platform'), $who)
            : '';

        // An add-on's own audit type: core cannot phrase what it means, and
        // the fallback below claims nothing was recorded on a row whose
        // payload is in this very response. Filtered at read time rather than
        // stored, so the sentence follows the reader's language and not
        // whatever was set when the row was written.
        $message = (string) apply_filters('gratora.audit.message', $message, (string) $e->type, $payload);

        return [
            'id'      => (int) $e->id,
            'kind'    => 'audit',
            'source'  => (string) $e->type,
            'message' => $message !== '' ? $message : __('No detail recorded.', 'gratora-donation-platform'),
            'context'     => $payload,
            'occurred_at' => (string) $e->occurred_at,
        ];
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
            'message'     => $message !== '' ? $message : __('No detail recorded.', 'gratora-donation-platform'),
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
            'message'     => $event !== '' ? $event : __('Unnamed event.', 'gratora-donation-platform'),
            'verified'    => (bool) ($payload['verified'] ?? false),
            'processed'   => (bool) ($payload['processed'] ?? false),
            'error'       => $error !== '' ? $error : null,
            'context'     => [],
            'occurred_at' => (string) $e->occurred_at,
        ];
    }

    /**
     * Types present in the log, so the filter offers what is actually there
     * rather than every source Gratora can emit and every gateway it supports.
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
                    ->orWhereLike('type', self::WEBHOOK_PREFIX . '%')
                    ->orWhereLike('type', self::AUDIT_PREFIX . '%')
                    ->orWhereIn('type', DonationAudit::TYPES);
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
        $privacy = get_option('gratora_privacy', []);
        $stored  = is_array($privacy) ? (int) ($privacy['event_retention_days'] ?? 730) : 730;
        $days    = (int) apply_filters('gratora.event.retention_days', $stored);

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
            return new \WP_Error('gratora_invalid_email', __('Provide a valid recipient email.', 'gratora-donation-platform'), ['status' => 422]);
        }

        $subject = __('Gratora test email', 'gratora-donation-platform');
        $body    = '<p>' . esc_html__('This is a test email from Gratora.', 'gratora-donation-platform') . '</p>'
                 . '<p>' . esc_html__('If it landed in your inbox, your sender + transport settings are working.', 'gratora-donation-platform') . '</p>'
                 . '<p style="color:#6b7280;font-size:12px">'
                 . esc_html(sprintf(
                     /* translators: %s: site URL */
                     __('Sent at %1$s from %2$s', 'gratora-donation-platform'),
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

        // Which transport actually carried it. "Sent" is not the useful answer:
        // a message handed to unauthenticated PHP mail is accepted here and
        // rejected later by any mailbox that checks SPF or DKIM, which is the
        // usual shape of "the test worked but no donor got a receipt".
        $transport = '';
        $host      = '';
        $inspect   = static function ($phpmailer) use (&$transport, &$host): void {
            $transport = (string) ($phpmailer->Mailer ?? '');
            $host      = (string) ($phpmailer->Host ?? '');
        };
        add_action('phpmailer_init', $inspect, PHP_INT_MAX);

        try {
            $ok = $this->mailer->sendRaw($to, $subject, $body, ['html' => true]);
        } finally {
            remove_action('wp_mail_failed', $capture);
            remove_action('phpmailer_init', $inspect, PHP_INT_MAX);
        }

        if (! $ok) {
            return new \WP_Error(
                'gratora_test_send_failed',
                $reason !== ''
                    ? sprintf(
                        /* translators: %s: the mail server's own error message. */
                        __('The mail server refused it: %s', 'gratora-donation-platform'),
                        $reason
                    )
                    : __('wp_mail() returned false and reported no reason. The site most likely has no mail transport configured: install an SMTP plugin or check your host\'s mail logs.', 'gratora-donation-platform'),
                ['status' => 500]
            );
        }

        return new WP_REST_Response([
            'ok'        => true,
            'to'        => $to,
            'transport' => $transport,
            'host'      => $host,
            // Three states, not two. A mail plugin can short-circuit wp_mail
            // before PHPMailer is built, leaving the transport unknown, and
            // reporting unknown as unauthenticated would be the same false
            // claim in the other direction.
            'authenticated' => $transport === '' ? null : $transport !== 'mail',
        ], 200);
    }

    /**
     * Recompute denormalized aggregates from source-of-truth donation rows.
     *
     * One request does as much as fits in a time budget and records where it
     * got to; the caller posts again until done is true. Walking every donor,
     * fund, campaign and form in one request could not finish on any site big
     * enough to need the tool: PHP hit max_execution_time part-way through the
     * donor pass, the later passes never ran, and pressing the button again
     * restarted from the first donor and timed out in the same place forever.
     *
     * @since 1.0.0
     */
    public function recalculate(\WP_REST_Request $request): WP_REST_Response
    {
        $scope = (string) ($request['scope'] ?? 'all');
        $state = $this->recalcState($scope);

        /**
         * Seconds one request spends rebuilding before handing the rest back.
         *
         * @param float $seconds
         *
         * @since 1.0.0
         */
        $until = microtime(true) + (float) apply_filters(
            'gratora.recalculate.budget_seconds',
            self::RECALC_BUDGET_SECONDS
        );

        // The clock is read after a step rather than before, so every request
        // makes progress however tight the budget is. A request that returned
        // done:false having done nothing would loop the caller forever.
        $stepped = false;

        while ($state['pass'] !== null) {
            if ($stepped && microtime(true) >= $until) {
                break;
            }
            $stepped = true;

            $pass = (string) $state['pass'];

            if ($pass === 'currency') {
                if ($scope !== 'all' && $scope !== 'currency') {
                    $state = self::recalcAdvance($state);
                    continue;
                }

                // On its own cursor and the same clock as the aggregate passes.
                // Run to completion it outlived the time limit on any real
                // backlog, and since it recorded nothing the next press began
                // the identical walk: the forever loop the paging was added to
                // end, in the one pass the paging never reached.
                if ($this->recalcCurrencyPass($state, $until)) {
                    $state = self::recalcAdvance($state);
                }
                continue;
            }

            // Add-ons recompute theirs from the same source rows, after the
            // core passes, so anything derived from a campaign total is
            // rebuilt from a campaign total that is already correct.
            if ($pass === 'addons') {
                /**
                 * Process one add-on rebuild page. Store progress under your cursor key and
                 * return done=false while rows remain. Check the clock after processing a row
                 * to guarantee progress; return the bag unchanged when idle.
                 *
                 * @param array{counts:array<string,int>,cursor:array<string,mixed>,done:bool} $bag
                 * @param string $scope  'all' or the one scope asked for
                 * @param float  $until  microtime to stop at
                 *
                 * @since 1.0.0
                 */
                $bag = (array) apply_filters(
                    'gratora.recalculate.addons',
                    [
                        'counts' => $state['counts'],
                        'cursor' => (array) ($state['addon_cursor'] ?? []),
                        'done'   => true,
                    ],
                    $state['rebuild_all'] ? 'all' : $scope,
                    $until
                );

                $state['counts']       = (array) ($bag['counts'] ?? $state['counts']);
                $state['addon_cursor'] = (array) ($bag['cursor'] ?? []);

                // Left on this pass, so the cursor is stored and the caller's
                // next request picks the add-on up where it stopped.
                if (! ($bag['done'] ?? true)) {
                    break;
                }

                $state = self::recalcAdvance($state);
                continue;
            }

            if (! $state['rebuild_all'] && $scope !== $pass) {
                $state = self::recalcAdvance($state);
                continue;
            }

            $ids = self::recalcChunk(self::RECALC_TABLES[$pass], (int) $state['after']);
            if ($ids === []) {
                $state = self::recalcAdvance($state);
                continue;
            }

            foreach ($ids as $id) {
                $this->recalcOne($pass, $id);
                $state['after'] = $id;
                $state['counts'][$pass] = (int) ($state['counts'][$pass] ?? 0) + 1;

                if (microtime(true) >= $until) {
                    break;
                }
            }
        }

        $done = $state['pass'] === null;
        if ($done) {
            delete_option(self::RECALC_CURSOR_OPTION);
        } else {
            update_option(self::RECALC_CURSOR_OPTION, $state, false);
        }

        return new WP_REST_Response([
            'ok'                      => true,
            'scope'                   => $scope,
            'counts'                  => $state['counts'],
            'still_unconvertible'     => (int) ($state['still_unconvertible'] ?? 0),
            'unconvertible_currencies' => array_values((array) ($state['unconvertible_currencies'] ?? [])),
            'done'                    => $done,
        ], 200);
    }

    /**
     * How long one request spends rebuilding before handing the rest back to
     * the caller. Well inside the usual max_execution_time and gateway
     * timeouts, so the admin gets an answer rather than a 504.
     */
    private const RECALC_BUDGET_SECONDS = 15.0;

    /** Where an unfinished run got to. Absent between runs. */
    private const RECALC_CURSOR_OPTION = 'gratora_recalculate_cursor';

    /** In order. currency runs first so a donation that finally has a rate is counted. */
    private const RECALC_PASSES = ['currency', 'donors', 'funds', 'campaigns', 'forms', 'addons'];

    private const RECALC_TABLES = [
        'donors'    => 'gratora_donors',
        'funds'     => 'gratora_funds',
        'campaigns' => 'gratora_campaigns',
        'forms'     => 'gratora_forms',
    ];

    /**
     * The unfinished run's state, or a fresh one. A run for a different scope
     * replaces it: the admin asked for something else.
     *
     * @return array<string,mixed>
     */
    private function recalcState(string $scope): array
    {
        $stored = get_option(self::RECALC_CURSOR_OPTION);
        if (is_array($stored) && ($stored['scope'] ?? null) === $scope && isset($stored['counts'])) {
            return $stored;
        }

        return [
            'scope'       => $scope,
            'pass'        => self::RECALC_PASSES[0],
            'after'       => 0,
            'rebuild_all' => $scope === 'all',
            'counts'      => ['donors' => 0, 'funds' => 0, 'campaigns' => 0, 'forms' => 0],
            // The currency pass runs in the first round only, so a resumed
            // run has to carry its answer forward.
            'still_unconvertible'      => 0,
            'unconvertible_currencies' => [],
            'addon_cursor'             => [],
        ];
    }

    /**
     * @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    private static function recalcAdvance(array $state): array
    {
        $at = array_search($state['pass'], self::RECALC_PASSES, true);

        $state['pass']  = self::RECALC_PASSES[(int) $at + 1] ?? null;
        $state['after'] = 0;

        return $state;
    }

    /**
     * Recording a donation never blocks on FX, so rows with no rate yet are
     * real payments sitting outside every total until one exists.
     *
     * @param array<string,mixed> $state
     */
    /** @return bool whether the backlog is through, so the pass can advance. */
    private function recalcCurrencyPass(array &$state, float $until): bool
    {
        $fx        = $this->fxBackfill->run((int) ($state['after'] ?? 0), $until);
        $converted = (int) $fx['converted'];

        $state['after'] = (int) $fx['after'];
        $state['counts']['converted_donations'] =
            (int) ($state['counts']['converted_donations'] ?? 0) + $converted;
        if (($fx['plans'] ?? 0) > 0) {
            $state['counts']['converted_plans'] = (int) $fx['plans'];
        }
        // A plan carries its own base amount, so converting one changes
        // recurring revenue even when no donation moved.
        $converted += (int) ($fx['plans'] ?? 0);
        // Not in counts: those are things that were synced, and this is the
        // opposite. Reported as a success line it read "2 synced" about rows
        // that are still missing from every total.
        $state['still_unconvertible']    = (int) $fx['unconvertible'];
        $state['unconvertible_currencies'] = array_values((array) ($fx['currencies'] ?? []));

        // Converting a donation changes every total it belongs to, so the
        // aggregate passes have to run whatever the scope was. Without it, a
        // currency-only pass writes base amounts, rebuilds nothing, and
        // reports success while every total stays wrong.
        if ($converted > 0) {
            $state['rebuild_all'] = true;
        }

        return (bool) $fx['done'];
    }

    private function recalcOne(string $pass, int $id): void
    {
        match ($pass) {
            'donors'    => $this->aggregates->syncDonor($id),
            'funds'     => $this->aggregates->syncFund($id),
            'campaigns' => $this->aggregates->syncCampaign($id),
            'forms'     => $this->aggregates->syncForm($id),
            default     => null,
        };
    }

    /**
     * Ids only, never hydrated models, so the memory a rebuild needs does not
     * grow with the org.
     *
     * @return list<int>
     */
    private static function recalcChunk(string $table, int $after): array
    {
        $rows = DB::table($table)
            ->select('id')
            ->where('id', $after, '>')
            ->orderBy('id')
            ->limit(self::RECALC_CHUNK)
            ->getAll();

        $ids = [];
        foreach ($rows as $row) {
            // DB::table() yields plain rows, not hydrated models.
            $id = (int) (is_array($row) ? ($row['id'] ?? 0) : $row->id);
            if ($id <= 0) {
                break;
            }
            $ids[] = $id;
        }

        return $ids;
    }

    /** How many ids to hold at once while walking a table. */
    private const RECALC_CHUNK = 500;

    private const SETTINGS_OPTIONS = [
        'gratora_org_profile',
        'gratora_currency_locale',
        'gratora_org_brand',
        'gratora_gateway_config',
        'gratora_privacy',
        'gratora_roles',
        'gratora_consents',
        'gratora_receipt_settings',
        'gratora_email_settings',
        'gratora_reference_settings',
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
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://temp stream, not a filesystem path; WP_Filesystem has no streaming equivalent.
        $out = fopen('php://temp/maxmemory:8388608', 'r+');
        $this->exporter->writeJson($out);
        rewind($out);

        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="gratora-export-' . gmdate('Y-m-d') . '.json"');
        fpassthru($out);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- php://temp stream, not a filesystem path; WP_Filesystem has no streaming equivalent.
        fclose($out);
        exit;
    }

    /** @since 1.0.0 */
    public function export(): WP_REST_Response
    {
        $data = [
            'exported_at' => gmdate('c'),
            'site_url'    => site_url(),
            'version'     => defined('GRATORA_VERSION') ? GRATORA_VERSION : 'unknown',
            'settings'    => [],
        ];
        foreach (self::SETTINGS_OPTIONS as $opt) {
            $value = get_option($opt, null);
            if ($value === null) {
                continue;
            }

            // An export is a file people attach to support tickets and commit
            // to repositories. gratora_gateway_config holds the Stripe webhook
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
            return new \WP_Error('gratora_invalid_csv', __('That file is empty.', 'gratora-donation-platform'), ['status' => 422]);
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
            return new \WP_Error('gratora_invalid_csv', __('That file is empty.', 'gratora-donation-platform'), ['status' => 422]);
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

        // A file carrying tables is a full export.
        $hasRecords = is_array($body['tables'] ?? null);

        $settings = is_array($body['settings'] ?? null) ? $body['settings'] : null;
        if ($settings === null) {
            if ($hasRecords) {
                $records = $this->importer->import($body);
                DonorRetention::deferBy();

                return new WP_REST_Response(['imported' => true, 'records' => $records, 'settings_applied' => 0], 200);
            }

            return new \WP_Error('gratora_invalid_import', __('No settings payload found.', 'gratora-donation-platform'), ['status' => 422]);
        }

        // Settings first, so every guard on the write reads the site as it
        // stands rather than as the file has just made it. The base-currency
        // lock counts the money already recorded here, which is what it is
        // protecting; run after the restore it counts the file's own donations
        // and refuses the org its own base. The numbering the file's references
        // were printed in is in force by the time the importer reads them, and
        // a group the site refuses stops the money landing under a unit nobody
        // agreed to.
        $erasureWasOn = self::erasureIsOn();

        $writer = new SettingsService();

        $applied = 0;
        /** @var array<string,string> $refused */
        $refused = [];
        $locked  = false;

        foreach (self::SETTINGS_OPTIONS as $opt) {
            if (! array_key_exists($opt, $settings)) {
                continue;
            }

            $incoming = $settings[$opt];

            // Every one of these options holds a group of keys. A scalar landing
            // in one is not a partial restore: each reader merges what it finds
            // over the group defaults, so the option reads as the defaults, and
            // for the currency group that means the base silently becomes USD.
            if (! is_array($incoming)) {
                $refused[$opt] = __('That entry is not a settings group.', 'gratora-donation-platform');
                continue;
            }

            $group = self::groupFor($writer, $opt);

            // Nothing on this install declares it, so there is no shape to check
            // it against and nothing that would read it back. Writing the option
            // anyway would restore a setting nobody honours, past every guard.
            if ($group === null) {
                $refused[$opt] = __('This site has no settings group by that name.', 'gratora-donation-platform');
                continue;
            }

            // A masked value in the file means "whatever is already stored",
            // so importing an export cannot wipe the secrets it could not
            // carry. Read through the settings writer rather than the raw
            // option, which on a site that has never saved this group is
            // absent entirely.
            $incoming = SecretRedactor::restore($incoming, $writer->get($group));

            // An attachment id means nothing on another site: the export carries
            // no media, so the id either resolves to a different picture or to
            // none. Dropped rather than restored, which leaves the receipt with
            // no logo until one is uploaded here.
            unset($incoming['logo_attachment_id']);

            // Through the settings writer, so a restore inherits what every
            // other writer does: the base-currency lock, the per-group type
            // whitelist, and the gratora.settings.updated broadcast that the FX
            // snapshot, the campaign currency sync and the role capabilities
            // hang off.
            try {
                $writer->update($group, $incoming);
            } catch (BaseCurrencyLocked $e) {
                $refused[$opt] = $e->getMessage();
                $locked        = true;
                continue;
            } catch (\RuntimeException | \InvalidArgumentException $e) {
                // Every other refusal the writer raises: a numbering format with
                // a token nothing answers to, a currency that is not a code.
                // Uncaught, one of them ended the restore as a fatal after nine
                // groups were already written, with no report of what landed.
                $refused[$opt] = $e->getMessage();
                continue;
            }

            $applied++;
        }

        // A refusal reaches the admin as a refusal, and the records stay out.
        // Reported inside a 200 it reads as "settings restored", and the one
        // group that did not land is the one holding the unit every recorded
        // total is denominated in; restoring the money behind that refusal
        // denominates it in whatever the site already had.
        if ($refused !== []) {
            return new \WP_Error(
                $locked ? 'gratora_base_currency_locked' : 'gratora_invalid_import',
                sprintf(
                    /* translators: %s: one or more refusal messages, already sentences. */
                    __('Part of that file was not restored. %s', 'gratora-donation-platform'),
                    implode(' ', $refused)
                ),
                [
                    'status'   => $locked ? 409 : 422,
                    'applied'  => $applied,
                    'refused'  => $refused,
                    'imported' => false,
                    'records'  => null,
                ]
            );
        }

        $records = null;
        if ($hasRecords) {
            $records = $this->importer->import($body);
            DonorRetention::deferBy();
        }

        // A file can carry automatic erasure switched on, and it lands by
        // option write rather than through the settings screen, so the screen's
        // own re-arm never sees it. Without this, restoring a backup onto a
        // site whose activation stamp is months past sweeps that same night.
        if (! $erasureWasOn && self::erasureIsOn()) {
            DonorRetention::deferBy();
        }

        return new WP_REST_Response([
            'ok'       => true,
            'applied'  => $applied,
            'imported' => $records !== null,
            'records'  => $records,
        ], 200);
    }

    /**
     * The settings group that owns an option, or null when nothing declares it.
     *
     * Read off the group map rather than a second list here, so a group an
     * add-on registers through `gratora.settings.groups` is written the same way as
     * a core one.
     *
     * @since 1.0.0
     */
    private static function groupFor(SettingsService $settings, string $option): ?string
    {
        foreach ($settings->groups() as $group => $cfg) {
            if (is_array($cfg) && ($cfg['option'] ?? null) === $option) {
                return (string) $group;
            }
        }

        return null;
    }

    /** @since 1.0.0 */
    private static function erasureIsOn(): bool
    {
        $privacy = get_option('gratora_privacy', []);

        return is_array($privacy) && ! empty($privacy['erase_inactive_donors']);
    }

    /** @since 1.0.0 */
    public function purgeTestData(\WP_REST_Request $request): WP_REST_Response|\WP_Error
    {
        // Typed, not clicked: the button is one keystroke away from a ledger
        // that cannot be restored, and there is no undo behind it.
        if (strtoupper(trim((string) $request->get_param('confirmation'))) !== 'DELETE') {
            return new \WP_Error(
                'gratora_confirmation_required',
                sprintf(
                    /* translators: %s: the literal confirmation keyword to type (DELETE) */
                    __('Type %s to confirm.', 'gratora-donation-platform'),
                    'DELETE'
                ),
                ['status' => 400]
            );
        }

        return new WP_REST_Response($this->testData->purge(), 200);
    }

    /** @since 1.0.0 */
    public function canAccess(): bool
    {
        return Capabilities::userCan('gratora_manage_settings');
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
            'all'       => __('Everything', 'gratora-donation-platform'),
            'currency'  => __('Currency conversions', 'gratora-donation-platform'),
            'donors'    => __('Donors', 'gratora-donation-platform'),
            'funds'     => __('Funds', 'gratora-donation-platform'),
            'campaigns' => __('Campaigns', 'gratora-donation-platform'),
            'forms'     => __('Forms', 'gratora-donation-platform'),
        ];

        $added = (array) apply_filters('gratora.recalculate.scopes', []);
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
        // Action Scheduler, not WP-Cron: every Gratora job is queued through
        // AsyncDispatcher into the 'gratora' group, and nothing in the plugin
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
            'version'   => defined('GRATORA_VERSION') ? GRATORA_VERSION : 'unknown',
            'php'       => PHP_VERSION,
            'wp'        => get_bloginfo('version'),
            'rest_root' => esc_url_raw(rest_url('gratora/v1/')),
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
            // The full report, grouped, so the screen and the clipboard carry
            // the same thing and neither has to know what is in it.
            'report'                => $this->report->sections(),
            'test_data'             => $this->testData->preview(),
            'recalc_scopes'         => array_map(
                static fn ($slug, $label): array => ['value' => $slug, 'label' => $label],
                array_keys(self::scopes()),
                array_values(self::scopes())
            ),
        ], 200);
    }

}
