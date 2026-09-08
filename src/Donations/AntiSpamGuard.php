<?php

declare(strict_types=1);

namespace FundKit\Donations;

use FundKit\Foundation\Config\SystemSetting;
use FundKit\Foundation\Helpers\Money;
use FundKit\Foundation\Http\ClientIp;
use FundKit\Foundation\Identity\IdentityHasher;
use FundKit\Foundation\Plugin;
use FundKit\Gateways\GatewayManager;
use FundKit\Gateways\SettlesOutOfBand;
use FundKit\Gateways\TestMode;
use WP_Error;

/**
 * Anti-spam gates for the public donation endpoint. Each check returns null on
 * pass or a WP_Error on fail.
 *
 * @since 1.0.0
 */
final class AntiSpamGuard
{
    private const SETTING_SECRET = 'form_signing_secret_v1';

    private const IP_MAX             = 10;
    private const IP_WINDOW          = 900;
    private const EMAIL_MAX          = 3;
    // Anyone can type anyone's address, so this counter is addressable by a
    // stranger and spending it refuses the person who owns it. The window is
    // what bounds that: it decides how long an outsider can hold a named donor
    // out of donating, and five minutes is short enough that a donor who tries
    // again gets through. The per-IP cap is what actually bounds volume.
    private const EMAIL_WINDOW       = 300;
    // The token is a coarse day bucket, not a per-render timestamp, so a form
    // served from a page cache still validates. Replay inside the window is
    // bounded by the IP/email rate limits.
    //
    // Thirty days is long for a scraped token, and shortening it is the
    // obvious tightening. It is not taken here: a CDN serving a campaign page
    // that has not been rebuilt for weeks is a real deployment, and a form
    // whose token has expired refuses the donor with nothing they can do about
    // it. Sites that know their own cache horizon set it through
    // fundkit.spam.token_window_days.
    private const TOKEN_WINDOW_DAYS  = 30;
    private const MIN_AMOUNT_CENTS   = 100;

    // Room for a test run and an automated suite, still a ceiling.
    private const TEST_MODE_RELIEF   = 10;

    // A donor who backs out of one gateway and picks another is still making
    // one donation, so the attempts that follow spend the first attempt's own
    // budget rather than a fresh slot of the email quota. Half of EMAIL_WINDOW,
    // so a tree can never outlive the slot that bought it.
    private const RETRY_MAX          = 2;
    private const RETRY_TTL          = 1800;

    /** @since 1.0.0 */
    public function __construct(private IdentityHasher $hasher, private ?TestMode $testMode = null)
    {
    }

    /**
     * The cap in force, raised but never removed under the org-wide test-mode
     * switch.
     *
     * Test submissions move no real money and automation bursts through the
     * production caps, so the caps give way. They do not disappear: the same
     * switch registers a gateway that confirms in the request, and a confirmed
     * donation mails a rendered receipt to whatever address the caller typed.
     * Removed, that is an unmetered mail cannon aimed at strangers from the
     * org's own sending domain, and a site left in test mode by accident is
     * the ordinary way to arrive there. Sending reputation is the one thing
     * the test-data purge cannot undo.
     *
     * @since 1.0.0
     */
    private function relaxed(int $max): int
    {
        return $this->inGlobalTestMode() ? $max * self::TEST_MODE_RELIEF : $max;
    }

    /** @since 1.0.0 */
    private function inGlobalTestMode(): bool
    {
        if ($this->testMode !== null) {
            return $this->testMode->forForm(null);
        }
        $cfg = get_option('fundkit_gateway_config', []);
        return is_array($cfg) && ! empty($cfg['test_mode']);
    }

    /**
     * The form id is folded into the signature, so a token minted on one form
     * cannot be replayed against another.
     *
     * @since 1.0.0
     */
    public function mintFormToken(int $formId = 0): string
    {
        return $this->signed((string) $formId);
    }

