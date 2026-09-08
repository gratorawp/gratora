<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Donations\Donation;
use FundKit\Donations\DonationRepository;
use FundKit\Foundation\Plugin;
use WP_REST_Request;

/**
 * What repeating a PayPal callback costs this site.
 *
 * Both routes call PayPal, and the status token that opens them is held for the
 * life of the donation. So one real donation is a permanent credential, and
 * without a ceiling it buys unlimited blocking outbound calls: cheap to send,
 * expensive to serve, and spent against the org's own PayPal rate limit rather
 * than the caller's. Money was never the exposure here; workers and API budget
 * are.
 */
final class PayPalCallbackCostTest extends IntegrationTestCase
{
    private int $outbound = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->outbound = 0;

        // The ceiling is relaxed tenfold while the site is in test mode, which
        // is deliberate and not what this measures: left to whatever an earlier
        // test left behind, 40 calls sit under a 300 cap and no refusal comes.
        $cfg = get_option('fundkit_gateway_config', []);
        update_option('fundkit_gateway_config', array_merge(is_array($cfg) ? $cfg : [], ['test_mode' => false]));

        add_filter('pre_http_request', function ($pre, $args, $url) {
            if (is_string($url) && str_contains($url, 'paypal.com')) {
                $this->outbound++;

                return [
                    'headers'  => [],
                    'body'     => (string) wp_json_encode(['id' => 'ORDER1', 'status' => 'COMPLETED']),
                    'response' => ['code' => 200, 'message' => 'OK'],
                ];
            }

            return $pre;
        }, 10, 3);
    }

    /** @return array{0:string,1:string} reference and raw status token */
    private function donation(): array
    {
        $req = new WP_REST_Request('POST', '/fundkit/v1/donations');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode([
            'email'        => 'paypal-cost-' . uniqid() . '@example.test',
            'amount_cents' => 5000,
            'currency'     => 'USD',
            'gateway'      => 'offline',
            'frequency'    => 'one_time',
        ]));
        $data = (array) rest_do_request($req)->get_data();

        $repo     = Plugin::instance()->container->get(DonationRepository::class);
        $donation = $repo->findByReference((string) $data['reference']);
        $donation->gateway           = 'paypal';
        $donation->gateway_intent_id = 'ORDER1';
        $donation->save();

        return [(string) $data['reference'], (string) ($data['status_token'] ?? '')];
    }

    private function capture(string $reference, string $token): int
    {
        $req = new WP_REST_Request('POST', '/fundkit/v1/gateways/paypal/capture');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode([
            'reference'    => $reference,
            'status_token' => $token,
        ]));

        return rest_do_request($req)->get_status();
    }

    private function settle(string $reference): void
    {
        $repo = Plugin::instance()->container->get(DonationRepository::class);
        Donation::query()
            ->where('id', (int) $repo->findByReference($reference)->id)
            ->update(['status' => 'paid']);
    }

    public function test_a_settled_donation_costs_no_paypal_call_to_refuse(): void
    {
        [$reference, $token] = $this->donation();
        $this->settle($reference);

        $this->outbound = 0;
        for ($i = 0; $i < 12; $i++) {
            $this->capture($reference, $token);
        }

        $this->assertSame(
            0,
            $this->outbound,
            'a donation whose money already moved must not spend an outbound call per request'
        );
    }

    public function test_a_replay_on_a_paid_donation_answers_as_success(): void
    {
        [$reference, $token] = $this->donation();
        $this->settle($reference);

        $this->assertSame(200, $this->capture($reference, $token));
    }

    public function test_repeating_the_callback_is_eventually_refused(): void
    {
        [$reference, $token] = $this->donation();

        $statuses = [];
        for ($i = 0; $i < 40; $i++) {
            $statuses[] = $this->capture($reference, $token);
        }

        $this->assertContains(429, $statuses, 'an unbounded outbound-call channel must have a ceiling');
    }

    /** The ceiling must not land on the one call a real donation makes. */
    public function test_a_single_capture_is_never_rate_limited(): void
    {
        [$reference, $token] = $this->donation();

        $this->assertNotSame(429, $this->capture($reference, $token));
    }
}
