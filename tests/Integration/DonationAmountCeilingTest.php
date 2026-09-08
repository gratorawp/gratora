<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Donations\Donation;
use WP_REST_Request;
use WP_REST_Response;

final class DonationAmountCeilingTest extends IntegrationTestCase
{
    private function post(int $cents): WP_REST_Response
    {
        $req = new WP_REST_Request('POST', '/fundkit/v1/donations');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode([
            'email'        => 'whale@example.test',
            'amount_cents' => $cents,
            'currency'     => 'USD',
            'gateway'      => 'offline',
        ]));
        return rest_do_request($req);
    }

    public function test_an_absurd_amount_never_reaches_the_handler(): void
    {
        $res = $this->post(1000000000000);

        $this->assertSame(400, $res->get_status());
        $this->assertSame('rest_invalid_param', $res->get_data()['code'] ?? null);
        $this->assertNull(
            Donation::query()->where('amount_cents', 1000000000000)->get(),
            'and no junk pending row is written'
        );
    }

    /** A large but real donation must still go through. */
    public function test_a_genuinely_large_gift_is_accepted(): void
    {
        $this->assertSame(201, $this->post(5000000)->get_status(), '50,000.00 is a normal major donation');
    }

    public function test_the_floor_still_applies(): void
    {
        $res = $this->post(1);

        $this->assertSame(400, $res->get_status());
        $this->assertSame('fundkit_amount_too_low', $res->get_data()['code'] ?? null);
    }
}
