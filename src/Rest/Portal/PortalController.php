<?php

declare(strict_types=1);

namespace Dono\Rest\Portal;

use Dono\Analytics\ErrorLog;
use Dono\Async\AsyncDispatcher;
use Dono\Campaigns\Campaign;
use Dono\Donations\Donation;
use Dono\Donations\DonationQueries;
use Dono\Donations\DonationRepository;
use Dono\Donors\ConsentService;
use Dono\Donors\Donor;
use Dono\Donors\DonorRepository;
use Dono\Donors\DonorService;
use Dono\Donors\MagicLinkService;
use Dono\Donors\Portal\AnnualStatementBuilder;
use Dono\Donors\Portal\PortalSession;
use Dono\Foundation\Identity\IdentityHasher;
use Dono\Gateways\GatewayManager;
use Dono\Gateways\SupportsPaymentMethodUpdate;
use Dono\Mail\Mailer;
use Dono\Receipts\Receipt;
use Dono\Receipts\ReceiptRepository;
use Dono\Recurring\RecurringPlan;
use Dono\Recurring\RecurringPlanActions;
use Dono\Recurring\RecurringPlanChange;
use RuntimeException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use Dono\Vendor\Queryable\DB;

/**
 * Donor portal REST surface: authentication, profile, donations, recurring
 * plans, receipts, consents, data export, and account erasure.
 *
 * @version 1.0.0
 */
final class PortalController
{
    private const NAMESPACE = 'dono/v1';

    public const SEND_LINK_HOOK          = 'dono.async.send_portal_link';
    private const SEND_LINK_IP_MAX       = 10;
    private const SEND_LINK_IP_WINDOW    = 15 * MINUTE_IN_SECONDS;
    private const SEND_LINK_EMAIL_WINDOW = 5 * MINUTE_IN_SECONDS;

    public function __construct(
        private PortalSession $session,
        private DonorRepository $donors,
        private DonorService $donorService,
        private DonationRepository $donations,
        private ReceiptRepository $receipts,
        private MagicLinkService $magicLinks,
        private IdentityHasher $hasher,
        private AnnualStatementBuilder $annualStatements,
        private ConsentService $consents,
        private Mailer $mailer,
        private AsyncDispatcher $async,
        private \Dono\Donations\DonationService $donationService,
        private \Dono\Donors\DonorMetricsService $metrics,
        private RecurringPlanActions $planActions,
        private GatewayManager $gateways,
    ) {
    }

