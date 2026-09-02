<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Donations\AntiSpamGuard;
use FundKit\Foundation\Plugin;
use WP_Error;

/**
 * What the donation quotas count.
 *
 * A cap is only worth the scarcity of the thing it counts. Counting addresses
 * lets anyone who can type a '+' hold as many quotas as they like, and counting
 * whole IPv6 addresses hands one host the 2^64 it is routinely routed. Both
 * leave a limit that reads as enforced and bounds nothing.
 */
final class DonationQuotaSubjectTest extends IntegrationTestCase
{
    private ?string $remoteAddr = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->remoteAddr = $_SERVER['REMOTE_ADDR'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->remoteAddr === null) {
            unset($_SERVER['REMOTE_ADDR']);
        } else {
            $_SERVER['REMOTE_ADDR'] = $this->remoteAddr;
        }

        remove_all_filters('fundkit.spam.pre_check');
        parent::tearDown();
    }

    private function guard(): AntiSpamGuard
    {
        return Plugin::instance()->container->get(AntiSpamGuard::class);
    }

    /** Spend the email quota until it refuses, and answer how many got through. */
    private function drain(string $email): int
    {
        $allowed = 0;
        for ($i = 0; $i < 12; $i++) {
            if ($this->guard()->consumeEmailQuota($email) instanceof WP_Error) {
                return $allowed;
            }
            $allowed++;
        }

        return $allowed;
    }

    private function drainIp(): int
    {
        $allowed = 0;
        for ($i = 0; $i < 30; $i++) {
            if ($this->guard()->consumeIpQuota() instanceof WP_Error) {
                return $allowed;
            }
            $allowed++;
        }

        return $allowed;
    }

    public function test_plus_tags_do_not_buy_a_fresh_email_quota(): void
    {
        $user = 'quota-' . uniqid();

        $this->assertSame(3, $this->drain($user . '@example.test'));

        // The same inbox, spelled differently. It must find the cap already spent.
        $this->assertSame(
            0,
            $this->drain($user . '+charity@example.test'),
            'a plus tag must not mint a second quota for one mailbox'
        );
    }

    public function test_gmail_dots_do_not_buy_a_fresh_email_quota(): void
    {
        $user = 'quota' . str_replace('.', '', uniqid());

        $this->assertSame(3, $this->drain($user . '@gmail.com'));
        $this->assertSame(
            0,
            $this->drain($user[0] . '.' . substr($user, 1) . '@googlemail.com'),
            'a dot and the googlemail alias reach one inbox and must share one quota'
        );
    }

    public function test_two_real_people_keep_their_own_quotas(): void
    {
        $this->assertSame(3, $this->drain('ada-' . uniqid() . '@example.test'));
        $this->assertSame(
            3,
            $this->drain('grace-' . uniqid() . '@example.test'),
            'one donor spending their cap must never refuse another'
        );
    }

    public function test_a_routed_ipv6_prefix_is_one_quota(): void
    {
        $prefix = '2001:db8:' . dechex(random_int(0x1000, 0xffff)) . ':1';

        $_SERVER['REMOTE_ADDR'] = $prefix . '::1';
        $this->assertSame(10, $this->drainIp());

        // A different address the same host already owns.
        $_SERVER['REMOTE_ADDR'] = $prefix . ':dead:beef:cafe:2';
        $this->assertSame(
            0,
            $this->drainIp(),
            'every address in one routed /64 must name the same counter'
        );
    }

    public function test_separate_ipv6_prefixes_stay_separate(): void
    {
        $a = '2001:db8:' . dechex(random_int(0x1000, 0x7fff)) . ':1::1';
        $b = '2001:db8:' . dechex(random_int(0x8000, 0xffff)) . ':1::1';

        $_SERVER['REMOTE_ADDR'] = $a;
        $this->assertSame(10, $this->drainIp());

        $_SERVER['REMOTE_ADDR'] = $b;
        $this->assertSame(10, $this->drainIp(), 'two unrelated networks must not share a cap');
    }

    public function test_ipv4_is_still_counted_per_address(): void
    {
        $_SERVER['REMOTE_ADDR'] = '198.51.100.' . random_int(2, 254);
        $this->assertSame(10, $this->drainIp());

        $_SERVER['REMOTE_ADDR'] = '203.0.113.' . random_int(2, 254);
        $this->assertSame(10, $this->drainIp(), 'v4 addresses are the scarce thing and keep their own bucket');
    }

    public function test_pre_check_can_refuse_a_submission(): void
    {
        $this->assertNull($this->guard()->preCheck(['email' => 'a@example.test']));

        add_filter('fundkit.spam.pre_check', static function ($refusal, array $submission) {
            return ($submission['email'] ?? '') === 'bot@example.test'
                ? new WP_Error('site_captcha_failed', 'Please try again.', ['status' => 400])
                : $refusal;
        }, 10, 2);

        $this->assertNull($this->guard()->preCheck(['email' => 'donor@example.test']));

        $refusal = $this->guard()->preCheck(['email' => 'bot@example.test']);
        $this->assertInstanceOf(WP_Error::class, $refusal);
        $this->assertSame('site_captcha_failed', $refusal->get_error_code());
    }

    /** A filter that returns junk must not be read as a refusal, or as a pass it cannot express. */
    public function test_pre_check_ignores_a_non_error_return(): void
    {
        add_filter('fundkit.spam.pre_check', static fn () => 'no', 10, 2);

        $this->assertNull($this->guard()->preCheck(['email' => 'donor@example.test']));
    }
}
