<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

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
 * A second capture on an order PayPal already took is documented as safe to
 * re-enter: the gateway re-reads the order and confirms the donation from it.
 *
 * It never worked. `isAlreadyCaptured()` grepped the formatted message for
 * `ORDER_ALREADY_CAPTURED`, and the formatter prefers `details[].description`
 * over `details[].issue`. PayPal always sends a description, so the code was
 * gone before the check saw it, and the path only ever worked against a
 * response shape PayPal does not send. A double-click or a retried tab told the
 * donor their payment had failed on money already taken.
 */
final class PayPalRecaptureTest extends IntegrationTestCase
{
    /** PayPal's real body: issue code and description together. */
    private const REAL_ERROR = [
        'name'    => 'UNPROCESSABLE_ENTITY',
        'message' => 'The requested action could not be performed, semantically incorrect, or failed business validation.',
        'details' => [[
            'issue'       => 'ORDER_ALREADY_CAPTURED',
            'description' => "Order already captured.If 'intent=CAPTURE' only one capture per order is allowed.",
        ]],
    ];

    private bool $captureAttempted = false;
    private bool $orderRead = false;

    protected function setUp(): void
    {
        parent::setUp();

        update_option('dono_gateway_config', ['test_mode' => true]);
        update_option('dono_currency_locale', [
            'default_currency'     => 'USD',
            'supported_currencies' => ['USD'],
        ]);

        $c = Plugin::instance()->container;
        $account = $c->get(PayPalAccount::class);
        $account->forget();
        $account->saveKeys(true, 'client-recapture', 'secret-recapture');
        $account->saveWebhookId(true, 'WH-RECAP-1');

        add_filter('pre_http_request', function ($pre, $args, $url) {
            if (! is_string($url) || ! str_contains($url, 'paypal.com')) return $pre;

            $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');

            if (str_contains($path, '/v1/oauth2/token')) {
                return $this->reply(['access_token' => 'A21AAF_test', 'expires_in' => 32400]);
            }

            if (str_contains($path, '/capture')) {
                $this->captureAttempted = true;
                return $this->reply(self::REAL_ERROR, 422);
            }

            if (str_contains($path, '/v2/checkout/orders/')) {
                $this->orderRead = true;
                return $this->reply([
                    'id'             => 'ORDER-RECAP',
                    'status'         => 'COMPLETED',
                    'purchase_units' => [[
                        'payments' => ['captures' => [[
                            'id'     => 'CAPTURE-RECAP',
                            'status' => 'COMPLETED',
                            'amount' => ['currency_code' => 'USD', 'value' => '25.00'],
                        ]]],
                    ]],
                ]);
            }

            return $this->reply(['id' => 'ORDER-RECAP', 'status' => 'CREATED']);
        }, 10, 3);

        // CoreModule registers PayPal only when credentials exist at boot, and
        // these are created here, so register it by hand.
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

    public function test_a_second_capture_confirms_the_donation_instead_of_failing_the_donor(): void
    {
        $create = new WP_REST_Request('POST', '/dono/v1/donations');
        $create->set_header('content-type', 'application/json');
        $create->set_body((string) wp_json_encode([
            'email'        => 'recapture@example.test',
            'amount_cents' => 2500,
            'currency'     => 'USD',
            'gateway'      => 'paypal',
            'frequency'    => 'one_time',
            'profile'      => ['first_name' => 'Re', 'last_name' => 'Capture'],
        ]));
        $data = rest_do_request($create)->get_data();
        $this->assertArrayHasKey('reference', $data, (string) wp_json_encode($data));
        $reference = (string) $data['reference'];

        $gateway  = Plugin::instance()->container->get(GatewayManager::class)->get('paypal');
        $donation = Plugin::instance()->container->get(DonationRepository::class)->findByReference($reference);
        $donation->gateway_intent_id = 'ORDER-RECAP';
        $donation->save();

        $result = $gateway->confirm($donation, ['order_id' => 'ORDER-RECAP']);

        $this->assertTrue($this->captureAttempted, 'the capture was attempted');
        $this->assertTrue($result->success, 'an already-captured order is a success, not a failure');
        $this->assertTrue($this->orderRead, 'and the order was re-read to recover the transaction id');
        $this->assertSame('CAPTURE-RECAP', $result->gateway_txn_id);
    }
}
