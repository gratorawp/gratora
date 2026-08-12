<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Analytics\Event;
use Dono\Donations\DonationRepository;
use Dono\Donations\DonationService;
use Dono\Foundation\Plugin;
use Dono\Foundation\Time\Clock;
use Dono\Gateways\GatewayManager;
use Dono\Gateways\PayPal\PayPalAccount;
use Dono\Gateways\PayPal\PayPalApi;
use Dono\Gateways\PayPal\PayPalGateway;
use Dono\Gateways\PayPal\PayPalPlans;
use Dono\Recurring\RecurringPlanRepository;
use WP_REST_Request;

/**
 * PayPal can answer a capture with PENDING: it has the money and will decide
 * later. The reason it gives is the only thing that separates an eCheck
 * settling by itself from a payment that will never move until somebody accepts
 * it inside the PayPal account, so it has to survive all the way to the admin
 * who has to act on it.
 */
final class PayPalPendingCaptureTest extends IntegrationTestCase
{
    private const ORDER   = 'ORDER-PENDING-1';
    private const CAPTURE = 'CAPTURE-PENDING-1';

    protected function setUp(): void
    {
        parent::setUp();

        update_option('dono_gateway_config', ['test_mode' => true]);
        update_option('dono_currency_locale', [
            'default_currency'     => 'USD',
            'supported_currencies' => ['USD'],
        ]);

        $c       = Plugin::instance()->container;
        $account = $c->get(PayPalAccount::class);
        $account->forget();
        $account->saveKeys(true, 'client-pending', 'secret-pending');
        $account->saveWebhookId(true, 'WH-PENDING-1');

        add_filter('pre_http_request', function ($pre, $args, $url) {
            if (! is_string($url) || ! str_contains($url, 'paypal.com')) return $pre;

            $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');

            if (str_contains($path, '/v1/oauth2/token')) {
                return $this->reply(['access_token' => 'A21AAF_test', 'expires_in' => 32400]);
            }

            if (str_contains($path, '/verify-webhook-signature')) {
                return $this->reply(['verification_status' => 'SUCCESS']);
            }

            if (str_contains($path, '/capture')) {
                return $this->reply([
                    'id'             => self::ORDER,
                    'status'         => 'COMPLETED',
                    'payer'          => ['email_address' => 'held@example.test'],
                    'purchase_units' => [[
                        'payments' => ['captures' => [[
                            'id'             => self::CAPTURE,
                            'status'         => 'PENDING',
                            'status_details' => ['reason' => 'PENDING_REVIEW'],
                            'amount'         => ['currency_code' => 'USD', 'value' => '10.00'],
                        ]]],
                    ]],
                ]);
            }

            return $this->reply(['id' => self::ORDER, 'status' => 'CREATED']);
        }, 10, 3);

        $manager = $c->get(GatewayManager::class);
        if (! $manager->get('paypal')) {
            $manager->register(new PayPalGateway(
                $c->get(PayPalApi::class),
                $account,
                $c->get(DonationRepository::class),
                $c->get(DonationService::class),
                $c->get(PayPalPlans::class),
                $c->get(RecurringPlanRepository::class),
                $c->get(Clock::class),
                $c->get(\Dono\Gateways\PayPal\PayPalPlanRecorder::class),
            ));
        }
    }

    /** @param array<string,mixed> $body */
    private function reply(array $body, int $code = 200): array
    {
        return [
            'headers'  => [],
            'body'     => (string) wp_json_encode($body),
            'response' => ['code' => $code, 'message' => $code === 200 ? 'OK' : 'Error'],
            'cookies'  => [], 'filename' => null,
        ];
    }

