<?php

declare(strict_types=1);

namespace Dono\Rest\Portal;

use Dono\Async\AsyncDispatcher;
use Dono\Campaigns\Campaign;
use Dono\Currency\Currency;
use Dono\Donations\Donation;
use Dono\Donations\DonationQueries;
use Dono\Donations\DonationRepository;
use Dono\Donations\DonationTributeRepository;
use Dono\Donors\ConsentService;
use Dono\Donors\Donor;
use Dono\Donors\DonorRepository;
use Dono\Donors\DonorService;
use Dono\Donors\MagicLinkService;
use Dono\Donors\Portal\AnnualStatementBuilder;
use Dono\Donors\Portal\PortalSession;
use Dono\Foundation\Identity\IdentityHasher;
use Dono\Gateways\GatewayManager;
use Dono\Gateways\SubscriptionAware;
use Dono\Mail\Mailer;
use Dono\Receipts\Receipt;
use Dono\Receipts\ReceiptRepository;
use Dono\Recurring\RecurringCanceller;
use Dono\Recurring\RecurringPlan;
use RuntimeException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Donor portal REST surface: authentication, profile, donations, recurring
 * plans, receipts, consents, data export, and account erasure.
 *
 * @version 1.0.0
 */
final class PortalController
{
    private const NAMESPACE = 'dono/v1';

    private const SEND_LINK_HOOK         = 'dono.async.send_portal_link';
    private const SEND_LINK_IP_MAX       = 10;
    private const SEND_LINK_IP_WINDOW    = 15 * MINUTE_IN_SECONDS;
    private const SEND_LINK_EMAIL_WINDOW = 5 * MINUTE_IN_SECONDS;