    /** @since 1.0.0 */
    private function signed(string $scope): string
    {
        $payload = (string) $this->currentBucket();
        $sig     = hash_hmac('sha256', $scope . '|' . $payload, $this->secret());

        return $payload . '.' . $sig;
    }

    /**
     * The same proof for surfaces that are not a donation form. Namespaced so
     * one surface's token is not accepted at another, and so a context can
     * never be mistaken for a form id, which is a bare integer.
     *
     * @since 1.0.0
     */
    public function mintToken(string $context): string
    {
        return $this->signed('ctx:' . $context);
    }

    /** @since 1.0.0 */
    public function verifyToken(string $token, string $context): ?WP_Error
    {
        return $this->check($token, 'ctx:' . $context);
    }

    /** @since 1.0.0 */
    public function mintPortalToken(): string
    {
        return $this->mintToken('portal');
    }

    /** @since 1.0.0 */
    public function verifyPortalToken(string $token): ?WP_Error
    {
        return $this->verifyToken($token, 'portal');
    }

    /**
     * Reject disallowed browser origins; WordPress’s permissive REST CORS would otherwise let
     * third-party pages spend visitors’ IP quotas. Allow absent Origin headers for server
     * callers and use allowed_http_origins for decoupled frontends.
     *
     * @since 1.0.0
     */
    public function checkOrigin(): ?WP_Error
    {
        $origin = get_http_origin();
        if (! is_string($origin) || $origin === '') {
            return null;
        }

        // This site, port included. Core's get_allowed_http_origins compares
        // hosts and drops the port, carrying a "@todo Preserve port?" where it
        // does, so a site served on an explicit port fails its own check and
        // every donor with it.
        $mine = array_filter([self::originOf(home_url()), self::originOf(site_url())]);
        if (in_array(self::originOf($origin), $mine, true)) {
            return null;
        }

        // Asked second and for what it adds: the allowed_http_origins filter,
        // which is where a decoupled front end declares itself.
        if (is_allowed_http_origin($origin)) {
            return null;
        }

        return new WP_Error(
            'fundkit_invalid_submission',
            __('Please refresh the page and try again.', 'fundraising-toolkit'),
            ['status' => 403]
        );
    }

    /**
     * scheme://host[:port], the whole of what makes two pages same-origin.
     *
     * @since 1.0.0
     */
    private static function originOf(string $url): string
    {
        $parts = wp_parse_url($url);
        if (empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }

        return strtolower($parts['scheme'] . '://' . $parts['host'])
            . (isset($parts['port']) ? ':' . (int) $parts['port'] : '');
    }

    /** @since 1.0.0 */
    public function checkHoneypot(string $value): ?WP_Error
    {
        if ($value === '') return null;
        return new WP_Error('fundkit_invalid_submission', __('Submission rejected.', 'fundraising-toolkit'), ['status' => 400]);
    }

    /**
     * Run site-specific checks after IP quota to bound third-party requests. Return a
     * donor-facing WP_Error to refuse submission.
     *
     * @param array<string,mixed> $submission the raw request body
     *
     * @since 1.0.0
     */
    public function preCheck(array $submission): ?WP_Error
    {
        $refusal = apply_filters('fundkit.spam.pre_check', null, $submission);

        return $refusal instanceof WP_Error ? $refusal : null;
    }

    /** @since 1.0.0 */
    public function verifyFormToken(string $token, int $formId = 0): ?WP_Error
    {
        return $this->check($token, (string) $formId);
    }

    /** @since 1.0.0 */
    private function check(string $token, string $scope): ?WP_Error
    {
        $generic = new WP_Error('fundkit_invalid_submission', __('Please refresh the page and try again.', 'fundraising-toolkit'), ['status' => 400]);

        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) return $generic;

        [$payload, $sig] = $parts;
        $expected = hash_hmac('sha256', $scope . '|' . $payload, $this->secret());
        if (! hash_equals($expected, $sig)) return $generic;

        if ((string) (int) $payload !== $payload) return $generic;
        $bucket  = (int) $payload;
        $current = $this->currentBucket();

