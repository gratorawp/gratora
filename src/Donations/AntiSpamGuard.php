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

    public function __construct(private IdentityHasher $hasher, private ?TestMode $testMode = null)
    {
    }

    /**
     * Rate limits relax under the org-wide test-mode switch: test submissions
     * move no real money, and automation bursts through the production caps.
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
     */
    public function mintFormToken(int $formId = 0): string
    {
        return $this->signed((string) $formId);
    }

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
     */
    public function mintToken(string $context): string
    {
        return $this->signed('ctx:' . $context);
    }

    public function verifyToken(string $token, string $context): ?WP_Error
    {
        return $this->check($token, 'ctx:' . $context);
    }

    public function mintPortalToken(): string
    {
        return $this->mintToken('portal');
    }

    public function verifyPortalToken(string $token): ?WP_Error
    {
        return $this->verifyToken($token, 'portal');
    }

    public function checkHoneypot(string $value): ?WP_Error
    {
        if ($value === '') return null;
        return new WP_Error('dono_invalid_submission', __('Submission rejected.', 'dono'), ['status' => 400]);
    }

    public function verifyFormToken(string $token, int $formId = 0): ?WP_Error
    {
        return $this->check($token, (string) $formId);
    }

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

    private function currentBucket(): int
    {
        return (int) floor(time() / DAY_IN_SECONDS);
    }

    public function consumeIpQuota(): ?WP_Error
    {
        if ($this->inGlobalTestMode()) return null;

        $ip  = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $key = 'dono_donate_ip_' . hash('sha256', $ip);
        $count = (int) get_transient($key);
        if ($count >= self::IP_MAX) {
            return new WP_Error(
                'dono_rate_limited',
                __('Too many attempts. Please try again in a few minutes.', 'dono'),
                ['status' => 429]
            );
        }
        set_transient($key, $count + 1, self::IP_WINDOW);
        return null;
    }

    public function consumeEmailQuota(string $email): ?WP_Error
    {
        if ($email === '') return null;
        if ($this->inGlobalTestMode()) return null;

        $hash = $this->hasher->emailHash($this->hasher->normalizeEmail($email));
        $key  = 'dono_donate_email_' . substr($hash, 0, 32);
        $count = (int) get_transient($key);
        if ($count >= self::EMAIL_MAX) {
            return new WP_Error(
                'dono_rate_limited',
                __('Too many recent attempts for this email. Please try again later.', 'dono'),
                ['status' => 429]
            );
        }
        set_transient($key, $count + 1, self::EMAIL_WINDOW);
        return null;
    }

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

    private function secret(): string
    {
        $stored = SystemSetting::read(self::SETTING_SECRET);
        if (is_string($stored) && $stored !== '') return $stored;
        $secret = bin2hex(random_bytes(32));
        SystemSetting::write(self::SETTING_SECRET, $secret);
        return $secret;
    }
}
