<?php

declare(strict_types=1);

namespace Dono\Donations;

use Dono\Foundation\Config\SystemSetting;
use Dono\Foundation\Helpers\Money;
use Dono\Foundation\Identity\IdentityHasher;
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
    private const EMAIL_WINDOW       = 3600;
    // The token is a coarse day bucket, not a per-render timestamp, so a form
    // served from a page cache still validates. Replay inside the window is
    // bounded by the IP/email rate limits.
    private const TOKEN_WINDOW_DAYS  = 30;
    private const MIN_AMOUNT_CENTS   = 100;

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
        return new WP_Error('dono_invalid_submission', __('Submission rejected.', 'dono'), ['status' => 400]);
    }

    /** @since 1.0.0 */
    public function verifyFormToken(string $token, int $formId = 0): ?WP_Error
    {
        return $this->check($token, (string) $formId);
    }

    /** @since 1.0.0 */
    private function check(string $token, string $scope): ?WP_Error
    {
        $generic = new WP_Error('dono_invalid_submission', __('Please refresh the page and try again.', 'dono'), ['status' => 400]);

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
            __('Too many attempts. Please try again in a few minutes.', 'dono'),
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
            __('Too many recent attempts for this email. Please try again later.', 'dono'),
            ['status' => 429]
        );
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
     * @since 1.0.0
     */
    private function hit(string $base, int $window): int
    {
        global $wpdb;

        $key  = $base . '_' . (int) floor(time() / $window);
        $name = '_transient_' . $key;

        // add_option is an INSERT against option_name's unique index, so of any
        // number of concurrent first attempts exactly one creates the row and
        // the rest fall through to the UPDATE, which MySQL serialises.
        if (! add_option($name, '1', '', false)) {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = option_value + 1 WHERE option_name = %s",
                $name
            ));
            wp_cache_delete($name, 'options');
        }

        // Only so the existing transient GC reclaims the bucket; expiry itself
        // is structural, an old bucket is simply never named again.
        add_option('_transient_timeout_' . $key, (string) (time() + $window * 2), '', false);

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            $name
        ));
    }

    /** @since 1.0.0 */
    public function checkMinAmount(int $cents): ?WP_Error
    {
        $min = (int) apply_filters('dono.spam.min_amount_cents', self::MIN_AMOUNT_CENTS);
        if ($min > 0 && $cents < $min) {
            return new WP_Error(
                'dono_amount_too_low',
                /* translators: %s: minimum donation amount formatted */
                sprintf(__('Minimum donation is %s.', 'dono'), Money::format($min)),
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
