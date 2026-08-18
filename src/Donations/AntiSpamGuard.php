<?php

declare(strict_types=1);

namespace Dono\Donations;

use Dono\Foundation\Config\SystemSetting;
use Dono\Foundation\Helpers\Money;
use Dono\Foundation\Identity\IdentityHasher;
use Dono\Foundation\Plugin;
use Dono\Gateways\GatewayManager;
use Dono\Gateways\SettlesOutOfBand;
use Dono\Gateways\TestMode;
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
    private const TOKEN_WINDOW_DAYS  = 30;
    private const MIN_AMOUNT_CENTS   = 100;

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
     * Rate limits relax under the org-wide test-mode switch: test submissions
     * move no real money, and automation bursts through the production caps.
     *
     * @since 1.0.0
     */
    private function inGlobalTestMode(): bool
    {
        if ($this->testMode !== null) {
            return $this->testMode->forForm(null);
        }
        $cfg = get_option('dono_gateway_config', []);
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

    /** @since 1.0.0 */
    public function checkHoneypot(string $value): ?WP_Error
    {
        if ($value === '') return null;
        return new WP_Error('dono_invalid_submission', __('Submission rejected.', 'dono-fundraising-platform'), ['status' => 400]);
    }

    /** @since 1.0.0 */
    public function verifyFormToken(string $token, int $formId = 0): ?WP_Error
    {
        return $this->check($token, (string) $formId);
    }

    /** @since 1.0.0 */
    private function check(string $token, string $scope): ?WP_Error
    {
        $generic = new WP_Error('dono_invalid_submission', __('Please refresh the page and try again.', 'dono-fundraising-platform'), ['status' => 400]);

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
        if ($bucket > $current || $bucket < $current - self::TOKEN_WINDOW_DAYS) {
            return $generic;
        }

        return null;
    }

    /** @since 1.0.0 */
    private function currentBucket(): int
    {
        return (int) floor(time() / DAY_IN_SECONDS);
    }

    /** @since 1.0.0 */
    public function consumeIpQuota(): ?WP_Error
    {
        if ($this->inGlobalTestMode()) return null;

        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        if ($this->hit('dono_donate_ip_' . hash('sha256', $ip), self::IP_WINDOW) <= self::IP_MAX) {
            return null;
        }

        return new WP_Error(
            'dono_rate_limited',
            __('Too many attempts. Please try again in a few minutes.', 'dono-fundraising-platform'),
            ['status' => 429]
        );
    }

    /** @since 1.0.0 */
    public function consumeEmailQuota(string $email): ?WP_Error
    {
        if ($email === '') return null;
        if ($this->inGlobalTestMode()) return null;

        $hash = $this->hasher->emailHash($this->hasher->normalizeEmail($email));
        $key  = 'dono_donate_email_' . substr($hash, 0, 32);
        if ($this->hit($key, self::EMAIL_WINDOW) <= self::EMAIL_MAX) {
            return null;
        }

        return new WP_Error(
            'dono_rate_limited',
            __('Too many recent attempts for this email. Please try again later.', 'dono-fundraising-platform'),
            ['status' => 429]
        );
    }

    /**
     * Proof that this submission continues one specific never-funded donation,
     * which spends that attempt tree's own budget instead of the email quota.
     *
     * The relief hangs off a server-minted per-donation secret, never off a
     * property of the email address: an attacker cannot mint one, and a row
     * that has seen money can never buy one.
     *
     * Returns null on any refusal, and the caller falls back to the email
     * quota, so a refusal here is never itself an error the donor sees.
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
        $key = 'dono_donate_retry_' . substr(hash('sha256', $group), 0, 32);
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
     * registered through `dono.gateways.register` closes the same hole by
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
     * Count this attempt and answer how many the window has now seen.
     *
     * Incremented before it is judged, and incremented atomically, because
     * read-then-write lets two requests both read the last allowed value and
     * both write it back: the limit is walked past exactly as fast as a
     * caller can open connections.
     *
     * The window is a fixed bucket in the key rather than a sliding expiry.
     * Re-setting a transient on every attempt pushes its expiry out, so a
     * caller who keeps trying holds their own lockout open forever, and the
     * person it strands is the donor whose card was declined twice.
     *
     * Public because every unauthenticated surface needs these two properties,
     * not only the donation endpoint. $base carries the caller's own namespace.
     *
     * $bucket names the bucket outright for a counter whose lifetime is a
     * server-minted moment rather than the wall clock. A wall-clock bucket
     * rolls underneath such a counter and hands its subject a fresh allowance
     * part way through. The value is written by the server and never moves, so
     * a caller still cannot push their own expiry out.
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

    /** @since 1.0.0 */
    public function checkMinAmount(int $cents): ?WP_Error
    {
        $min = (int) apply_filters('dono.spam.min_amount_cents', self::MIN_AMOUNT_CENTS);
        if ($min > 0 && $cents < $min) {
            return new WP_Error(
                'dono_amount_too_low',
                /* translators: %s: minimum donation amount formatted */
                sprintf(__('Minimum donation is %s.', 'dono-fundraising-platform'), Money::format($min)),
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