        // A future bucket is a clock game; a past one inside the window is a
        // cached page.
        $days = max(1, (int) apply_filters('fundkit.spam.token_window_days', self::TOKEN_WINDOW_DAYS));
        if ($bucket > $current || $bucket < $current - $days) {
            return $generic;
        }

        return null;
    }

    /** @since 1.0.0 */
    private function currentBucket(): int
    {
        return (int) floor(time() / DAY_IN_SECONDS);
    }

    /**
     * The subject of the per-IP quota.
     *
     * REMOTE_ADDR unless the site has declared its own proxies, because a
     * forwarded header is written by whoever is speaking to us and believing
     * one unconditionally would let any caller mint a fresh quota per request
     * by changing a string. ClientIp holds that rule.
     *
     * IPv6 is bucketed by its /64 rather than its full address. A single
     * host is routinely routed a whole /64, so per-address counting hands
     * one machine 2^64 quotas, which is no quota at all. v4 keeps its
     * per-address bucket, where an address is the scarce thing.
     *
     * @since 1.0.0
     */
    private function quotaSubject(): string
    {
        $ip = ClientIp::resolve();
        if ($ip === '') {
            return 'unknown';
        }

        $packed = @inet_pton($ip);
        if ($packed !== false && strlen($packed) === 16) {
            // The routed prefix, so every address behind it names one counter.
            return 'v6/64:' . bin2hex(substr($packed, 0, 8));
        }

        return $ip;
    }

    /**
     * A counter base naming whoever is calling, under the caller's namespace.
     *
     * Exposed so a surface that has to count something other than "this
     * request" - a failure, say - keys it the same way, rather than each one
     * deriving an address and getting IPv6 wrong on its own.
     *
     * @since 1.0.0
     */
    public function subjectKey(string $namespace): string
    {
        return $namespace . '_' . hash('sha256', $this->quotaSubject());
    }

    /** @since 1.0.0 */
    public function consumeIpQuota(): ?WP_Error
    {
        return $this->consumeIpBudget('fundkit_donate_ip', self::IP_MAX, self::IP_WINDOW);
    }

    /**
     * A per-IP allowance under the caller's own namespace.
     *
     * Public because the donation endpoint is not the only unauthenticated
     * surface that can be made expensive. A route that calls out to a gateway
     * spends the site's own resources on the caller's schedule, and a blocking
     * request holds a worker for the whole round trip, so one cheap call
     * costing one expensive one is the shape that needs a ceiling.
     *
     * @since 1.0.0
     */
    public function consumeIpBudget(string $namespace, int $max, int $window): ?WP_Error
    {
        if ($this->hit($this->subjectKey($namespace), $window) <= $this->relaxed($max)) {
            return null;
        }

        return new WP_Error(
            'fundkit_rate_limited',
            __('Too many attempts. Please try again in a few minutes.', 'fundraising-toolkit'),
            ['status' => 429]
        );
    }

    /** @since 1.0.0 */
    public function consumeEmailQuota(string $email): ?WP_Error
    {
        if ($email === '') return null;

        // The mailbox, not the address: one inbox answers to unlimited
        // addresses, because every plus tag is a distinct address and on some
        // providers so is every placement of a dot. Counting addresses leaves
        // the cap open to anyone who can type a '+'. Rate limiting only, never
        // identity: emailHash over the normalised address is the UNIQUE key on
        // the donor table, and collapsing addresses there would merge two
        // people's giving history into one record.
        $hash = $this->hasher->emailHash($this->hasher->rateLimitMailbox($email));
        $key  = 'fundkit_donate_email_' . substr($hash, 0, 32);
        if ($this->hit($key, self::EMAIL_WINDOW) <= $this->relaxed(self::EMAIL_MAX)) {
            return null;
        }

        return new WP_Error(
            'fundkit_rate_limited',
            __('Too many recent attempts for this email. Please try again later.', 'fundraising-toolkit'),
            ['status' => 429]
        );
    }

    /**
     * Authorize a retry with a server-minted token for a never-funded donation. Refusals return
     * null and fall back to email quota.
     *
     * @param array{amount_cents:int,currency:string,frequency:string} $describes
     *   what this submission is for, which has to be the parent's own donation
     *
     * @return array{group: string, born: int, parent: string}|null
     *
     * @since 1.0.0
     */
    public function claimRetry(
        Donation $parent,
        string $parentEmailHash,
        string $rawStatusToken,
        string $email,
        ?int $formId,
        array $describes
    ): ?array {
        $storedToken = (string) $parent->status_token_hash;
        if ($rawStatusToken === '' || $storedToken === '') {
            return null;
        }
        if (! hash_equals($storedToken, hash('sha256', $rawStatusToken))) {
            return null;
        }

        if ($parent->status !== 'pending'
            || $parent->paid_at !== null
            || (int) $parent->refunded_cents !== 0) {
            return null;
        }

        // An accepted claim is stamped on the parent as retried_by, and a
        // pending row carrying that is read as a replaced attempt: it leaves the
        // admin list, the CSV export, the KPI counts and the donor's own
        // donation list. For an out-of-band gateway that row is the queue entry
        // the incoming transfer has to be matched against, and the donor is
        // quoting its reference.
        if ($this->settlesOutOfBand((string) $parent->gateway)) {
            return null;
        }

        if ((int) ($parent->form_id ?? 0) !== (int) ($formId ?? 0)) {
            return null;
        }

        // The relief is for one donation tried a second way, so the child has
        // to be that donation. An accepted claim stamps retried_by on the
        // parent, and the parent's own detail page renders it as the donation
        // that replaced this one: without this, a EUR 1.00 submission can carry
        // a $1000 attempt's token and the admin is given a false account of one
        // donor's decision, which is also what lets an unrelated donation hide
        // an earlier one. A donor who changes their mind about the amount is
        // making a new decision and falls back to the ordinary email quota.
        if ((int) ($describes['amount_cents'] ?? 0) !== (int) $parent->amount_cents
            || strtoupper((string) ($describes['currency'] ?? '')) !== strtoupper((string) $parent->currency)
            || (string) ($describes['frequency'] ?? '') !== (string) $parent->frequency) {
            return null;
        }

        // Without this one root would buy free rows for unlimited addresses.
        if ($parentEmailHash === '') {
            return null;
        }
        if (! hash_equals($parentEmailHash, $this->hasher->emailHash($this->hasher->normalizeEmail($email)))) {
            return null;
        }

        $retry = is_array($parent->flags ?? null) ? ($parent->flags['retry'] ?? null) : null;
        if (! is_array($retry)) {
            return null;
        }
        $group = is_string($retry['group'] ?? null) ? $retry['group'] : '';
        $born  = is_numeric($retry['born'] ?? null) ? (int) $retry['born'] : 0;
        if ($group === '' || $born <= 0) {
            return null;
        }

        // Measured from the root's birth, which every descendant inherits
        // verbatim, so a chain of individually recent hops cannot walk a tree
        // forward indefinitely.
        if (time() - $born > self::RETRY_TTL) {
            return null;
        }

        // Spent last, so a refusal above costs nothing. The bucket is the root's
        // birth, so every member of the tree at any depth and on any branch
        // names one counter that no wall-clock boundary can reset.
        $key = 'fundkit_donate_retry_' . substr(hash('sha256', $group), 0, 32);
        if ($this->hit($key, self::RETRY_TTL * 2, $born) > self::RETRY_MAX) {
            return null;
        }

        return [
            'group'  => $group,
            'born'   => $born,
            'parent' => (string) $parent->reference,
        ];
    }

    /**
     * Whether the gateway a row was created on takes the money out of band.
     *
     * Asked of the registry rather than of a list kept here, so a gateway
     * registered through `fundkit.gateways.register` closes the same hole by
     * implementing SettlesOutOfBand. A gateway that is no longer registered
     * cannot answer and its rows keep the ordinary relief: it can take no
     * further submission either way, and refusing on silence would spend a
     * donor's email quota over a gateway the org has since put away.
     *
     * @since 1.0.0
     */
    private function settlesOutOfBand(string $gatewayId): bool
    {
        $gateways = Plugin::instance()->container->get(GatewayManager::class);

        return $gateways->get($gatewayId) instanceof SettlesOutOfBand;
    }

    /**
     * Atomically count attempts before checking limits. Fixed buckets prevent retries extending
     * lockouts; $base namespaces callers. An explicit server-minted $bucket keeps an attempt’s
     * allowance from resetting at wall-clock boundaries.
     *
     * @since 1.0.0
     */
    public function hit(string $base, int $window, ?int $bucket = null): int
    {
        global $wpdb;

        $key  = $base . '_' . ($bucket ?? (int) floor(time() / $window));
        $name = '_transient_' . $key;

        // INSERT IGNORE rather than add_option, which consults the notoptions
        // cache and, when that cache says a row is absent, writes through
        // ON DUPLICATE KEY UPDATE and sets a live counter back to 1. With a
        // persistent object cache a losing concurrent first insert leaves
        // exactly that entry behind, which hands every cap here a free reset.
        // IGNORE is the one form that cannot overwrite: of any number of
        // concurrent first attempts one creates the row, the rest do nothing
        // and fall through to the UPDATE, which MySQL serialises.
        $created = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '1', 'off')",
            $name
        ));

        if ($created === false) {
            return PHP_INT_MAX;
        }

        if ($created === 0) {
            if (false === $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = option_value + 1 WHERE option_name = %s",
                $name
            ))) {
                return PHP_INT_MAX;
            }
        }

        wp_cache_delete($name, 'options');
        wp_cache_delete('notoptions', 'options');

        // Only so the existing transient GC reclaims the bucket; expiry itself
        // is structural, an old bucket is simply never named again.
        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')",
            '_transient_timeout_' . $key,
            (string) (time() + $window * 2)
        ));

        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            $name
        ));

        // A counter this cannot read is a limit it cannot enforce, and every
        // caller reads a low number as room to spare. Refusing is the only
        // answer that does not turn a database in trouble into an open door.
        return $count === null ? PHP_INT_MAX : (int) $count;
    }

    /**
     * Read a counter without spending it.
     *
     * hit() answers what this attempt has now used, which is the right shape
     * when the attempt itself is what counts. A limiter that counts only
     * failures has to ask before it knows whether this attempt is one, and the
     * asking must not itself become an attempt.
     *
     * Same key hit() writes, so the two see one counter.
     *
     * @since 1.0.0
     */
    public function peek(string $base, int $window, ?int $bucket = null): int
    {
        $key   = $base . '_' . ($bucket ?? (int) floor(time() / $window));
        $value = get_option('_transient_' . $key, null);

        return $value === null || $value === false ? 0 : (int) $value;
    }

    /** @since 1.0.0 */
    public function checkMinAmount(int $cents): ?WP_Error
    {
        $min = (int) apply_filters('fundkit.spam.min_amount_cents', self::MIN_AMOUNT_CENTS);
        if ($min > 0 && $cents < $min) {
            return new WP_Error(
                'fundkit_amount_too_low',
                /* translators: %s: minimum donation amount formatted */
                sprintf(__('Minimum donation is %s.', 'fundraising-toolkit'), Money::format($min)),
                ['status' => 400]
            );
        }
        return null;
    }

    /** @since 1.0.0 */
    private function secret(): string
    {
        $stored = SystemSetting::read(self::SETTING_SECRET);
        if (is_string($stored) && $stored !== '') return $stored;
        $secret = bin2hex(random_bytes(32));
        SystemSetting::write(self::SETTING_SECRET, $secret);
        return $secret;
    }
}