    private function newDonation(): string
    {
        $create = new WP_REST_Request('POST', '/dono/v1/donations');
        $create->set_header('content-type', 'application/json');
        $create->set_body((string) wp_json_encode([
            'email'        => 'pending@example.test',
            'amount_cents' => 1000,
            'currency'     => 'USD',
            'gateway'      => 'paypal',
            'frequency'    => 'one_time',
            'profile'      => ['first_name' => 'Held', 'last_name' => 'Payment'],
        ]));
        $data = rest_do_request($create)->get_data();
        $this->assertArrayHasKey('reference', $data, (string) wp_json_encode($data));

        $repo     = Plugin::instance()->container->get(DonationRepository::class);
        $donation = $repo->findByReference((string) $data['reference']);
        $donation->gateway_intent_id = self::ORDER;
        $donation->save();

        return (string) $data['reference'];
    }

    private function capture(string $reference): void
    {
        $req = new WP_REST_Request('POST', '/dono/v1/gateways/paypal/capture');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode([
            'reference' => $reference,
            'status_token' => $this->stampStatusToken($reference),
            'order_id'  => self::ORDER,
        ]));
        $res = rest_do_request($req);

        $this->assertSame(200, $res->get_status(), (string) wp_json_encode($res->get_data()));
        $this->assertSame('processing', $res->get_data()['status'] ?? null);
    }

    private function find(string $reference)
    {
        return Plugin::instance()->container->get(DonationRepository::class)->findByReference($reference);
    }

    public function test_a_held_capture_records_why_paypal_is_holding_it(): void
    {
        $reference = $this->newDonation();
        $this->capture($reference);

        $donation = $this->find($reference);
        $meta     = (array) $donation->gateway_metadata;

        $this->assertSame('processing', $donation->status);
        $this->assertSame('PENDING_REVIEW', $meta['paypal_pending_reason'] ?? null);
        $this->assertSame(self::CAPTURE, $donation->gateway_txn_id);
    }

    public function test_the_reason_reaches_the_admin_screen_as_words(): void
    {
        $reference = $this->newDonation();
        $this->capture($reference);

        $res  = rest_do_request(new WP_REST_Request('GET', "/dono/v1/admin/donations/{$reference}"));
        $body = (array) $res->get_data();
        $data = (array) ($body['donation'] ?? []);

        $this->assertSame(200, $res->get_status(), (string) wp_json_encode($body));

        // Storing the reason and showing it are separate problems, and a code
        // an org has to search for is barely better than no reason at all.
        $this->assertArrayHasKey('processing_reason', $data);
        $this->assertNotNull($data['processing_reason']);
        $this->assertStringContainsString('reviewing', strtolower((string) $data['processing_reason']));
    }

    public function test_the_settling_webhook_does_not_erase_the_reason(): void
    {
        $reference = $this->newDonation();
        $this->capture($reference);

        $hook = new WP_REST_Request('POST', '/dono/v1/webhooks/paypal');
        $hook->set_header('content-type', 'application/json');
        $hook->set_header('paypal_transmission_id', 'tx-1');
        $hook->set_header('paypal_transmission_time', '2026-08-11T12:00:00Z');
        $hook->set_header('paypal_transmission_sig', 'sig');
        $hook->set_header('paypal_cert_url', 'https://api.sandbox.paypal.com/cert');
        $hook->set_header('paypal_auth_algo', 'SHA256withRSA');
        $hook->set_body((string) wp_json_encode([
            'id'         => 'WH-SETTLED-1',
            'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
            'resource'   => [
                'id'        => self::CAPTURE,
                'status'    => 'COMPLETED',
                'custom_id' => $reference,
                'amount'    => ['currency_code' => 'USD', 'value' => '10.00'],
            ],
        ]));

        $res = rest_do_request($hook);
        $this->assertLessThan(300, $res->get_status(), (string) wp_json_encode($res->get_data()));

        $donation = $this->find($reference);
        $meta     = (array) $donation->gateway_metadata;

        $this->assertSame('paid', $donation->status, 'the webhook settles the donation');

        // The settling event knows only its own capture id. Writing it over the
        // metadata rather than into it destroys the hold reason at the exact
        // moment an admin goes looking for what happened to the money.
        $this->assertSame('PENDING_REVIEW', $meta['paypal_pending_reason'] ?? null);
        $this->assertSame(self::CAPTURE, $meta['paypal_capture_id'] ?? null);
    }

    public function test_a_redelivery_is_recorded_as_its_own_attempt(): void
    {
        $reference = $this->newDonation();
        $this->capture($reference);

        // Same event id twice, as PayPal does when the first attempt does not
        // answer 2xx. The first cannot be matched to anything.
        $this->deliverCompleted('WH-REDELIVER-1', 'NO-SUCH-REFERENCE');
        $this->deliverCompleted('WH-REDELIVER-1', $reference);

        $rows = Event::query()
            ->whereLike('type', 'webhook.paypal%')
            ->orderBy('id', 'ASC')
            ->getAll();

        $this->assertCount(2, $rows, 'both attempts are kept');
        $this->assertSame('paid', $this->find($reference)->status);

        // The newest attempt is the one that says what happened. Overwriting
        // the first, or dropping the second, would leave a permanent failure
        // recorded against money that did land.
        $first = (array) $rows[0]->payload;
        $last  = (array) $rows[1]->payload;

        $this->assertFalse((bool) $first['processed']);
        $this->assertTrue((bool) $last['processed']);
        $this->assertNull($last['error'] ?? null);
    }

    private function deliverCompleted(string $eventId, string $reference): void
    {
        $hook = new WP_REST_Request('POST', '/dono/v1/webhooks/paypal');
        $hook->set_header('content-type', 'application/json');
        $hook->set_header('paypal_transmission_id', 'tx-' . $eventId);
        $hook->set_header('paypal_transmission_time', '2026-08-11T12:00:00Z');
        $hook->set_header('paypal_transmission_sig', 'sig');
        $hook->set_header('paypal_cert_url', 'https://api.sandbox.paypal.com/cert');
        $hook->set_header('paypal_auth_algo', 'SHA256withRSA');
        $hook->set_body((string) wp_json_encode([
            'id'         => $eventId,
            'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
            'resource'   => [
                'id'        => self::CAPTURE,
                'status'    => 'COMPLETED',
                'custom_id' => $reference,
                'amount'    => ['currency_code' => 'USD', 'value' => '10.00'],
            ],
        ]));

        rest_do_request($hook);
    }

    public function test_a_pending_webhook_records_the_reason_when_the_donor_never_returns(): void
    {
        $reference = $this->newDonation();

        $hook = new WP_REST_Request('POST', '/dono/v1/webhooks/paypal');
        $hook->set_header('content-type', 'application/json');
        $hook->set_header('paypal_transmission_id', 'tx-2');
        $hook->set_header('paypal_transmission_time', '2026-08-11T12:00:00Z');
        $hook->set_header('paypal_transmission_sig', 'sig');
        $hook->set_header('paypal_cert_url', 'https://api.sandbox.paypal.com/cert');
        $hook->set_header('paypal_auth_algo', 'SHA256withRSA');
        $hook->set_body((string) wp_json_encode([
            'id'         => 'WH-PENDING-EVT-1',
            'event_type' => 'PAYMENT.CAPTURE.PENDING',
            'resource'   => [
                'id'             => self::CAPTURE,
                'status'         => 'PENDING',
                'status_details' => ['reason' => 'RECEIVING_PREFERENCE_MANDATES_MANUAL_ACTION'],
                'custom_id'      => $reference,
                'amount'         => ['currency_code' => 'USD', 'value' => '10.00'],
            ],
        ]));

        $res = rest_do_request($hook);
        $this->assertLessThan(300, $res->get_status(), (string) wp_json_encode($res->get_data()));

        $donation = $this->find($reference);
        $meta     = (array) $donation->gateway_metadata;

        $this->assertSame('processing', $donation->status);
        $this->assertSame(
            'RECEIVING_PREFERENCE_MANDATES_MANUAL_ACTION',
            $meta['paypal_pending_reason'] ?? null
        );
    }
}