    public function __construct(
        private PortalSession $session,
        private DonorRepository $donors,
        private DonorService $donorService,
        private DonationRepository $donations,
        private ReceiptRepository $receipts,
        private DonationTributeRepository $tributes,
        private MagicLinkService $magicLinks,
        private IdentityHasher $hasher,
        private AnnualStatementBuilder $annualStatements,
        private ConsentService $consents,
        private GatewayManager $gateways,
        private Mailer $mailer,
        private AsyncDispatcher $async,
        private \Dono\Donations\DonationService $donationService,
        private RecurringCanceller $canceller,
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
                'email' => ['type' => 'string', 'required' => true],
                'name'  => ['type' => 'string'],
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

        register_rest_route(self::NAMESPACE, '/portal/donations/(?P<reference>[A-Za-z0-9_\-]+)/tribute', [
            'methods'             => WP_REST_Server::EDITABLE,
            'callback'            => [$this, 'donationTribute'],
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

        $donor = $this->donors->findByEmailHash($hash);
        if (! $donor) return $ok;

        $this->async->enqueue(self::SEND_LINK_HOOK, [
            'donor_id' => (int) $donor->id,
            'email'    => $email,
        ]);

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

        $profile = [];
        $name = trim((string) ($request['name'] ?? ''));
        if ($name !== '') {
            $parts = preg_split('/\s+/', $name, 2) ?: [];
            $profile['first_name'] = (string) ($parts[0] ?? '');
            if (isset($parts[1])) {
                $profile['last_name'] = (string) $parts[1];
            }
        }

        $donor = $this->donorService->findOrCreate($email, $profile);

        $this->async->enqueue(self::SEND_LINK_HOOK, [
            'donor_id' => (int) $donor->id,
            'email'    => $email,
        ]);

        return $ok;
    }

    /**
     * Action Scheduler executes do_action_ref_array($hook, array_values($args)),
     * so the enqueued ['donor_id'=>.., 'email'=>..] arrives as two positional
     * params, not one array (single-key jobs hide this; this two-key one didn't).
     * Accept both shapes so the sign-in link actually sends.
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
        if ($donorId <= 0 || $email === '' || ! is_email($email)) return;

        $donor = $this->donors->findById($donorId);
        if (! $donor) return;

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
            'donations_count'     => (int) $donor->donations_count,
            'first_donation_at'   => $donor->first_donation_at,
            'last_donation_at'    => $donor->last_donation_at,
            'primary_currency'    => $defaultCurrency,
            'csrf'                => (string) ($this->session->csrfToken() ?? ''),
            'consents_pending'    => $this->staleConsentCount((int) $donor->id),
        ], 200);
    }

    public function donationsList(): WP_REST_Response|WP_Error
    {
        $donorId = $this->session->currentDonorId();
        if (! $donorId) return new WP_Error('dono_unauthorized', '', ['status' => 401]);

        $rows = DonationQueries::live(Donation::query())
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
        if (! $d || $d->donor_id !== $donor->id) {
            return new WP_Error('dono_not_found', '', ['status' => 404]);
        }

        $tribute = $this->tributes->forDonation((int) $d->id);
        $tributePayload = null;
        if ($tribute) {
            $tributePayload = [
                'type'                => (string) $tribute->type,
                'name'                => (string) $tribute->name,
                'message'             => (string) ($this->tributes->decryptedMessage($tribute) ?? ''),
                'convert_to_annual'   => (bool) $tribute->convert_to_annual,
            ];
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

        return new WP_REST_Response([
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
            'tribute'           => $tributePayload,
            'give_again_url'    => $giveAgainUrl,
        ], 200);
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

    public function donationTribute(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $donor = $this->requireDonor();
        if ($donor instanceof WP_Error) return $donor;

        $d = $this->donations->findByReference((string) $request['reference']);
        if (! $d || $d->donor_id !== $donor->id) {
            return new WP_Error('dono_not_found', '', ['status' => 404]);
        }
        $body = (array) ($request->get_json_params() ?? []);
        $name = trim((string) ($body['name'] ?? ''));
        $type = trim((string) ($body['type'] ?? ''));
        if ($name === '' || $type === '') {
            return new WP_Error('dono_invalid_input', __('Type and name are required.', 'dono'), ['status' => 422]);
        }
        $notify = isset($body['notify_email']) ? trim((string) $body['notify_email']) : '';
        $this->tributes->persist($d, [
            'type'              => $type,
            'name'              => $name,
            'message'           => isset($body['message']) ? (string) $body['message'] : null,
            'notify_email'      => $notify !== '' && is_email($notify) ? $notify : null,
            'convert_to_annual' => ! empty($body['convert_to_annual']),
        ]);
        return new WP_REST_Response(['ok' => true], 200);
    }

    public function recurring(): WP_REST_Response|WP_Error
    {
        $donorId = $this->session->currentDonorId();
        if (! $donorId) return new WP_Error('dono_unauthorized', '', ['status' => 401]);

        $rows = RecurringPlan::query()
            ->where('donor_id', $donorId)
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
            ];
        }
        return new WP_REST_Response($out, 200);
    }

    public function recurringAction(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $donor = $this->requireDonor();
        if ($donor instanceof WP_Error) return $donor;

        $plan = RecurringPlan::query()->find('id', (int) $request['id']);
        if (! $plan || $plan->donor_id !== $donor->id) {
            return new WP_Error('dono_not_found', '', ['status' => 404]);
        }
        $body   = (array) ($request->get_json_params() ?? []);
        $action = (string) ($body['action'] ?? '');
        $now    = gmdate('Y-m-d H:i:s');

        // Terminal plans accept no further actions. The UI only offers actions
        // on active/paused plans; this guards crafted requests against a donor's
        // own already-cancelled/expired plan (esp. Offline plans with no gateway).
        if (in_array((string) $plan->status, ['cancelled', 'expired'], true)) {
            return new WP_Error('dono_plan_terminal', __('This donation is no longer active.', 'dono'), ['status' => 422]);
        }

        // Null when the gateway isn't SubscriptionAware (e.g. Offline); action only flips local state then.
        $gateway = $this->gateways->get((string) $plan->gateway);
        $sub     = $gateway instanceof SubscriptionAware ? $gateway : null;

        try {
            switch ($action) {
                case 'pause':
                    $months    = max(1, min(12, (int) ($body['months'] ?? 1)));
                    $resumesAt = gmdate('Y-m-d H:i:s', strtotime("+{$months} months"));
                    if ($sub) $sub->pauseSubscription($plan, $resumesAt);
                    $plan->status          = 'paused';
                    $plan->next_payment_at = $resumesAt;
                    $plan->save();
                    do_action('dono.recurring.plan_paused', $plan, $months);
                    break;

                case 'resume':
                    if ($sub) $sub->resumeSubscription($plan);
                    $plan->status = 'active';
                    $plan->save();
                    do_action('dono.recurring.plan_resumed', $plan);
                    break;

                case 'skip_next':
                    if ($plan->next_payment_at) {
                        $unit  = ($plan->interval_unit === 'year') ? 'year' : ($plan->interval_unit === 'week' ? 'week' : 'month');
                        $count = max(1, (int) $plan->interval_count);
                        $nextAt = gmdate('Y-m-d H:i:s', strtotime("+{$count} {$unit}", strtotime($plan->next_payment_at)));
                        // Pause with auto-resume so the original charge is skipped.
                        if ($sub) $sub->pauseSubscription($plan, $nextAt);
                        $plan->next_payment_at = $nextAt;
                        $plan->save();
                        do_action('dono.recurring.plan_skipped', $plan);
                    }
                    break;

                case 'change_amount':
                    $newCents = (int) ($body['amount_cents'] ?? 0);
                    if ($newCents < 50) return new WP_Error('dono_invalid_input', __('Amount is too low.', 'dono'), ['status' => 422]);
                    if ($newCents > 99999999) return new WP_Error('dono_invalid_input', __('Amount is too high.', 'dono'), ['status' => 422]);
                    // Same zero-decimal guard as the create path: storage is
                    // major x 100, so a fractional JPY amount would round at
                    // the gateway and permanently disagree with the plan.
                    if (Currency::minorUnits((string) $plan->currency) === 0 && $newCents % 100 !== 0) {
                        return new WP_Error('dono_invalid_input', __('This currency does not support fractional amounts.', 'dono'), ['status' => 422]);
                    }
                    if ($sub) $sub->updateSubscriptionAmount($plan, $newCents);
                    $plan->amount_cents = $newCents;
                    // Keep the base-currency snapshot in step with the new amount.
                    $plan->base_amount_cents = $plan->fx_rate !== null
                        ? (int) round($newCents * (float) $plan->fx_rate)
                        : null;
                    $plan->save();
                    do_action('dono.recurring.plan_amount_changed', $plan);
                    break;

                case 'cancel':
                    $reason = isset($body['reason']) ? (string) $body['reason'] : null;
                    // Gateway cancel + winner-gated local side effects, so one
                    // cancellation email goes out even if the gateway's
                    // subscription.deleted webhook races this request.
                    $this->canceller->cancel($plan, $reason);
                    break;

                default:
                    return new WP_Error('dono_invalid_action', '', ['status' => 422]);
            }
        } catch (\Throwable $e) {
            // Gateway (or any downstream) failed; local state intentionally left
            // unchanged. Degrade to a clean 502 rather than a 500.
            error_log('[dono] portal recurring action failure: ' . $e->getMessage());
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

    public function receiptsList(): WP_REST_Response|WP_Error
    {
        $donorId = $this->session->currentDonorId();
        if (! $donorId) return new WP_Error('dono_unauthorized', '', ['status' => 401]);

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
            // Mint a short-lived download token for the donor's current
            // session. The /receipts/{id}/download endpoint is public-by-token
            // (mirrors the email-attached download flow), so we need a token
            // here too. 1-hour TTL keeps the URL useful for the page lifetime
            // without becoming a long-lived link if copied out of the portal.
            $token = $this->magicLinks->issue($donorId, 'download_receipt', (int) $r->id, 3600);
            $url   = rest_url('dono/v1/receipts/' . $r->id . '/download');
            $out[] = [
                'id'             => (int) $r->id,
                'receipt_number' => (string) $r->receipt_number,
                'renderer_id'    => (string) $r->renderer_id,
                'issued_at'      => $r->issued_at,
                'donation_id'    => $r->donation_id ? (int) $r->donation_id : null,
                'download_url'   => esc_url_raw(add_query_arg('token', $token, $url)),
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
        $donorId = $this->session->currentDonorId();
        if (! $donorId) return new WP_Error('dono_unauthorized', '', ['status' => 401]);

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
        $donorId = $this->session->currentDonorId();
        if (! $donorId) return new WP_Error('dono_unauthorized', '', ['status' => 401]);

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
                'granted'        => $row ? (bool) $row->granted : (bool) $p['default'],
                'occurred_at'    => $row->occurred_at ?? null,
                'has_record'     => $row !== null,
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
        // Channels + topics were a UI that nothing consumed (no mail layer reads
        // them); that UI was removed, so they are no longer persisted. Only the
        // wired preferences remain.
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

        $bundle = [
            'exported_at' => gmdate('c'),
            'donor' => [
                'id'           => (int) $donor->id,
                'email'        => $email,
                'first_name'   => $donor->first_name,
                'last_name'    => $donor->last_name,
                'phone'        => $phone,
                'country'      => $donor->country,
                'company'      => $donor->company,
                'created_at'   => $donor->created_at,
            ],
            'donations'      => $donationRows,
            'consents'       => $consentRows,
            'recurring'      => $planRows,
        ];

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

        $this->donorService->redact($donor);
        $this->session->destroy();

        return new WP_REST_Response(['ok' => true], 200);
    }
}
