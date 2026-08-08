<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\AntiSpamGuard;
use Dono\Foundation\Plugin;

/**
 * The limiter counts every attempt, and counts it once.
 *
 * Read-then-write let two requests read the last allowed value and both write
 * it back, so the ceiling could be walked past as fast as connections could be
 * opened. The counter is incremented before it is judged now, and the
 * increment is a single statement.
 */
final class RateLimitAtomicityTest extends IntegrationTestCase
{
    private function guard(): AntiSpamGuard
    {
        return Plugin::instance()->container->get(AntiSpamGuard::class);
    }

    private function email(): string
    {
        return 'limiter-' . uniqid() . '@example.test';
    }

    /** EMAIL_MAX is 3, so the fourth attempt is the one that must be refused. */
    public function test_the_email_quota_refuses_the_attempt_past_the_limit(): void
    {
        $email = $this->email();

        for ($i = 1; $i <= 3; $i++) {
            $this->assertNull($this->guard()->consumeEmailQuota($email), "attempt {$i} should pass");
        }

        $refused = $this->guard()->consumeEmailQuota($email);
        $this->assertNotNull($refused);
        $this->assertSame('dono_rate_limited', $refused->get_error_code());
    }

    /** One mailbox running out must not spend another's allowance. */
    public function test_the_quota_is_per_mailbox(): void
    {
        $spent = $this->email();
        for ($i = 0; $i < 4; $i++) {
            $this->guard()->consumeEmailQuota($spent);
        }

        $this->assertNull($this->guard()->consumeEmailQuota($this->email()));
    }

    public function test_three_pass_and_the_rest_are_refused(): void
    {
        $email = $this->email();

        $refusals = 0;
        for ($i = 0; $i < 10; $i++) {
            if ($this->guard()->consumeEmailQuota($email) !== null) {
                $refusals++;
            }
        }

        $this->assertSame(7, $refusals);
    }

    /**
     * The counter is raised before it is read, so it keeps climbing past the
     * ceiling. Checking first and only counting an allowed attempt is what
     * makes the limit raceable: two requests read the last allowed value, both
     * decide they may proceed, and both write the same number back.
     *
     * This is the assertion that separates the two designs. Counting only
     * allowed attempts pins the stored value at the maximum forever.
     */
    public function test_refused_attempts_are_still_counted(): void
    {
        global $wpdb;
        $email = $this->email();

        for ($i = 0; $i < 6; $i++) {
            $this->guard()->consumeEmailQuota($email);
        }

        $stored = (int) $wpdb->get_var(
            "SELECT option_value FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_dono_donate_email_%'
             ORDER BY option_id DESC LIMIT 1"
        );

        $this->assertSame(6, $stored, 'every attempt counts, not just the allowed ones');
    }

    /**
     * The window is a fixed bucket, not an expiry pushed forward on every
     * attempt. A sliding expiry means someone hammering the endpoint holds
     * their own lockout open indefinitely, stranding the real donor behind it.
     */
    public function test_attempts_do_not_extend_their_own_window(): void
    {
        global $wpdb;
        $email = $this->email();

        $this->guard()->consumeEmailQuota($email);
        $first = (int) $wpdb->get_var(
            "SELECT option_value FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_timeout_dono_donate_email_%'
             ORDER BY option_id DESC LIMIT 1"
        );

        $this->guard()->consumeEmailQuota($email);
        $second = (int) $wpdb->get_var(
            "SELECT option_value FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_timeout_dono_donate_email_%'
             ORDER BY option_id DESC LIMIT 1"
        );

        $this->assertSame($first, $second, 'a second attempt must not push the expiry out');
    }

    public function test_an_empty_email_is_not_counted(): void
    {
        $this->assertNull($this->guard()->consumeEmailQuota(''));
    }
}
