<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\DonationRepository;
use Dono\Foundation\Plugin;
use WP_REST_Request;

/**
 * Capturing a PayPal order takes money, and the route is public because the
 * donor paying is not logged in. What makes it safe is the per-donation secret
 * the submit response handed to that browser, not the reference: references are
 * sequential, printed on receipts, and quoted in support email.
 *
 * Without the token, guessing DONO-2026-00007 is enough to make a stranger's
 * approved order charge, and PayPal has no CHECKOUT.ORDER.APPROVED handler here
 * to do it later, so the charge is one that would not otherwise have happened.
 */
final class PayPalCaptureOwnershipTest extends IntegrationTestCase
{
    /** @return array{0:string,1:string} reference and raw status token */
    private function donation(): array
    {
        $req = new WP_REST_Request('POST', '/dono/v1/donations');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode([
            'email'        => 'paypal-owner@example.test',
            'amount_cents' => 5000,
            'currency'     => 'USD',
            'gateway'      => 'offline',
            'frequency'    => 'one_time',
            'profile'      => ['first_name' => 'Pay', 'last_name' => 'Pal'],
        ]));
        $data = (array) rest_do_request($req)->get_data();
        $this->assertArrayHasKey('reference', $data, (string) wp_json_encode($data));

        // The gateway is switched on the row rather than asked for: offline is
        // what a test site can create, and the route only cares that the
        // donation says paypal.
        $repo     = Plugin::instance()->container->get(DonationRepository::class);
        $donation = $repo->findByReference((string) $data['reference']);
        $donation->gateway = 'paypal';
        $donation->save();

        return [(string) $data['reference'], (string) ($data['status_token'] ?? '')];
    }

    private function capture(string $reference, string $token): \WP_REST_Response|\WP_Error
    {
        $req = new WP_REST_Request('POST', '/dono/v1/gateways/paypal/capture');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode([
            'reference'    => $reference,
            'status_token' => $token,
        ]));

        return rest_do_request($req);
    }

    public function test_the_submit_response_hands_the_browser_a_token(): void
    {
        [, $token] = $this->donation();

        // If this is ever dropped from the response the route below cannot be
        // satisfied by the donor either, so the guard would have to come out.
        $this->assertNotSame('', $token);
    }

    public function test_a_guessed_reference_alone_cannot_take_money(): void
    {
        [$reference] = $this->donation();

        $res = $this->capture($reference, 'not-the-token');

        $this->assertGreaterThanOrEqual(400, $res->get_status());
    }

    public function test_an_empty_token_cannot_take_money(): void
    {
        [$reference] = $this->donation();

        $res = $this->capture($reference, '');

        $this->assertGreaterThanOrEqual(400, $res->get_status());
    }

    public function test_a_wrong_token_answers_like_a_wrong_reference(): void
    {
        [$reference] = $this->donation();

        $wrongToken = (array) $this->capture($reference, 'not-the-token')->get_data();
        $wrongRef   = (array) $this->capture('DONO-2026-09999', 'not-the-token')->get_data();

        // Answering differently would turn the route into an oracle for which
        // references exist, which is the thing the token is protecting.
        $this->assertSame($wrongRef['code'] ?? null, $wrongToken['code'] ?? null);
    }

    public function test_the_right_token_gets_past_the_guard(): void
    {
        [$reference, $token] = $this->donation();

        $res  = $this->capture($reference, $token);
        $data = (array) $res->get_data();

        // PayPal is not configured here, so this cannot reach a real capture.
        // What it must not be is the not-found the guard returns: that would
        // mean the donor's own token was rejected and nobody could ever pay.
        $this->assertNotSame('dono_paypal_no_donation', $data['code'] ?? null);
    }
}
