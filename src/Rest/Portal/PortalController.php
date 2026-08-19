<?php

declare(strict_types=1);

namespace Dono\Rest\Portal;

use Dono\Analytics\ErrorLog;
use Dono\Async\AsyncDispatcher;
use Dono\Campaigns\Campaign;
use Dono\Donations\AntiSpamGuard;
use Dono\Donations\Donation;
use Dono\Donations\DonationQueries;
use Dono\Donations\DonationRepository;
use Dono\Donors\ConsentService;
use Dono\Donors\Donor;
use Dono\Donors\DonorRepository;
use Dono\Donors\DonorService;
use Dono\Donors\MagicLinkService;
use Dono\Donors\PendingSignupRepository;
use Dono\Donors\SignupRedemption;
use Dono\Donors\Portal\AnnualStatementBuilder;
use Dono\Donors\Portal\PortalSession;
use Dono\Foundation\Identity\IdentityHasher;
use Dono\Gateways\GatewayManager;
use Dono\Gateways\SubscriptionChangeNeedsApproval;
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
 * The donor portal API: magic-link sign-in, self-registration, and the signed-in
 * donor's own donations, recurring plans, receipts, profile and privacy actions.
 *
 * @since 1.0.0
 */
final class PortalController
{
    private const NAMESPACE = 'dono/v1';

    public const SEND_LINK_HOOK          = 'dono.async.send_portal_link';
    private const SEND_LINK_IP_MAX       = 10;
    private const SEND_LINK_IP_WINDOW    = 15 * MINUTE_IN_SECONDS;
    private const SEND_LINK_EMAIL_MAX    = 3;
    private const SEND_LINK_EMAIL_WINDOW = 5 * MINUTE_IN_SECONDS;

    /**
     * The inbox limit, spent at the moment a link is mailed rather than when it
     * is asked for. An address that reaches a mailbox but resolves to no donor
     * mails nothing, so it must cost that mailbox nothing.
     */
    private const SEND_LINK_MAILBOX_MAX    = 5;
    private const SEND_LINK_MAILBOX_WINDOW = 15 * MINUTE_IN_SECONDS;

    /**
     * An emailed sign-in link is a bearer credential: whoever reads the mailbox
     * later reads the portal. Long enough for a donor to open their mail, not
     * long enough to sit in an archive as a live key.
     */
    private const PORTAL_LINK_TTL = HOUR_IN_SECONDS;

