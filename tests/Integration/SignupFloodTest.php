<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Donations\AntiSpamGuard;
use FundKit\Donors\PendingSignup;
use FundKit\Foundation\Plugin;
use WP_REST_Request;

/**
 * Registration writes a row and queues a mail without any session, so one
 * caller must not be able to fill the table with addresses nobody asked about.
 */
final class SignupFloodTest extends IntegrationTestCase
{
    /** Mirrors PortalController::SEND_LINK_IP_MAX, which is private. */
    private const IP_MAX = 4;

    private function token(): string
    {
        return Plugin::instance()->container->get(AntiSpamGuard::class)->mintPortalToken();
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
            'token'      => $this->token(),
        ]));

        return rest_do_request($req)->get_status();
    }

    private function rows(): int
    {
        return (int) PendingSignup::query()->count();
    }

    public function test_one_address_cannot_fill_the_table(): void
    {
        for ($i = 0; $i < 40; $i++) {
            $this->assertSame(200, $this->register("flood{$i}@example.test"), 'the route never leaks which address exists');
        }

        $rows = $this->rows();

        // Both bounds: a zero here would pass the cap while proving nothing.
        $this->assertGreaterThan(0, $rows, 'registration wrote nothing at all, so the cap is untested');
        $this->assertLessThanOrEqual(
            self::IP_MAX,
            $rows,
            "a single caller wrote {$rows} pending signups, over the per-address quota"
        );
    }
}