    public function registerHooks(): void
    {
        // Action Scheduler spreads the enqueued args positionally, so accept 2.
        add_action(self::SEND_LINK_HOOK, [$this, 'handleSendLinkAsync'], 10, 2);
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/portal/exchange', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'exchange'],
            'permission_callback' => '__return_true',
            'args'                => ['token' => ['type' => 'string', 'required' => true]],
        ]);

        register_rest_route(self::NAMESPACE, '/portal/send-link', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'sendLink'],
            'permission_callback' => '__return_true',
            'args'                => ['email' => ['type' => 'string', 'required' => true]],
        ]);

        register_rest_route(self::NAMESPACE, '/portal/register', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'registerDonor'],
            'permission_callback' => '__return_true',
            'args'                => [
                'email'      => ['type' => 'string', 'required' => true],
                'first_name' => ['type' => 'string'],
                'last_name'  => ['type' => 'string'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/portal/logout', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'logout'],
            // CSRF-gated: the portal JS already sends X-Dono-Csrf on every call,
            // so a cross-site forged POST can't sign the donor out.
            'permission_callback' => [$this, 'sessionWithCsrf'],
        ]);

        register_rest_route(self::NAMESPACE, '/portal/me', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'me'],
            'permission_callback' => [$this, 'session'],
        ]);

        register_rest_route(self::NAMESPACE, '/portal/donations', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'donationsList'],
            'permission_callback' => [$this, 'session'],
        ]);

        register_rest_route(self::NAMESPACE, '/portal/donations/(?P<reference>[A-Za-z0-9_\-]+)', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'donationShow'],
            'permission_callback' => [$this, 'session'],
        ]);

        register_rest_route(self::NAMESPACE, '/portal/donations/(?P<reference>[A-Za-z0-9_\-]+)/anonymity', [
            'methods'             => WP_REST_Server::EDITABLE,
            'callback'            => [$this, 'donationAnonymity'],
            'permission_callback' => [$this, 'sessionWithCsrf'],
        ]);

        register_rest_route(self::NAMESPACE, '/portal/recurring', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'recurring'],
            'permission_callback' => [$this, 'session'],
        ]);

        register_rest_route(self::NAMESPACE, '/portal/recurring/(?P<id>\d+)/action', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'recurringAction'],
            'permission_callback' => [$this, 'sessionWithCsrf'],
        ]);

        register_rest_route(self::NAMESPACE, '/portal/recurring/(?P<id>\d+)/payment-method', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'startPaymentMethodUpdate'],
            'permission_callback' => [$this, 'sessionWithCsrf'],
        ]);

        register_rest_route(self::NAMESPACE, '/portal/recurring/(?P<id>\d+)/payment-method/complete', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'completePaymentMethodUpdate'],
            'permission_callback' => [$this, 'sessionWithCsrf'],
            'args'                => ['token' => ['type' => 'string', 'required' => true]],
        ]);

        register_rest_route(self::NAMESPACE, '/portal/receipts', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'receiptsList'],
            'permission_callback' => [$this, 'session'],
        ]);

        register_rest_route(self::NAMESPACE, '/portal/receipts/(?P<id>\d+)/download-url', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'receiptDownloadUrl'],
            'permission_callback' => [$this, 'session'],
        ]);

        register_rest_route(self::NAMESPACE, '/portal/annual-statement/(?P<year>\d{4})', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'annualStatement'],
            'permission_callback' => [$this, 'session'],
        ]);

        register_rest_route(self::NAMESPACE, '/portal/profile', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'profileShow'],
                'permission_callback' => [$this, 'session'],
            ],
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [$this, 'profileUpdate'],
                'permission_callback' => [$this, 'sessionWithCsrf'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/portal/preferences', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'preferencesShow'],
                'permission_callback' => [$this, 'session'],
            ],
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [$this, 'preferencesUpdate'],
                'permission_callback' => [$this, 'sessionWithCsrf'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/portal/consents', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'consentsShow'],
                'permission_callback' => [$this, 'session'],
            ],
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [$this, 'consentsUpdate'],
                'permission_callback' => [$this, 'sessionWithCsrf'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/portal/data-export', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'dataExport'],
            'permission_callback' => [$this, 'sessionWithCsrf'],
        ]);

        register_rest_route(self::NAMESPACE, '/portal/forget', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'forget'],
            'permission_callback' => [$this, 'sessionWithCsrf'],
            'args'                => [
                'confirm' => ['type' => 'string', 'required' => true],
            ],
        ]);
    }

    /** Read a single setting from the `dono_privacy` group with a typed default. */
    private function privacySetting(string $key, $default)
    {
        $opt = get_option('dono_privacy', []);
        if (! is_array($opt)) return $default;
        return array_key_exists($key, $opt) ? $opt[$key] : $default;
    }

    public function session(): bool
    {
        return $this->session->currentDonorId() !== null;
    }

    /**
     * Session plus matching `X-Dono-Csrf` header. Writes only; defence in
     * depth on top of the SameSite=Lax cookie.
     */
    public function sessionWithCsrf(WP_REST_Request $request): bool
    {
        $expected = $this->session->csrfToken();
        if ($expected === null || $expected === '') return false;
        $provided = (string) $request->get_header('X-Dono-Csrf');
        if ($provided === '') return false;
        return hash_equals($expected, $provided);
    }

    public function exchange(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $token = (string) $request['token'];
        $session = $this->session->startFromToken($token);
        if (! $session) {
            return new WP_Error('dono_invalid_token', __('Sign-in link is invalid or expired.', 'dono'), ['status' => 401]);
        }
        return new WP_REST_Response([
            'ok'        => true,
            'donor_id'  => $session['donor_id'],
            'csrf'      => $session['csrf'],
        ], 200);
    }

    /**
     * Always returns 200 so the response can't reveal whether the address
     * belongs to a donor. Rate limited per-IP and per-email; issuance runs
     * async so timing doesn't leak the lookup result either.
     */
    public function sendLink(WP_REST_Request $request): WP_REST_Response
    {
        $ok    = new WP_REST_Response(['ok' => true], 200);
        $email = trim((string) ($request['email'] ?? ''));

        if ($email === '' || ! is_email($email)) return $ok;

        if (! $this->consumeIpQuota()) return $ok;

        $hash = $this->hasher->emailHash($this->hasher->normalizeEmail($email));

        // Lock per-email before the lookup so a hammered address can't reveal existence.
        if (! $this->consumeEmailQuota($hash)) return $ok;

        // Enqueued for every address that gets this far, known or not, and the
        // lookup happens in the job. Doing it here meant the donor branch wrote
        // several Action Scheduler rows before answering while the unknown
        // branch returned at once, and that difference is a reliable test of
        // whether an address is one of the charity's donors, which is exactly
        // what the identical 200 exists to hide.
        $this->async->enqueue(self::SEND_LINK_HOOK, ['email' => $email]);

        return $ok;
    }

    /**
     * Self-register as a donor so non-donors (e.g. would-be fundraisers) can get
     * into the portal without donating first. Verification-first: we create-or-find
     * the donor and email a magic link; a session only starts when they click it.
     * Same 200 whether the email is new or existing, so it can't enumerate donors.
     */
    public function registerDonor(WP_REST_Request $request): WP_REST_Response
    {
        $ok    = new WP_REST_Response(['ok' => true], 200);
        $email = trim((string) ($request['email'] ?? ''));
        if ($email === '' || ! is_email($email)) return $ok;

        if (! $this->consumeIpQuota()) return $ok;
        $hash = $this->hasher->emailHash($this->hasher->normalizeEmail($email));
        if (! $this->consumeEmailQuota($hash)) return $ok;

        // The name is only ever written to a donor this request is creating.
        // This endpoint takes no session and proves nothing about who is
        // calling, and findOrCreate() back-fills any empty field on a donor it
        // finds. So anyone knowing an address could write a name onto that
        // donor's record, and it would then print on their year-end tax
        // statement and on every receipt re-download that carries no name of
        // its own. An existing donor sets their own name from the profile
        // endpoint, which is behind their session.
        $existing = $this->donors->findByEmailHash($hash);

        $profile = [];
        if ($existing === null) {
            // Truncated to the column width rather than rejected: a signup that
            // fails because a surname is long is worse than a stored surname
            // that is short.
            foreach (['first_name', 'last_name'] as $field) {
                $value = trim(sanitize_text_field((string) ($request[$field] ?? '')));
                if ($value !== '') {
                    $profile[$field] = mb_substr($value, 0, 100);
                }
            }
        }

        $donor = $existing ?? $this->donorService->findOrCreate($email, $profile);

        $this->async->enqueue(self::SEND_LINK_HOOK, [
            'donor_id' => (int) $donor->id,
            'email'    => $email,
        ]);

        return $ok;
    }

    /**
     * Action Scheduler executes do_action_ref_array($hook, array_values($args)),
     * so the enqueued ['donor_id'=>.., 'email'=>..] arrives as two positional
     * params, not one array. Accept both shapes.
     *
     * @param array{donor_id?:int, email?:string}|int $args
     */
    public function handleSendLinkAsync(mixed $args = 0, string $email = ''): void
    {
        if (is_array($args)) {
            $email   = (string) ($args['email'] ?? '');
            $donorId = (int) ($args['donor_id'] ?? 0);
        } else {
            $donorId = (int) $args;
        }
        if ($email === '' || ! is_email($email)) return;

        // Resolved here rather than in the request, so the request does the same
        // work for an address that is a donor and one that is not. donor_id is
        // still honoured for jobs queued before that moved, and by registerDonor
        // which already knows the row it just created.
        $donor = $donorId > 0
            ? $this->donors->findById($donorId)
            : $this->donors->findByEmailHash($this->hasher->emailHash($this->hasher->normalizeEmail($email)));

        if (! $donor) return;
        $donorId = (int) $donor->id;

        $raw = $this->magicLinks->issue($donorId, 'donor_portal');
        $url = add_query_arg('token', $raw, $this->portalUrl());
        $donorName = trim(($donor->first_name ?? '') . ' ' . ($donor->last_name ?? ''));

        $this->mailer->sendTemplate('magic_link', $email, [
            'donor_name'        => $donorName !== '' ? $donorName : $email,
            'organisation_name' => (string) get_bloginfo('name'),
            'portal_url'        => $url,
        ]);
    }

    private function consumeIpQuota(): bool
    {
        $ip  = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $key = 'dono_send_link_ip_' . hash('sha256', $ip);
        $cnt = (int) get_transient($key);
        if ($cnt >= self::SEND_LINK_IP_MAX) return false;
        set_transient($key, $cnt + 1, self::SEND_LINK_IP_WINDOW);
        return true;
    }

    private function consumeEmailQuota(string $emailHash): bool
    {
        $key = 'dono_send_link_email_' . substr($emailHash, 0, 32);
        if (get_transient($key) !== false) return false;
        set_transient($key, 1, self::SEND_LINK_EMAIL_WINDOW);
        return true;
    }

    public function logout(): WP_REST_Response
    {
        $this->session->destroy();
        return new WP_REST_Response(['ok' => true], 200);
    }

    public function me(): WP_REST_Response|WP_Error
    {
        $donorId = $this->session->currentDonorId();
        $donor = $donorId ? $this->donors->findById($donorId) : null;
        if (! $donor || $donor->redacted_at !== null) {
            // A redacted donor's session is invalid even if a prior link was
            // already exchanged - the row no longer represents a real person.
            return new WP_Error('dono_session_invalid', __('Session expired.', 'dono'), ['status' => 401]);
        }

        $name = trim(($donor->first_name ?? '') . ' ' . ($donor->last_name ?? ''));
        $currencyCfg = get_option('dono_currency_locale', []);
        $defaultCurrency = is_array($currencyCfg) && ! empty($currencyCfg['default_currency'])
            ? (string) $currencyCfg['default_currency']
            : 'USD';
        return new WP_REST_Response([
            'id'                  => (int) $donor->id,
            'name'                => $name !== '' ? $name : __('Friend', 'dono'),
            'first_name'          => (string) ($donor->first_name ?? ''),
            'last_name'           => (string) ($donor->last_name ?? ''),
            'country'             => (string) ($donor->country ?? ''),
            'total_donated_cents' => (int) $donor->total_donated_cents,
            // A donation taken in a currency the org had no rate for carries a
            // NULL base amount and contributes nothing to the lifetime figure,
            // which is built on the base. The count beside it is a plain count,
            // so a donor could read "3 donations" next to a lifetime total of
            // zero with nothing explaining the gap. Admin screens pair the same
            // figure with this count; the portal now can too.
            'unconverted_count'   => $this->unconvertedDonationCount((int) $donor->id),
            'donations_count'     => (int) $donor->donations_count,
            'first_donation_at'   => $donor->first_donation_at,
            'last_donation_at'    => $donor->last_donation_at,
            'primary_currency'    => $defaultCurrency,
            'csrf'                => (string) ($this->session->csrfToken() ?? ''),
            'consents_pending'    => $this->staleConsentCount((int) $donor->id),
        ], 200);
    }

    /** Donations of this donor's that no lifetime total can include. */
    private function unconvertedDonationCount(int $donorId): int
    {
        $row = DonationQueries::donationsOnly(DB::table('dono_donations'))
            ->whereIn('status', ['paid', 'partial_refund'])
            ->where('donor_id', $donorId)
            ->selectRaw(DonationQueries::unconvertedExpr() . ' AS n')
            ->get();

        return (int) ($row['n'] ?? 0);
    }

    public function donationsList(): WP_REST_Response|WP_Error
    {
        $donor = $this->requireDonor();
        if ($donor instanceof WP_Error) return $donor;
        $donorId = (int) $donor->id;

        // donationsOnly, not live: an event ticket order rides the same table
        // with kind='order', and every other donor-facing total in core
        // excludes those. Listed here they read to the donor as gifts they made,
        // and the list then disagreed with the lifetime figure above it.
        $rows = DonationQueries::donationsOnly(Donation::query())
            ->whereIn('status', ['paid', 'partial_refund'])
            ->where('donor_id', $donorId)
            ->orderBy('paid_at', 'DESC')
            ->limit(100)
            ->getAll();

        $out = [];
        foreach ($rows as $d) {
            $out[] = [
                'id'                => (int) $d->id,
                'reference'         => (string) $d->reference,
                'amount_cents'      => (int) $d->amount_cents,
                'fee_covered_cents' => (int) ($d->fee_covered_cents ?? 0),
                'currency'          => (string) $d->currency,
                'frequency'         => (string) $d->frequency,
                'campaign_id'       => $d->campaign_id ? (int) $d->campaign_id : null,
                'form_id'           => $d->form_id ? (int) $d->form_id : null,
                'paid_at'           => $d->paid_at,
                'is_anonymous'      => (bool) $d->is_anonymous,
            ];
        }
        return new WP_REST_Response($out, 200);
    }

    public function donationShow(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $donor = $this->requireDonor();
        if ($donor instanceof WP_Error) return $donor;

        $d = $this->donations->findByReference((string) $request['reference']);
        // kind, as well as owner: the list excludes ticket orders, so a
        // reference naming one must not open here either.
        if (! $d || $d->donor_id !== $donor->id || (string) $d->kind !== 'donation') {
            return new WP_Error('dono_not_found', '', ['status' => 404]);
        }

        $giveAgainUrl = null;
        if ($d->campaign_id) {
            $campaign = Campaign::query()->find('id', (int) $d->campaign_id);
            if ($campaign && $campaign->page_id) {
                $perma = get_permalink((int) $campaign->page_id);
                if ($perma) {
                    // Prefill the net amount: amount_cents folds the covered
                    // fee in, and the form re-adds the fee on top of the
                    // prefill, so gross would double-count last time's fee.
                    $net = (int) $d->amount_cents - min((int) $d->amount_cents, max(0, (int) ($d->fee_covered_cents ?? 0)));
                    $giveAgainUrl = add_query_arg([
                        'dono_amount'    => $net,
                        'dono_frequency' => $d->frequency,
                    ], $perma);
                }
            }
        }

        // Add-ons own records that hang off a donation, and the donor is
        // entitled to see them here. The filter is how those reach the portal
        // without core knowing what they are.
        $payload = (array) apply_filters('dono.portal.donation', [
            'id'                => (int) $d->id,
            'reference'         => (string) $d->reference,
            'amount_cents'      => (int) $d->amount_cents,
            'fee_covered_cents' => (int) ($d->fee_covered_cents ?? 0),
            'currency'          => (string) $d->currency,
            'frequency'         => (string) $d->frequency,
            'gateway'           => (string) $d->gateway,
            'campaign_id'       => $d->campaign_id ? (int) $d->campaign_id : null,
            'form_id'           => $d->form_id ? (int) $d->form_id : null,
            'paid_at'           => $d->paid_at,
            'is_anonymous'      => (bool) $d->is_anonymous,
            'give_again_url'    => $giveAgainUrl,
        ], $d);

        return new WP_REST_Response($payload, 200);
    }

    public function donationAnonymity(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $donor = $this->requireDonor();
        if ($donor instanceof WP_Error) return $donor;

        $d = $this->donations->findByReference((string) $request['reference']);
        if (! $d || $d->donor_id !== $donor->id) {
            return new WP_Error('dono_not_found', '', ['status' => 404]);
        }
        $body = (array) ($request->get_json_params() ?? []);
        $d->is_anonymous = (bool) ($body['is_anonymous'] ?? false);
        $d->updated_at   = gmdate('Y-m-d H:i:s');
        $d->save();

        do_action('dono.donation.updated', $d);
        return new WP_REST_Response(['ok' => true, 'is_anonymous' => $d->is_anonymous], 200);
    }

    public function recurring(): WP_REST_Response|WP_Error
    {
        $donor = $this->requireDonor();
        if ($donor instanceof WP_Error) return $donor;
        $donorId = (int) $donor->id;

        // Live only, like the donations and receipts lists. A test-mode plan is
        // the organisation rehearsing, not something this donor set up, and
        // showing it here offered them a subscription to cancel that was never
        // theirs.
        $rows = RecurringPlan::query()
            ->where('donor_id', $donorId)
            ->where('is_test', 0)
            ->orderBy('status', 'ASC')
            ->orderBy('next_payment_at', 'ASC')
            ->getAll();

        $out = [];
        foreach ($rows as $p) {
            $out[] = [
                'id'              => (int) $p->id,
                'amount_cents'    => (int) $p->amount_cents,
                'currency'        => (string) $p->currency,
                'interval_unit'   => (string) $p->interval_unit,
                'interval_count'  => (int) $p->interval_count,
                'status'          => (string) $p->status,
                'next_payment_at' => $p->next_payment_at,
                'campaign_id'     => $p->campaign_id ? (int) $p->campaign_id : null,
                // Offline plans have no card, and a gateway that cannot take a
                // new one must not be offered the option.
                'can_update_payment_method' => $this->gateways->get((string) $p->gateway)
                    instanceof SupportsPaymentMethodUpdate,
            ];
        }
        return new WP_REST_Response($out, 200);
    }

    public function recurringAction(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        // Through donorPlan(), not a second copy of the same check: this route
        // and the payment-method ones each had their own, and only one of them
        // learned that a test plan is out of scope.
        $plan = $this->donorPlan($request);
        if ($plan instanceof WP_Error) return $plan;

        $body   = (array) ($request->get_json_params() ?? []);
        $action = (string) ($body['action'] ?? '');
        $now    = gmdate('Y-m-d H:i:s');

        // The guards, the gateway calls and the writes all live in
        // RecurringPlanActions so the admin screen and the command registry
        // cannot drift from what the donor gets. Only the HTTP shape is here.
        $change = RecurringPlanChange::byDonor($action);

        try {
            switch ($action) {
                case 'pause':
                    $this->planActions->pause($plan, RecurringPlanActions::monthsFromNow((int) ($body['months'] ?? 1)), $change);
                    break;

                case 'resume':
                    $this->planActions->resume($plan, $change);
                    break;

                case 'skip_next':
                    $this->planActions->skipNext($plan, $change);
                    break;

                case 'change_amount':
                    $this->planActions->changeAmount($plan, (int) ($body['amount_cents'] ?? 0), $change);
                    break;

                case 'cancel':
                    $this->planActions->cancel($plan, isset($body['reason']) ? (string) $body['reason'] : null, $change);
                    break;

                default:
                    return new WP_Error('dono_invalid_action', '', ['status' => 422]);
            }
        } catch (\Dono\Gateways\SubscriptionChangeNeedsApproval $e) {
            // Ahead of the RuntimeException arm below, which is its parent and
            // would otherwise swallow it and report a live plan as terminal.
            //
            // Not a failure: the processor took the request and is waiting on
            // the donor. Local state stays as it is, because writing the new
            // amount here would tell the donor a change had happened that their
            // card would not agree with. Retrying does not help, so the message
            // does not suggest it.
            return new WP_Error(
                'dono_change_needs_approval',
                __('Your payment provider needs you to approve this change before it takes effect. Nothing has changed yet.', 'dono'),
                ['status' => 409, 'approve_url' => $e->approveUrl]
            );
        } catch (\InvalidArgumentException $e) {
            return new WP_Error('dono_invalid_input', $e->getMessage(), ['status' => 422]);
        } catch (\RuntimeException $e) {
            return new WP_Error('dono_plan_terminal', $e->getMessage(), ['status' => 422]);
        } catch (\Throwable $e) {
            // Gateway (or any downstream) failed; local state intentionally left
            // unchanged. Degrade to a clean 502 rather than a 500.
            ErrorLog::record('portal.recurring', $e->getMessage());
            return new WP_Error(
                'dono_gateway_error',
                __('We could not complete this change with the payment provider. Please try again in a moment.', 'dono'),
                ['status' => 502]
            );
        }

        return new WP_REST_Response([
            'id'              => (int) $plan->id,
            'status'          => (string) $plan->status,
            'next_payment_at' => $plan->next_payment_at,
            'amount_cents'    => (int) $plan->amount_cents,
        ], 200);
    }

    /**
     * Begin changing the card behind a plan.
     *
     * The dunning email has always pointed the donor here, so this is the page
     * that has to be able to do it. A declined renewal is usually an expired
     * card, which retrying cannot fix.
     */
    public function startPaymentMethodUpdate(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $plan = $this->donorPlan($request);
        if ($plan instanceof WP_Error) return $plan;

        $gateway = $this->gateways->get((string) $plan->gateway);
        if (! $gateway instanceof SupportsPaymentMethodUpdate) {
            return new WP_Error(
                'dono_not_supported',
                __('This donation\'s payment method cannot be changed here. Please contact us and we will help.', 'dono'),
                ['status' => 422]
            );
        }

        try {
            $session = $gateway->startPaymentMethodUpdate($plan);
        } catch (\Throwable $e) {
            ErrorLog::record('portal.payment_method', $e->getMessage());
            return new WP_Error(
                'dono_gateway_error',
                __('We could not reach the payment provider. Please try again in a moment.', 'dono'),
                ['status' => 502]
            );
        }

        // The redirect copy names the processor, so it has to come from the
        // gateway; the mode is generic and any gateway may use it.
        return new WP_REST_Response(
            $session->toArray() + ['gateway_label' => $gateway->label()],
            200
        );
    }

    /**
     * Put the card the donor just entered behind the plan.
     *
     * The browser confirmed the setup against the processor, so what arrives
     * here is only an identifier for it; the money path is unchanged and no
     * card detail passes through this site.
     */
    public function completePaymentMethodUpdate(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $plan = $this->donorPlan($request);
        if ($plan instanceof WP_Error) return $plan;

        $gateway = $this->gateways->get((string) $plan->gateway);
        if (! $gateway instanceof SupportsPaymentMethodUpdate) {
            return new WP_Error('dono_not_supported', '', ['status' => 422]);
        }

        try {
            $gateway->completePaymentMethodUpdate($plan, (string) $request['token']);
        } catch (\Throwable $e) {
            ErrorLog::record('portal.payment_method', $e->getMessage());
            return new WP_Error(
                'dono_gateway_error',
                __('The new card could not be saved. Please try again in a moment.', 'dono'),
                ['status' => 502]
            );
        }

        do_action('dono.recurring.payment_method_updated', $plan);

        return new WP_REST_Response(['ok' => true], 200);
    }

    /** The named plan, only if it belongs to the donor whose session this is. */
    private function donorPlan(WP_REST_Request $request): RecurringPlan|WP_Error
    {
        $donor = $this->requireDonor();
        if ($donor instanceof WP_Error) return $donor;

        $plan = RecurringPlan::query()->find('id', (int) $request['id']);
        // Ownership is not the only gate: a test plan is not listed, so it must
        // not be actionable either. Every pause, cancel, amount change and card
        // update comes through here.
        if (! $plan || (int) $plan->donor_id !== (int) $donor->id || $plan->is_test) {
            return new WP_Error('dono_not_found', '', ['status' => 404]);
        }

        return $plan;
    }

    public function receiptsList(): WP_REST_Response|WP_Error
    {
        $donor = $this->requireDonor();
        if ($donor instanceof WP_Error) return $donor;
        $donorId = (int) $donor->id;

        $rows = Receipt::query()
            ->where('donor_id', $donorId)
            ->where('voided', 0)
            ->orderBy('issued_at', 'DESC')
            ->limit(200)
            ->getAll();

        // Exclude receipts tied to test-mode donations so this list agrees with
        // the Overview totals and the Annual Statement (both live-only).
        $testDonationIds = array_flip(array_map(
            static fn ($d) => (int) $d->id,
            Donation::query()->where('donor_id', $donorId)->where('is_test', 1)->getAll()
        ));

        $out = [];
        foreach ($rows as $r) {
            if ($r->donation_id !== null && isset($testDonationIds[(int) $r->donation_id])) {
                continue;
            }
            // No token here. This used to mint a live one-hour download
            // credential for every receipt in the list, up to two hundred of
            // them, every time the tab was opened. The portal never read the
            // field: it asks /receipts/{id}/download-url at click time, which is
            // why that endpoint exists. So each of those was an unauthenticated
            // credential for a donor's receipt, valid for an hour, issued for
            // nobody and counting against the same validation budget the donor
            // needs to sign in.
            $out[] = [
                'id'             => (int) $r->id,
                'receipt_number' => (string) $r->receipt_number,
                'renderer_id'    => (string) $r->renderer_id,
                'issued_at'      => $r->issued_at,
                'donation_id'    => $r->donation_id ? (int) $r->donation_id : null,
            ];
        }
        return new WP_REST_Response($out, 200);
    }

    /**
     * Mint a fresh download token at click time so a portal receipt link never
     * opens expired. Gated on the portal session and the donor's own receipt.
     */
    public function receiptDownloadUrl(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $donor = $this->requireDonor();
        if ($donor instanceof WP_Error) return $donor;
        $donorId = (int) $donor->id;

        $receiptId = (int) $request['id'];
        $receipt   = Receipt::query()
            ->where('id', $receiptId)
            ->where('donor_id', $donorId)
            ->where('voided', 0)
            ->get();
        if (! $receipt) {
            return new WP_Error('dono_receipt_not_found', __('Receipt not found.', 'dono'), ['status' => 404]);
        }

        $token = $this->magicLinks->issue($donorId, 'download_receipt', $receiptId, 3600);
        $url   = add_query_arg('token', $token, rest_url('dono/v1/receipts/' . $receiptId . '/download'));
        return new WP_REST_Response(['url' => esc_url_raw($url)], 200);
    }

    public function annualStatement(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $donor = $this->requireDonor();
        if ($donor instanceof WP_Error) return $donor;

        $year = (int) $request['year'];
        if ($year < 2000 || $year > 2100) {
            return new WP_Error('dono_invalid_year', '', ['status' => 422]);
        }
        $pdf = $this->annualStatements->build($donor, $year);
        if ($pdf === '') {
            return new WP_Error('dono_no_donations', __('No donations found for that year.', 'dono'), ['status' => 404]);
        }

        // Stream PDF bytes directly so the REST server doesn't JSON-encode the
        // binary body. Mirrors the admin receipts/at-risk-csv patterns.
        $filename = sprintf('dono-annual-%d.pdf', $year);
        $route    = $request->get_route();
        add_filter('rest_pre_serve_request', function (bool $served, $result, $req, $server) use ($route, $pdf, $filename) {
            if ((string) $req->get_route() !== $route) return $served;
            $server->send_header('Content-Type', 'application/pdf');
            $server->send_header('Content-Disposition', 'inline; filename="' . $filename . '"');
            $server->send_header('Cache-Control', 'private, no-cache, no-store, must-revalidate');
            echo $pdf;
            return true;
        }, 10, 4);

        $response = new WP_REST_Response(null, 200);
        $response->set_headers([
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
        ]);
        return $response;
    }

    public function profileShow(): WP_REST_Response|WP_Error
    {
        $donorId = $this->session->currentDonorId();
        $donor   = $donorId ? $this->donors->findById($donorId) : null;
        if (! $donor || $donor->redacted_at !== null) return new WP_Error('dono_unauthorized', '', ['status' => 401]);

        return new WP_REST_Response([
            'email'      => (string) ($this->donorService->decryptEmail($donor) ?? ''),
            'phone'      => (string) ($this->donorService->decryptPhone($donor) ?? ''),
            'first_name' => (string) ($donor->first_name ?? ''),
            'last_name'  => (string) ($donor->last_name ?? ''),
            'country'    => (string) ($donor->country ?? ''),
            'company'    => (string) ($donor->company ?? ''),
        ], 200);
    }

    public function profileUpdate(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $donorId = $this->session->currentDonorId();
        $donor   = $donorId ? $this->donors->findById($donorId) : null;
        if (! $donor || $donor->redacted_at !== null) return new WP_Error('dono_unauthorized', '', ['status' => 401]);

        $body  = (array) ($request->get_json_params() ?? []);
        $patch = [];
        foreach (['first_name', 'last_name', 'country', 'company', 'phone'] as $f) {
            if (array_key_exists($f, $body)) $patch[$f] = $body[$f];
        }
        // editProfile (not refreshProfile): donors own their record and can
        // explicitly overwrite previously-populated values. refreshProfile's
        // lock-on-first-write is the donation-flow back-fill, not a portal edit.
        $this->donorService->editProfile($donor, $patch);
        return $this->profileShow();
    }

    public function preferencesShow(): WP_REST_Response|WP_Error
    {
        $donor = $this->requireDonor();
        if ($donor instanceof WP_Error) return $donor;
        $donorId = (int) $donor->id;

        $flags = (array) ($this->donors->findById($donorId)?->flags ?? []);
        $prefs = is_array($flags['prefs'] ?? null) ? $flags['prefs'] : [];

        return new WP_REST_Response(self::normalizePrefs($prefs), 200);
    }

    public function preferencesUpdate(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $donor = $this->requireDonor();
        if ($donor instanceof WP_Error) return $donor;

        $body  = (array) ($request->get_json_params() ?? []);
        $next  = self::normalizePrefs($body);
        $flags = is_array($donor->flags) ? $donor->flags : [];
        $flags['prefs'] = $next;
        $donor->flags      = $flags;
        $donor->updated_at = gmdate('Y-m-d H:i:s');
        $donor->save();

        return new WP_REST_Response($next, 200);
    }

    public function consentsShow(): WP_REST_Response|WP_Error
    {
        $donor = $this->requireDonor();
        if ($donor instanceof WP_Error) return $donor;

        return new WP_REST_Response($this->shapeConsents((int) $donor->id), 200);
    }

    /**
     * Per-purpose consent payload. `stale` is true when the stored version
     * is behind the current purpose version.
     *
     * @return list<array<string,mixed>>
     */
    private function shapeConsents(int $donorId): array
    {
        $purposes = $this->consents->purposes();
        $latest   = $this->consents->latestByPurpose($donorId);

        $out = [];
        foreach ($purposes as $p) {
            $row             = $latest[$p['key']] ?? null;
            $currentVersion  = (int) $p['version'];
            $storedVersion   = $row ? (int) $row->purpose_version : 0;
            $stale           = $row !== null && $storedVersion < $currentVersion;

            $out[] = [
                'key'            => $p['key'],
                'label'          => $p['label'],
                'description'    => $p['description'],
                'required'       => $p['required'],
                'version'        => $currentVersion,
                'stored_version' => $storedVersion,
                'stale'          => $stale,
                // The record is the truth: `default` is the donation form's
                // pre-selection at the point of collection, not a subscription
                // the donor holds. The delivery gate and the admin consent view
                // both read (row && granted), so a box ticked from `default`
                // here would claim a subscription nothing honours.
                'granted'        => $row !== null && (bool) $row->granted,
                'occurred_at'    => $row->occurred_at ?? null,
                'has_record'     => $row !== null,
            ];
        }

        // A donation form may define its own consent purposes, and the create
        // path deliberately records them. Listing only the org registry meant
        // any purpose that lives on a form and not in Settings > Consents was
        // invisible here: the donor agreed to it on the form and then had no
        // way to see it, let alone withdraw it.
        $known = [];
        foreach ($purposes as $p) {
            $known[$p['key']] = true;
        }

        foreach ($latest as $key => $row) {
            if (isset($known[$key])) {
                continue;
            }

            $out[] = [
                'key'   => (string) $key,
                // No label is stored with the record and the form that defined
                // it may be gone, so the key is humanised rather than shown raw.
                'label' => ucfirst(str_replace(['_', '-'], ' ', (string) $key)),
                'description'    => '',
                // Nothing off the registry can be required: "required" is a
                // property of a registered purpose, and treating it as one
                // would make a consent the donor cannot withdraw.
                'required'       => false,
                'version'        => (int) $row->purpose_version,
                'stored_version' => (int) $row->purpose_version,
                'stale'          => false,
                'granted'        => (bool) $row->granted,
                'occurred_at'    => $row->occurred_at ?? null,
                'has_record'     => true,
            ];
        }

        return $out;
    }

    /** Count how many of this donor's consents are behind their current version. */
    private function staleConsentCount(int $donorId): int
    {
        $count = 0;
        foreach ($this->shapeConsents($donorId) as $row) {
            if (! empty($row['stale'])) $count++;
        }
        return $count;
    }

    public function consentsUpdate(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $donor = $this->requireDonor();
        if ($donor instanceof WP_Error) return $donor;

        $body  = (array) ($request->get_json_params() ?? []);
        $items = is_array($body['items'] ?? null) ? $body['items'] : [];
        $ip    = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $ua    = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');

        $byKey   = [];
        foreach ($this->consents->purposes() as $p) $byKey[$p['key']] = $p;
        $latest  = $this->consents->latestByPurpose((int) $donor->id);

        // A purpose the donor already has a record for is one they agreed to,
        // so they can withdraw it even when it lives on a form rather than in
        // the org registry. Never required, and never a key they have no
        // record for: this widens what can be revoked, not what can be granted.
        foreach ($latest as $key => $_row) {
            if (! isset($byKey[$key])) {
                $byKey[$key] = ['key' => (string) $key, 'required' => false, 'version' => (int) $_row->purpose_version];
            }
        }

        foreach ($items as $it) {
            $key = (string) ($it['key'] ?? '');
            if (! isset($byKey[$key])) continue;
            $granted = (bool) ($it['granted'] ?? false);
            // Required purposes cannot be revoked, even via a crafted request.
            if (! $granted && ! empty($byKey[$key]['required'])) continue;
            $current = isset($latest[$key]) ? (bool) $latest[$key]->granted : false;
            // Skip a true no-op, but still record when the donor re-affirms an
            // unchanged grant against a newer purpose version (clears "stale").
            $storedVersion = isset($latest[$key]) ? (int) $latest[$key]->purpose_version : -1;
            if (isset($latest[$key]) && $current === $granted
                && $storedVersion >= (int) $byKey[$key]['version']) {
                continue;
            }
            $this->consents->record((int) $donor->id, $key, $granted, [
                'source'  => 'portal',
                'ip'      => $ip,
                'ua'      => $ua,
                'version' => (int) $byKey[$key]['version'],
            ]);
        }

        return $this->consentsShow();
    }

    /**
     * The donor this session belongs to, or 401.
     *
     * Every authenticated portal endpoint goes through here rather than reading
     * the session id directly. Erasure deletes the donor's magic-link tokens so
     * no emailed link can open a new session, but a cookie minted before it
     * kept working for its full life: still listing the erased donor's
     * donations and still minting fresh receipt download tokens, which is the
     * revocation undone.
     */
    private function requireDonor(): Donor|WP_Error
    {
        $donorId = $this->session->currentDonorId();
        $donor   = $donorId ? $this->donors->findById($donorId) : null;
        if (! $donor || $donor->redacted_at !== null) return new WP_Error('dono_unauthorized', '', ['status' => 401]);
        return $donor;
    }

    private function portalUrl(): string
    {
        return (new \Dono\Donors\Portal\PortalPage())->url();
    }

    /** @param array<string,mixed> $raw */
    private static function normalizePrefs(array $raw): array
    {
        $bool = static fn ($v) => (bool) $v;
        // Only the wired preferences are persisted; nothing in the mail layer
        // reads channels or topics.
        return [
            'always_anonymous' => $bool($raw['always_anonymous'] ?? false),
            'pause_until'      => ! empty($raw['pause_until']) ? (string) $raw['pause_until'] : null,
        ];
    }

    /**
     * GDPR right of access. Gated by `privacy.allow_data_export`.
     */
    public function dataExport(): WP_REST_Response|WP_Error
    {
        if (! $this->privacySetting('allow_data_export', true)) {
            return new WP_Error(
                'dono_export_disabled',
                __('Data export is disabled by the organisation.', 'dono'),
                ['status' => 403]
            );
        }
        $donor = $this->requireDonor();
        if ($donor instanceof WP_Error) return $donor;

        $email = $this->donorService->decryptEmail($donor);
        $phone = $this->donorService->decryptPhone($donor);

        $donations = Donation::query()
            ->where('donor_id', (int) $donor->id)
            ->orderBy('created_at', 'DESC')
            ->getAll();
        $donationRows = array_map(static function (Donation $d): array {
            return [
                'id'             => (int) $d->id,
                'reference'      => $d->reference,
                'status'         => $d->status,
                'amount_cents'   => (int) $d->amount_cents,
                'currency'       => $d->currency,
                'frequency'      => $d->frequency,
                'campaign_id'    => $d->campaign_id,
                'form_id'        => $d->form_id,
                'gateway'        => $d->gateway,
                'paid_at'        => $d->paid_at,
                'created_at'     => $d->created_at,
            ];
        }, $donations);

        $consents = $this->consents->latestByPurpose((int) $donor->id);
        $consentRows = [];
        foreach ($consents as $purpose => $row) {
            $consentRows[] = [
                'purpose'      => $purpose,
                'granted'      => (bool) $row->granted,
                'version'      => (int) ($row->purpose_version ?? 1),
                'source'       => $row->source,
                'occurred_at'  => $row->occurred_at,
            ];
        }

        $plans = RecurringPlan::query()->where('donor_id', (int) $donor->id)->getAll();
        $planRows = array_map(static function (RecurringPlan $p): array {
            return [
                'id'              => (int) $p->id,
                'gateway'         => $p->gateway,
                'amount_cents'    => (int) $p->amount_cents,
                'currency'        => $p->currency,
                'interval_unit'   => $p->interval_unit,
                'interval_count'  => (int) $p->interval_count,
                'status'          => $p->status,
                'started_at'      => $p->started_at,
                'next_payment_at' => $p->next_payment_at,
                'cancelled_at'    => $p->cancelled_at,
            ];
        }, $plans);

        // The org-side export is core's own answer to "everything we hold on
        // this donor", and the donor's right of access is to that same set. The
        // hand-built bundle here was a thinner one: no address, no receipts, no
        // analytics events, no consent history beyond the latest per purpose,
        // no donor type, and none of what the donor typed into the form. Two
        // definitions of the same legal obligation is one too many, so this
        // asks for the canonical one.
        $bundle = $this->metrics->exportData((int) $donor->id) ?? [
            'donor'     => ['id' => (int) $donor->id, 'email' => $email],
            'donations' => $donationRows,
            'consents'  => $consentRows,
            'recurring' => $planRows,
        ];
        $bundle['exported_at'] = gmdate('c');

        $json     = wp_json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $filename = sprintf('dono-my-data-%d-%s.json', $donor->id, gmdate('Y-m-d'));

        // Stream the JSON as an attachment so the donor's browser saves a
        // file instead of receiving the REST envelope.
        add_filter('rest_pre_serve_request', function (bool $served, $result, $req, $server) use ($json, $filename) {
            if ((string) $req->get_route() !== '/dono/v1/portal/data-export') return $served;
            $server->send_header('Content-Type', 'application/json; charset=utf-8');
            $server->send_header('Content-Disposition', 'attachment; filename="' . $filename . '"');
            $server->send_header('Cache-Control', 'private, no-cache, no-store, must-revalidate');
            echo $json;
            return true;
        }, 10, 4);

        $response = new WP_REST_Response(null, 200);
        $response->set_headers([
            'Content-Type'        => 'application/json; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
        return $response;
    }

    /**
     * GDPR right to erasure. Soft-redact: zeroes PII but keeps donation
     * totals for tax/audit. Gated by `privacy.allow_account_delete`.
     */
    public function forget(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (! $this->privacySetting('allow_account_delete', true)) {
            return new WP_Error(
                'dono_delete_disabled',
                __('Account deletion is disabled by the organisation.', 'dono'),
                ['status' => 403]
            );
        }
        $donor = $this->requireDonor();
        if ($donor instanceof WP_Error) return $donor;

        if (strtoupper((string) $request['confirm']) !== 'DELETE') {
            return new WP_Error(
                'dono_invalid_confirmation',
                __('Type DELETE to confirm.', 'dono'),
                ['status' => 422]
            );
        }

        try {
            $this->donorService->redact($donor);
        } catch (\Dono\Recurring\GatewayUnreachable $e) {
            // Erasure cancels the donor's live recurring plans first, on
            // purpose: wiping the donor while a subscription keeps billing is
            // worse than not wiping them. When the gateway is gone the
            // cancellation cannot happen, and this used to escape the callback
            // as a fatal, so the donor exercising their right to erasure was
            // told only that the request failed, with nothing to do next.
            ErrorLog::record('portal.forget', $e->getMessage());

            return new WP_Error(
                'dono_erasure_blocked',
                __('We could not stop your recurring donation with the payment provider, so your account has not been deleted yet. Please contact the organisation and they will finish this for you.', 'dono'),
                ['status' => 409]
            );
        }

        $this->session->destroy();

        return new WP_REST_Response(['ok' => true], 200);
    }
}
