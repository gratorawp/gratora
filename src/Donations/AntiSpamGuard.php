<?php

declare(strict_types=1);

namespace Dono\Donations;

use Dono\Foundation\Config\SystemSetting;
use Dono\Foundation\Helpers\Money;
use Dono\Foundation\Identity\IdentityHasher;
use Dono\Gateways\TestMode;
use WP_Error;

/**
 * Anti-spam / anti-card-testing gates for the public donation endpoint.
 *
 * Layered checks: honeypot, HMAC-signed form token with a minimum render
 * time, per-IP rate cap, per-email cap, minimum amount. Each check returns
 * null on pass or a WP_Error on fail.
 *
 * @version 1.0.0
 */
final class AntiSpamGuard
{
    private const SETTING_SECRET = 'form_signing_secret_v1';

    private const IP_MAX             = 10;
    private const IP_WINDOW          = 900;       // 15 minutes
    private const EMAIL_MAX          = 3;
    private const EMAIL_WINDOW       = 3600;      // 1 hour
    // Token validity in whole days. The token is a coarse day bucket (not a
    // per-render timestamp) so it survives page caching: a cached form up to
    // this many days old still validates. Replay within the window is bounded
    // by the IP/email rate limits, which are the real card-testing defense.
    private const TOKEN_WINDOW_DAYS  = 30;
    private const MIN_AMOUNT_CENTS   = 100;

    public function __construct(private IdentityHasher $hasher, private ?TestMode $testMode = null)
    {
    }

    /**
     * True when the org-wide test-mode kill switch is on. Rate-limit checks
     * relax in that case because test submissions never move real money, and
     * automation (Playwright, demos) bursts through the production caps.
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
     * Coarse day-bucket signed with a per-install secret, echoed back on submit.
     * Bucketing (vs a per-render timestamp) keeps the token stable across a
     * page-cache window, so cached donation forms still submit. The form id is
     * folded into the signature so a token minted on one form can't be replayed
     * against another (verification recomputes with the submitted form id).
     */
    public function mintFormToken(int $formId = 0): string
    {
        $payload = (string) $this->currentBucket();
        $sig     = hash_hmac('sha256', $formId . '|' . $payload, $this->secret());
        return $payload . '.' . $sig;
    }

    /**
     * The donor portal signs up and signs in from a page that is public and
     * cacheable, exactly like a donation form, so it wants the same token. Real
     * form ids are positive, so a reserved negative context keeps a token
     * minted on a donation form from being replayed at the portal.
     */
    private const PORTAL_CONTEXT = -1;

    public function mintPortalToken(): string
    {
        return $this->mintFormToken(self::PORTAL_CONTEXT);
    }

    public function verifyPortalToken(string $token): ?WP_Error
    {
        return $this->verifyFormToken($token, self::PORTAL_CONTEXT);
    }

    public function checkHoneypot(string $value): ?WP_Error
    {
        if ($value === '') return null;
        return new WP_Error('dono_invalid_submission', __('Submission rejected.', 'dono'), ['status' => 400]);
    }

    public function verifyFormToken(string $token, int $formId = 0): ?WP_Error
    {
        $generic = new WP_Error('dono_invalid_submission', __('Please refresh the page and try again.', 'dono'), ['status' => 400]);

        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) return $generic;

        [$payload, $sig] = $parts;
        $expected = hash_hmac('sha256', $formId . '|' . $payload, $this->secret());
        if (! hash_equals($expected, $sig)) return $generic;

        if ((string) (int) $payload !== $payload) return $generic; // non-numeric bucket
        $bucket  = (int) $payload;
        $current = $this->currentBucket();

        // Valid for the current bucket and up to TOKEN_WINDOW_DAYS back, so a
        // cached page keeps working; reject future buckets (clock games).
        if ($bucket > $current || $bucket < $current - self::TOKEN_WINDOW_DAYS) {
            return $generic;
        }

        return null;
    }

    /** Whole-day bucket index (UTC) used to date the form token. */
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
