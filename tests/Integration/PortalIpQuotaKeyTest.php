<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Donations\AntiSpamGuard;
use FundKit\Donors\PendingSignup;
use FundKit\Foundation\Plugin;
use WP_REST_Request;

/**
 * The per-caller quota is the only volume ceiling on the two portal routes
 * that write a row and mail a stranger's address, so how it names a caller is
 * the whole of it.
 *
 * Two ways to name one wrongly. Counting a full IPv6 address hands a host that
 * is routed a /64, which is the standard allocation, one counter per request.
 * Reading REMOTE_ADDR on a site behind a declared CDN gives every donor in the
 * world the same counter, so the fifth in fifteen minutes is refused a link
 * and told one is on its way.
 */
final class PortalIpQuotaKeyTest extends IntegrationTestCase
{
    /** Mirrors PortalController::SEND_LINK_IP_MAX, which is private. */
    private const IP_MAX = 4;

    private const EDGE = '162.158.1.1';

    protected function tearDown(): void
    {
        unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR']);
        parent::tearDown();
    }

    private function register(string $email): int
    {
        $req = new WP_REST_Request('POST', '/fundkit/v1/portal/register');
        $req->set_header('content-type', 'application/json');
        $req->set_header('Sec-Fetch-Site', 'same-origin');
        $req->set_header('Origin', home_url());
        $req->set_body((string) wp_json_encode([
            'email'      => $email,
            'first_name' => 'Flood',
            'token'      => Plugin::instance()->container->get(AntiSpamGuard::class)->mintPortalToken(),
        ]));

        return rest_do_request($req)->get_status();
    }

    private function rows(): int
    {
        return (int) PendingSignup::query()->count();
    }

    public function test_a_host_routed_a_slash_64_gets_one_allowance_not_one_per_address(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $_SERVER['REMOTE_ADDR'] = sprintf('2001:db8:1:1::%x', $i + 1);
            $this->assertSame(200, $this->register("flood{$i}@example.test"));
        }

        $rows = $this->rows();

        $this->assertGreaterThan(0, $rows, 'nothing was written at all, so the ceiling is untested');
        $this->assertLessThanOrEqual(
            self::IP_MAX,
            $rows,
            "one host wrote {$rows} signups by changing the low half of its address"
        );
    }

    public function test_a_different_prefix_is_a_different_caller(): void
    {
        for ($i = 0; $i < 8; $i++) {
            $_SERVER['REMOTE_ADDR'] = '2001:db8:1:1::9';
            $this->register("first{$i}@example.test");
        }

        $spent = $this->rows();
        $this->assertSame(self::IP_MAX, $spent, 'the first caller should have spent exactly its allowance');

        $_SERVER['REMOTE_ADDR'] = '2001:db8:2:2::9';
        $this->assertSame(200, $this->register('neighbour@example.test'));

        $this->assertGreaterThan(
            $spent,
            $this->rows(),
            'a caller on another prefix was refused for someone else\'s traffic'
        );
    }

    public function test_donors_behind_a_declared_edge_do_not_share_one_bucket(): void
    {
        $privacy = (array) get_option('fundkit_privacy', []);
        update_option('fundkit_privacy', $privacy + ['trusted_proxies' => ['cloudflare']]);

        $_SERVER['REMOTE_ADDR'] = self::EDGE;

        for ($i = 0; $i < 8; $i++) {
            $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.7';
            $this->register("regular{$i}@example.test");
        }

        $spent = $this->rows();
        $this->assertSame(self::IP_MAX, $spent, 'the busy donor should have spent exactly its allowance');

        // A second donor, same CDN, same second.
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.22';
        $this->assertSame(200, $this->register('someone-else@example.test'));

        $this->assertGreaterThan(
            $spent,
            $this->rows(),
            'every donor on the site shared one allowance because the edge was counted instead of them'
        );
    }
}