    /** @since 1.0.0 */
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
        private AntiSpamGuard $spam,
        private PendingSignupRepository $pending,
        private \Dono\Donors\DonorAvatarUploader $avatarUploader,
        private \Dono\Donors\DonorAvatars $avatars,
    ) {
    }

    /** @since 1.0.0 */
    public function registerHooks(): void
    {
        // Action Scheduler spreads the enqueued args positionally, so accept 3.
        add_action(self::SEND_LINK_HOOK, [$this, 'handleSendLinkAsync'], 10, 3);
    }

    /** @since 1.0.0 */
    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/portal/exchange', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'exchange'],
            'permission_callback' => [$this, 'sameSiteOnly'],
            'args'                => ['token' => ['type' => 'string', 'required' => true]],
        ]);

        // Same guard as the exchange: these two write and mail without a
        // session, and a POST-only route is otherwise reachable by a plain
        // link, which is the cheapest way to deliver either of them.
        register_rest_route(self::NAMESPACE, '/portal/send-link', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'sendLink'],
            'permission_callback' => [$this, 'sameSiteOnly'],
            'args'                => [
                'email' => ['type' => 'string', 'required' => true],
                'token' => ['type' => 'string'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/portal/register', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'registerDonor'],
            'permission_callback' => [$this, 'sameSiteOnly'],
            'args'                => [
                'email'      => ['type' => 'string', 'required' => true],
                'first_name' => ['type' => 'string'],
                'last_name'  => ['type' => 'string'],
                'token'      => ['type' => 'string'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/portal/logout', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'logout'],
            // The portal JS sends X-Dono-Csrf on every call, so a cross-site
            // forged POST cannot sign the donor out.
            'permission_callback' => [$this, 'sessionWithCsrf'],
        ]);

        register_rest_route(self::NAMESPACE, '/portal/logout-everywhere', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'logoutEverywhere'],
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

        register_rest_route(self::NAMESPACE, '/portal/avatar', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'avatarUpload'],
                'permission_callback' => [$this, 'sessionWithCsrf'],
            ],
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [$this, 'avatarDelete'],
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

    /** @since 1.0.0 */
    private function privacySetting(string $key, $default)
    {
        $opt = get_option('dono_privacy', []);
        if (! is_array($opt)) return $default;
        return array_key_exists($key, $opt) ? $opt[$key] : $default;
    }

    /** @since 1.0.0 */
    public function session(): bool
    {
        return $this->session->currentDonorId() !== null;
    }

    /**
     * Writes only; defense in depth on top of the SameSite=Lax cookie.
     *
     * @since 1.0.0
     */
    public function sessionWithCsrf(WP_REST_Request $request): bool
    {
        $expected = $this->session->csrfToken();
        if ($expected === null || $expected === '') return false;
        $provided = (string) $request->get_header('X-Dono-Csrf');
        if ($provided === '') return false;
        return hash_equals($expected, $provided);
    }

    /**
     * Redeeming a link sets the session cookie, so a forged cross-site POST
     * would sign a visitor into whichever account the attacker's token names,
     * and every write endpoint then works inside it: /portal/me hands the
     * caller the CSRF token that guards the rest.
     *
     * A nonce cannot be the guard here. The portal page is served from a page
     * cache, so its markup carries no per-visitor value (PortalShortcode mints
     * an empty nonce for logged-out visitors for exactly that reason). What the
     * browser labels the request with survives caching, so that is what is
     * read. A request with neither header is not a browser and is left alone,
     * because internal dispatch has no cross-site meaning.
     *
     * @since 1.0.0
     */
    public function sameSiteOnly(WP_REST_Request $request): bool|WP_Error
    {
        $refused = new WP_Error(
            'dono_cross_site',
            __('Sign-in must start from this site.', 'dono-fundraising-platform'),
            ['status' => 403]
        );

        $fetchSite = strtolower(trim((string) $request->get_header('Sec-Fetch-Site')));
        if ($fetchSite !== '' && ! in_array($fetchSite, ['same-origin', 'same-site', 'none'], true)) {
            return $refused;
        }

        $origin = trim((string) $request->get_header('Origin'));
        if ($origin !== '' && ! $this->originIsOurs($origin)) {
            return $refused;
        }

        // WP honours _method and X-HTTP-Method-Override, so a plain link can
        // otherwise reach a route registered POST-only, and a top-level
        // navigation carries no Origin at all.
        $override = (string) ($request->get_query_params()['_method'] ?? '')
            . (string) $request->get_header('X-HTTP-Method-Override');
        if ($override !== '' && strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) === 'GET') {
            return $refused;
        }

        return true;
    }

    /** @since 1.0.0 */
    private function originIsOurs(string $origin): bool
    {
        $host = strtolower((string) wp_parse_url($origin, PHP_URL_HOST));
        if ($host === '') return false;

        // Every host WordPress itself answers on: site_url can differ from
        // home_url on a subdirectory install, and behind a proxy or a mapped
        // domain the browser's own host is the one it puts in Origin.
        $ours = array_filter([
            strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST)),
            strtolower((string) wp_parse_url(site_url(), PHP_URL_HOST)),
            strtolower((string) preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? ''))),
        ]);

        foreach ($ours as $ourHost) {
            // www and the apex are one site on a very common hosting setup, and
            // rest_url() resolves to home_url's host whichever of the two the
            // page was served from, so the portal's own fetch is the request
            // this pairing lets through. Only that one label is paired: a
            // sibling subdomain is a different site and stays refused.
            if ($host === $ourHost
                || $host === 'www.' . $ourHost
                || $ourHost === 'www.' . $host) {
                return true;
            }
        }

        return false;
    }

    /** @since 1.0.0 */
    public function exchange(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $token = (string) $request['token'];
        $session = $this->session->startFromToken($token);
        if (! $session) {
            return new WP_Error('dono_invalid_token', __('Sign-in link is invalid or expired.', 'dono-fundraising-platform'), ['status' => 401]);
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
     *
     * @since 1.0.0
     */
    public function sendLink(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $ok    = new WP_REST_Response(['ok' => true], 200);

        // Checked before anything else is read, so a caller that never loaded
        // the portal cannot spend a real donor's rate limit on their behalf. It
        // says nothing about the address, so it costs no enumeration cover.
        if ($err = $this->spam->verifyPortalToken((string) ($request['token'] ?? ''))) return $err;

        $email = trim((string) ($request['email'] ?? ''));

        if ($email === '' || ! is_email($email)) return $ok;

        if (! $this->consumeIpQuota()) return $ok;

        // Locked before the lookup so a hammered address can't reveal existence.
        if (! $this->consumeEmailQuota($email)) return $ok;

        // Enqueued for every address that gets this far, known or not, and the
        // lookup happens in the job. Doing it here would make the donor branch
        // do visibly more work than the unknown one, which is exactly what the
        // identical 200 exists to hide.
        $this->async->enqueue(self::SEND_LINK_HOOK, ['email' => $email]);

        return $ok;
    }

    /**
     * Self-registration, so somebody who has not donated can get into the
     * portal. Nothing here becomes a donor: anyone can type anyone's address,
     * so the claim waits in dono_pending_signups until the emailed link comes
     * back, and redeeming it is what creates the donor.
     *
     * The donor table is not read here, for the same reason sendLink() does not
     * read it: the two branches must not do visibly different amounts of work,
     * or the identical 200 is undone by the clock. Recording the claim is the
     * same work either way and says nothing about whether the address belongs
     * to a donor.
     *
     * @since 1.0.0
     */
    public function registerDonor(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $ok = new WP_REST_Response(['ok' => true], 200);

        // The one unauthenticated write on this controller, so it demands the
        // same proof the donation endpoint does.
        if ($err = $this->spam->verifyPortalToken((string) ($request['token'] ?? ''))) return $err;

        $email = trim((string) ($request['email'] ?? ''));
        if ($email === '' || ! is_email($email)) return $ok;

        if (! $this->consumeIpQuota()) return $ok;
        if (! $this->consumeEmailQuota($email)) return $ok;

        // Carried to the job, which mints this registration's own link and
        // hangs them on it. They are never written anywhere a second caller can
        // reach: the claim is one row per address and a name held there is a
        // name whoever posts next can steer.
        // Clamped here rather than where it is stored, because between the two
        // is the job queue: Action Scheduler refuses arguments over 8000
        // characters, and it refuses them by throwing inside the enqueue, which
        // is caught and logged. The registration would answer 200 having spent
        // the caller's quota and mailed nothing.
        $names = [];
        foreach (['first_name', 'last_name'] as $field) {
            $value = mb_substr(trim(sanitize_text_field((string) ($request[$field] ?? ''))), 0, 100);
            $names[$field] = $value !== '' ? $value : null;
        }

        $this->pending->put($email);

        $this->async->enqueue(self::SEND_LINK_HOOK, [
            'email'      => $email,
            'first_name' => $names['first_name'],
            'last_name'  => $names['last_name'],
        ]);

        return $ok;
    }

    /**
     * Action Scheduler executes do_action_ref_array($hook, array_values($args)),
     * so the enqueued ['email'=>.., 'first_name'=>.., 'last_name'=>..] arrives
     * as three positional params, not one array. Accept both shapes.
     *
     * @param array{email?:string, first_name?:?string, last_name?:?string}|string $args
     *
     * @since 1.0.0
     */
    public function handleSendLinkAsync(mixed $args = '', ?string $firstName = null, ?string $lastName = null): void
    {
        if (is_array($args)) {
            $firstName = $args['first_name'] ?? null;
            $lastName  = $args['last_name'] ?? null;
            $email     = (string) ($args['email'] ?? '');
        } else {
            $email = (string) $args;
        }
        if ($email === '' || ! is_email($email)) return;

        // Resolved here rather than in the request, so the request does the
        // same work for an address that is a donor and one that is not.
        $hash  = $this->hasher->emailHash($this->hasher->normalizeEmail($email));
        $donor = $this->donors->findByEmailHash($hash);

        if ($donor) {
            // Erasure is a decision, not a lapsed state: the same position
            // SignupRedemption takes below and issuePortalLink takes for staff.
            // An address the org was told to forget is not one to mail, and a
            // fresh token against an erased record is a credential for a person
            // who asked to stop existing here.
            if (($donor->redacted_at ?? null) !== null) return;

            // Already a donor, so any claim standing against this address is
            // moot: signing up for an address that has an account is a sign-in.
            $this->pending->deleteByEmailHash($hash);
            if (! $this->consumeMailboxQuota($email)) return;

            // Earlier links are left alone. This request proves nothing about
            // who made it, so it must not be able to destroy a credential it
            // did not create: support issues portal links too, and a donor who
            // clicks resend before opening the first mail is not attacking
            // anyone. What bounds an unclicked link is its hour, and a donor
            // who wants them all gone has sign out everywhere.
            $this->mailLink(
                $email,
                $this->magicLinks->issue((int) $donor->id, PortalSession::PORTAL_PURPOSE, null, self::PORTAL_LINK_TTL),
                trim(($donor->first_name ?? '') . ' ' . ($donor->last_name ?? '')),
                self::PORTAL_LINK_TTL
            );
            return;
        }

        // Not a donor, so the link carries the claim instead. No donor id
        // exists yet, so the token points at the claim through target_id, and
        // it carries the name this registration typed. Redemption applies the
        // name off the token it redeemed, so the only submission that can
        // decide what a link does is the one that minted it.
        $claim = $this->pending->findByEmailHash($hash);
        if (! $claim || ! $this->pending->isLive($claim)) return;

        if (! $this->consumeMailboxQuota($email)) return;

        $this->mailLink(
            $email,
            $this->magicLinks->issue(
                0,
                SignupRedemption::PURPOSE,
                (int) $claim->id,
                PendingSignupRepository::TTL_SECONDS,
                ['first_name' => $firstName, 'last_name' => $lastName]
            ),
            trim((string) $firstName . ' ' . (string) $lastName),
            PendingSignupRepository::TTL_SECONDS
        );
    }

    /**
     * One template serves two links with different lifetimes, so the lifetime
     * is a token rather than a sentence: a mail that names the wrong one tells
     * the donor to take their time with a key that is already dead.
     *
     * @since 1.0.0
     */
    private function mailLink(string $email, string $rawToken, string $name, int $ttlSeconds): void
    {
        $this->mailer->sendTemplate('magic_link', $email, [
            'donor_name'        => $name !== '' ? $name : $email,
            'organisation_name' => (string) get_bloginfo('name'),
            'portal_url'        => add_query_arg('token', $rawToken, $this->portalUrl()),
            'link_expiry'       => human_time_diff(0, $ttlSeconds),
        ]);
    }

    /**
     * Counted through the guard's atomic hit(), because get_transient followed
     * by set_transient lets concurrent callers all read the last allowed value
     * and all write it back.
     *
     * @since 1.0.0
     */
    private function consumeIpQuota(): bool
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');

        return $this->spam->hit('dono_send_link_ip_' . hash('sha256', $ip), self::SEND_LINK_IP_WINDOW)
            <= self::SEND_LINK_IP_MAX;
    }

    /**
     * Keyed by the address, which is the key the job resolves the donor by. A
     * key that collapses aliases where the lookup does not lets a stranger
     * spend a donor's allowance with an address that mails nobody, and the
     * donor is told their link is on its way. Hashed only to keep a plaintext
     * address out of the options table.
     *
     * A small counter rather than one flag: a single request must not be able
     * to close the window on the person the window is for.
     *
     * @since 1.0.0
     */
    private function consumeEmailQuota(string $email): bool
    {
        $key = 'dono_send_link_addr_'
            . substr($this->hasher->emailHash($this->hasher->normalizeEmail($email)), 0, 32);

        return $this->spam->hit($key, self::SEND_LINK_EMAIL_WINDOW) <= self::SEND_LINK_EMAIL_MAX;
    }

    /**
     * What stops this endpoint mailing a person on demand: one inbox answers to
     * unlimited addresses, so the flood limit belongs on the mailbox. Spent
     * here, in the job, at the moment a mail is actually issued, so aliases
     * that resolve to nobody cost the mailbox nothing.
     *
     * @since 1.0.0
     */
    private function consumeMailboxQuota(string $email): bool
    {
        $key = 'dono_send_link_mailbox_'
            . substr($this->hasher->emailHash($this->hasher->rateLimitMailbox($email)), 0, 32);

        $count = $this->spam->hit($key, self::SEND_LINK_MAILBOX_WINDOW);
        if ($count <= self::SEND_LINK_MAILBOX_MAX) return true;

        // Once per window per mailbox: a suppressed sign-in link is silent to
        // the donor asking for it, so the org needs somewhere to see it.
        if ($count === self::SEND_LINK_MAILBOX_MAX + 1) {
            ErrorLog::record(
                'portal.send_link',
                'Sign-in links to one mailbox hit the send limit; further links are suppressed for now.',
                ['mailbox' => substr($key, -32)]
            );
        }

        return false;
    }

    /** @since 1.0.0 */
    public function logout(): WP_REST_Response
    {
        $this->session->destroy();
        return new WP_REST_Response(['ok' => true], 200);
    }

    /** @since 1.0.0 */
    public function logoutEverywhere(): WP_REST_Response|WP_Error
    {
        $donorId = $this->session->currentDonorId();
        if ($donorId === null) {
            return new WP_Error('dono_unauthorized', __('Session expired.', 'dono-fundraising-platform'), ['status' => 401]);
        }

        return new WP_REST_Response(['ok' => true, 'ended' => $this->session->destroyAllFor($donorId)], 200);
    }

    /** @since 1.0.0 */
    public function me(): WP_REST_Response|WP_Error
    {
        $donorId = $this->session->currentDonorId();
        $donor = $donorId ? $this->donors->findById($donorId) : null;
        if (! $donor || $donor->redacted_at !== null) {
            // A redacted donor's session is invalid even when a link was
            // already exchanged: the row no longer represents a real person.
            return new WP_Error('dono_session_invalid', __('Session expired.', 'dono-fundraising-platform'), ['status' => 401]);
        }

        $name = trim(($donor->first_name ?? '') . ' ' . ($donor->last_name ?? ''));
        $currencyCfg = get_option('dono_currency_locale', []);
        $defaultCurrency = is_array($currencyCfg) && ! empty($currencyCfg['default_currency'])
            ? (string) $currencyCfg['default_currency']
            : 'USD';
        return new WP_REST_Response([
            'id'                  => (int) $donor->id,
            'name'                => $name !== '' ? $name : __('Friend', 'dono-fundraising-platform'),
            'first_name'          => (string) ($donor->first_name ?? ''),
            'last_name'           => (string) ($donor->last_name ?? ''),
            'country'             => (string) ($donor->country ?? ''),
            'total_donated_cents' => (int) $donor->total_donated_cents,
            // A donation taken in a currency the org had no rate for carries a
            // NULL base amount and contributes nothing to the lifetime figure,
            // so a donor could otherwise read "3 donations" next to a lifetime
            // total of zero with nothing explaining the gap.
            'unconverted_count'   => $this->unconvertedDonationCount((int) $donor->id),
            'donations_count'     => (int) $donor->donations_count,
            'first_donation_at'   => $donor->first_donation_at,
            'last_donation_at'    => $donor->last_donation_at,
            'primary_currency'    => $defaultCurrency,
            'csrf'                => (string) ($this->session->csrfToken() ?? ''),
            'consents_pending'    => $this->staleConsentCount((int) $donor->id),
        ], 200);
    }

    /**
     * Donations of this donor's that no lifetime total can include.
     *
     * @since 1.0.0
     */
    private function unconvertedDonationCount(int $donorId): int
    {
        $row = DonationQueries::donationsOnly(DB::table('dono_donations'))
            ->whereIn('status', ['paid', 'partial_refund'])
            ->where('donor_id', $donorId)
            ->selectRaw(DonationQueries::unconvertedExpr() . ' AS n')
            ->get();

        return (int) ($row['n'] ?? 0);
    }

    /** @since 1.0.0 */
    public function donationsList(): WP_REST_Response|WP_Error
    {
        $donor = $this->requireDonor();
        if ($donor instanceof WP_Error) return $donor;
        $donorId = (int) $donor->id;

        // donationsOnly, not live: a ticket order rides the same table with
        // kind='order' and would read to the donor as a donation they made,
        // which the lifetime figure above it excludes.
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
                // What came back, so a row can say why it counts for less than
                // it reads towards the lifetime total above it, which is net.
                'refunded_cents'    => (int) ($d->refunded_cents ?? 0),
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

    /** @since 1.0.0 */
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
                    // Net, not gross: amount_cents folds the covered fee in and
                    // the form re-adds the fee on top of the prefill, so gross
                    // would double-count last time's fee.
                    $net = (int) $d->amount_cents - min((int) $d->amount_cents, max(0, (int) ($d->fee_covered_cents ?? 0)));
                    // The currency travels with the figure. Minor units are not
                    // comparable across currencies, so a bare 500000 read as the
                    // form's own currency turns 5,000 yen into 5,000 dollars.
                    $giveAgainUrl = add_query_arg([
                        'dono_amount'    => $net,
                        'dono_currency'  => (string) $d->currency,
                        'dono_frequency' => $d->frequency,
                    ], $perma);
                }
            }
        }

        // Add-ons own records that hang off a donation, and the filter is how
        // those reach the portal without core knowing what they are.
        $payload = (array) apply_filters('dono.portal.donation', [
            'id'                => (int) $d->id,
            'reference'         => (string) $d->reference,
            'amount_cents'      => (int) $d->amount_cents,
            'fee_covered_cents' => (int) ($d->fee_covered_cents ?? 0),
            'refunded_cents'    => (int) ($d->refunded_cents ?? 0),
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

    /** @since 1.0.0 */
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

    /** @since 1.0.0 */
    public function recurring(): WP_REST_Response|WP_Error
    {
        $donor = $this->requireDonor();
        if ($donor instanceof WP_Error) return $donor;
        $donorId = (int) $donor->id;

        // Live only, like the donations and receipts lists. A test-mode plan is
        // the organization rehearsing, so showing it would offer the donor a
        // subscription to cancel that was never theirs.
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

    /** @since 1.0.0 */
    public function recurringAction(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        // Through donorPlan(), so ownership and the test-plan rule are checked
        // in one place rather than copied per route.
        $plan = $this->donorPlan($request);
        if ($plan instanceof WP_Error) return $plan;

        $body   = (array) ($request->get_json_params() ?? []);
        $action = (string) ($body['action'] ?? '');
        $now    = gmdate('Y-m-d H:i:s');

        // The guards, the gateway calls and the writes live in
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
        } catch (SubscriptionChangeNeedsApproval $e) {
            // Ahead of the RuntimeException arm below, which is its parent and
            // would otherwise swallow it and report a live plan as terminal.
            //
            // Not a failure: the processor took the request and is waiting on
            // the donor. Local state stays as it is, because writing the new
            // amount would tell the donor a change had happened that their card
            // would not agree with.
            return new WP_Error(
                'dono_change_needs_approval',
                __('Your payment provider needs you to approve this change before it takes effect. Nothing has changed yet.', 'dono-fundraising-platform'),
                ['status' => 409, 'approve_url' => $e->approveUrl]
            );
        } catch (\InvalidArgumentException $e) {
            return new WP_Error('dono_invalid_input', $e->getMessage(), ['status' => 422]);
        } catch (\RuntimeException $e) {
            return new WP_Error('dono_plan_terminal', $e->getMessage(), ['status' => 422]);
        } catch (\Throwable $e) {
            // Local state is deliberately left unchanged when the gateway or
            // anything downstream fails.
            ErrorLog::record('portal.recurring', $e->getMessage());
            return new WP_Error(
                'dono_gateway_error',
                __('We could not complete this change with the payment provider. Please try again in a moment.', 'dono-fundraising-platform'),
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
     * The dunning email points the donor here, and a declined renewal is
     * usually an expired card, which retrying cannot fix.
     *
     * @since 1.0.0
     */
    public function startPaymentMethodUpdate(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $plan = $this->donorPlan($request);
        if ($plan instanceof WP_Error) return $plan;

        $gateway = $this->gateways->get((string) $plan->gateway);
        if (! $gateway instanceof SupportsPaymentMethodUpdate) {
            return new WP_Error(
                'dono_not_supported',
                __('This donation\'s payment method cannot be changed here. Please contact us and we will help.', 'dono-fundraising-platform'),
                ['status' => 422]
            );
        }

        try {
            $session = $gateway->startPaymentMethodUpdate($plan);
        } catch (\Throwable $e) {
            ErrorLog::record('portal.payment_method', $e->getMessage());
            return new WP_Error(
                'dono_gateway_error',
                __('We could not reach the payment provider. Please try again in a moment.', 'dono-fundraising-platform'),
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
     * The browser confirmed the setup against the processor, so what arrives
     * here is only an identifier for it: no card detail passes through this
     * site.
     *
     * @since 1.0.0
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
                __('The new card could not be saved. Please try again in a moment.', 'dono-fundraising-platform'),
                ['status' => 502]
            );
        }

        do_action('dono.recurring.payment_method_updated', $plan);

        return new WP_REST_Response(['ok' => true], 200);
    }

    /**
     * The named plan, only if it belongs to the donor whose session this is.
     *
     * @since 1.0.0
     */
    private function donorPlan(WP_REST_Request $request): RecurringPlan|WP_Error
    {
        $donor = $this->requireDonor();
        if ($donor instanceof WP_Error) return $donor;

        $plan = RecurringPlan::query()->find('id', (int) $request['id']);
        // Ownership is not the only gate: a test plan is not listed, so it must
        // not be actionable either.
        if (! $plan || (int) $plan->donor_id !== (int) $donor->id || $plan->is_test) {
            return new WP_Error('dono_not_found', '', ['status' => 404]);
        }

        return $plan;
    }

    /** @since 1.0.0 */
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
            // No token here: the portal asks /receipts/{id}/download-url at
            // click time, so minting one per row would issue up to two hundred
            // unauthenticated receipt credentials nobody ever uses.
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
     * A fresh token at click time, so a portal receipt link never opens
     * expired. Gated on the session and the donor's own receipt.
     *
     * @since 1.0.0
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
            return new WP_Error('dono_receipt_not_found', __('Receipt not found.', 'dono-fundraising-platform'), ['status' => 404]);
        }

        $token = $this->magicLinks->issue($donorId, 'download_receipt', $receiptId, 3600);
        $url   = add_query_arg('token', $token, rest_url('dono/v1/receipts/' . $receiptId . '/download'));
        return new WP_REST_Response(['url' => esc_url_raw($url)], 200);
    }

    /** @since 1.0.0 */
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
            return new WP_Error('dono_no_donations', __('No donations found for that year.', 'dono-fundraising-platform'), ['status' => 404]);
        }

        // Streamed directly, so the REST server does not JSON-encode the binary
        // body.
        $filename = sprintf('dono-annual-%d.pdf', $year);
        $route    = $request->get_route();
        add_filter('rest_pre_serve_request', function (bool $served, $result, $req, $server) use ($route, $pdf, $filename) {
            if ((string) $req->get_route() !== $route) return $served;
            $server->send_header('Content-Type', 'application/pdf');
            $server->send_header('Content-Disposition', 'inline; filename="' . $filename . '"');
            $server->send_header('Cache-Control', 'private, no-cache, no-store, must-revalidate');
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $pdf is the binary PDF from AnnualStatementBuilder::build(), sent under its own application/pdf header; escaping it would corrupt the document.
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

    /** @since 1.0.0 */
    public function profileShow(): WP_REST_Response|WP_Error
    {
        $donorId = $this->session->currentDonorId();
        $donor   = $donorId ? $this->donors->findById($donorId) : null;
        if (! $donor || $donor->redacted_at !== null) return new WP_Error('dono_unauthorized', __('Session expired.', 'dono-fundraising-platform'), ['status' => 401]);

        return new WP_REST_Response([
            'email'      => (string) ($this->donorService->decryptEmail($donor) ?? ''),
            'phone'      => (string) ($this->donorService->decryptPhone($donor) ?? ''),
            'first_name' => (string) ($donor->first_name ?? ''),
            'last_name'  => (string) ($donor->last_name ?? ''),
            'country'    => (string) ($donor->country ?? ''),
            'company'    => (string) ($donor->company ?? ''),
            'avatar_url' => $this->avatars->uploadedUrl($donor),
        ], 200);
    }

    /** @since 1.0.0 */
    public function profileUpdate(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $donorId = $this->session->currentDonorId();
        $donor   = $donorId ? $this->donors->findById($donorId) : null;
        if (! $donor || $donor->redacted_at !== null) return new WP_Error('dono_unauthorized', __('Session expired.', 'dono-fundraising-platform'), ['status' => 401]);

        $body  = (array) ($request->get_json_params() ?? []);
        $patch = [];
        foreach (['first_name', 'last_name', 'country', 'company', 'phone'] as $f) {
            if (array_key_exists($f, $body)) $patch[$f] = $body[$f];
        }
        // editProfile, not refreshProfile: donors own their record and can
        // overwrite populated values. refreshProfile's lock-on-first-write is
        // the donation-flow back-fill, not a portal edit.
        $this->donorService->editProfile($donor, $patch);
        return $this->profileShow();
    }

    /** @since 1.0.0 */
    public function avatarUpload(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $donor = $this->requireDonor();
        if ($donor instanceof WP_Error) return $donor;

        $files = $request->get_file_params();
        $file  = $files['file'] ?? null;
        if (! is_array($file)) {
            // Over post_max_size, PHP throws the whole body away before this
            // runs: no file, no fields, no error code. The only trace is a
            // content length larger than the server would accept, so without
            // this the donor is told nothing was sent when in fact too much was.
            $sent = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
            $max  = wp_convert_hr_to_bytes((string) ini_get('post_max_size'));
            if ($max > 0 && $sent > $max) {
                return new WP_Error(
                    'dono_upload_too_large',
                    sprintf(
                        /* translators: %s: file size, e.g. "2 MB". */
                        __('That picture is too large. The most this site takes is %s.', 'dono-fundraising-platform'),
                        size_format(\Dono\Donors\DonorAvatarUploader::maxBytes())
                    ),
                    ['status' => 413]
                );
            }

            return new WP_Error('dono_upload_missing', __('No picture was sent.', 'dono-fundraising-platform'), ['status' => 400]);
        }

        $result = $this->avatarUploader->store($donor, $file);
        if ($result instanceof WP_Error) return $result;

        return $this->profileShow();
    }

    /** @since 1.0.0 */
    public function avatarDelete(): WP_REST_Response|WP_Error
    {
        $donor = $this->requireDonor();
        if ($donor instanceof WP_Error) return $donor;

        $this->avatarUploader->remove($donor);

        return $this->profileShow();
    }

    /** @since 1.0.0 */
    public function preferencesShow(): WP_REST_Response|WP_Error
    {
        $donor = $this->requireDonor();
        if ($donor instanceof WP_Error) return $donor;
        $donorId = (int) $donor->id;

        $flags = (array) ($this->donors->findById($donorId)?->flags ?? []);
        $prefs = is_array($flags['prefs'] ?? null) ? $flags['prefs'] : [];

        return new WP_REST_Response(self::normalizePrefs($prefs), 200);
    }

    /** @since 1.0.0 */
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

    /** @since 1.0.0 */
    public function consentsShow(): WP_REST_Response|WP_Error
    {
        $donor = $this->requireDonor();
        if ($donor instanceof WP_Error) return $donor;

        return new WP_REST_Response($this->shapeConsents((int) $donor->id), 200);
    }

    /**
     * `stale` is true when the stored version is behind the purpose version.
     *
     * @since 1.0.0
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
                // The record is the truth: `default` is the form's
                // pre-selection at the point of collection, not a subscription
                // the donor holds, so a box ticked from it would claim one
                // nothing honours.
                'granted'        => $row !== null && (bool) $row->granted,
                'occurred_at'    => $row->occurred_at ?? null,
                'has_record'     => $row !== null,
            ];
        }

        // A form may define its own consent purposes and the create path
        // records them. The org registry alone would leave those invisible, so
        // the donor could not withdraw what they agreed to.
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
                // it may be gone, so the key is humanized rather than shown raw.
                'label' => ucfirst(str_replace(['_', '-'], ' ', (string) $key)),
                'description'    => '',
                // Nothing off the registry can be required, or it would be a
                // consent the donor cannot withdraw.
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

    /** @since 1.0.0 */
    private function staleConsentCount(int $donorId): int
    {
        $count = 0;
        foreach ($this->shapeConsents($donorId) as $row) {
            if (! empty($row['stale'])) $count++;
        }
        return $count;
    }

    /** @since 1.0.0 */
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
        // so they can withdraw it even when it lives on a form rather than the
        // org registry. This widens what can be revoked, never what can be
        // granted.
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
            // A true no-op is skipped, but re-affirming an unchanged grant
            // against a newer purpose version still records, clearing "stale".
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
     * The donor this session belongs to, or 401. Every authenticated endpoint
     * goes through here rather than reading the session id directly: erasure
     * deletes the magic-link tokens, but a cookie minted before it would
     * otherwise keep working for its full life.
     *
     * @since 1.0.0
     */
    private function requireDonor(): Donor|WP_Error
    {
        $donorId = $this->session->currentDonorId();
        $donor   = $donorId ? $this->donors->findById($donorId) : null;
        if (! $donor || $donor->redacted_at !== null) return new WP_Error('dono_unauthorized', __('Session expired.', 'dono-fundraising-platform'), ['status' => 401]);
        return $donor;
    }

    /** @since 1.0.0 */
    private function portalUrl(): string
    {
        return (new \Dono\Donors\Portal\PortalPage())->url();
    }

    /** @since 1.0.0 */
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
     * GDPR right of access.
     *
     * @since 1.0.0
     */
    public function dataExport(): WP_REST_Response|WP_Error
    {
        if (! $this->privacySetting('allow_data_export', true)) {
            return new WP_Error(
                'dono_export_disabled',
                __('Data export is disabled by the organization.', 'dono-fundraising-platform'),
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
        // this donor", and the donor's right of access is to that same set. Two
        // definitions of one legal obligation is one too many, so this asks for
        // the canonical one and only falls back when it cannot answer.
        $bundle = $this->metrics->exportData((int) $donor->id) ?? [
            'donor'     => ['id' => (int) $donor->id, 'email' => $email],
            'donations' => $donationRows,
            'consents'  => $consentRows,
            'recurring' => $planRows,
        ];
        $bundle = self::withoutStaffNotes($bundle);
        $bundle['exported_at'] = gmdate('c');

        $json     = wp_json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $filename = sprintf('dono-my-data-%d-%s.json', $donor->id, gmdate('Y-m-d'));

        // Streamed as an attachment, so the donor's browser saves a file
        // instead of receiving the REST envelope.
        add_filter('rest_pre_serve_request', function (bool $served, $result, $req, $server) use ($json, $filename) {
            if ((string) $req->get_route() !== '/dono/v1/portal/data-export') return $served;
            $server->send_header('Content-Type', 'application/json; charset=utf-8');
            $server->send_header('Content-Disposition', 'attachment; filename="' . $filename . '"');
            $server->send_header('Cache-Control', 'private, no-cache, no-store, must-revalidate');
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $json is wp_json_encode() output, already escaped for the JSON grammar, sent under its own application/json header; escaping it again would corrupt the file.
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
     * Staff notes are for staff.
     *
     * The org-side export carries them, decrypted, with the name and role of
     * whoever wrote each one. That is the organization's own working record of
     * a donor, kept so its people can talk to each other, and it is not handed
     * to the person it discusses.
     *
     * @param  array<string,mixed> $bundle
     * @return array<string,mixed>
     *
     * @since 1.0.0
     */
    private static function withoutStaffNotes(array $bundle): array
    {
        unset($bundle['notes']);

        return $bundle;
    }


    /**
     * GDPR right to erasure. Soft-redact: zeroes PII but keeps donation totals
     * for tax and audit.
     *
     * @since 1.0.0
     */
    public function forget(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (! $this->privacySetting('allow_account_delete', true)) {
            return new WP_Error(
                'dono_delete_disabled',
                __('Account deletion is disabled by the organization.', 'dono-fundraising-platform'),
                ['status' => 403]
            );
        }
        $donor = $this->requireDonor();
        if ($donor instanceof WP_Error) return $donor;

        if (strtoupper((string) $request['confirm']) !== 'DELETE') {
            return new WP_Error(
                'dono_invalid_confirmation',
                __('Type DELETE to confirm.', 'dono-fundraising-platform'),
                ['status' => 422]
            );
        }

        try {
            $this->donorService->redact($donor);
        } catch (\Throwable $e) {
            // Erasure cancels the donor's live recurring plans first, on
            // purpose: wiping the donor while a subscription keeps billing is
            // worse than not wiping them. A gateway that is unreachable, that
            // answers with a 500, or that times out must not surface as a fatal
            // with nothing for the donor to do next.
            ErrorLog::record('portal.forget', $e->getMessage(), ['donor_id' => (int) $donor->id]);

            return new WP_Error(
                'dono_erasure_blocked',
                __('We could not stop your recurring donation with the payment provider, so your account has not been deleted yet. Please contact the organization and they will finish this for you.', 'dono-fundraising-platform'),
                ['status' => 409]
            );
        }

        $this->session->destroy();

        return new WP_REST_Response(['ok' => true], 200);
    }
}
