<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Donations\DonationRepository;
use Gratora\Donations\DonationService;
use Gratora\Foundation\Plugin;
use Gratora\Foundation\Time\Clock;
use Gratora\Gateways\ChargeLock;
use Gratora\Gateways\GatewayManager;
use Gratora\Gateways\PayPal\PayPalAccount;
use Gratora\Gateways\PayPal\PayPalApi;
use Gratora\Gateways\PayPal\PayPalGateway;
use Gratora\Gateways\PayPal\PayPalPlanRecorder;
use Gratora\Gateways\PayPal\PayPalPlans;
use Gratora\Recurring\RecurringPlanRepository;
use WP_REST_Request;

/**
 * The PayPal capture route reads a pending status and then spends three round
 * trips at PayPal before writing anything back. A second request arriving in
 * that window reads the same pending status.
 *
 * The order is captured against the id Gratora stored, so two captures are two
 * attempts on the same order rather than two orders, and PayPal answering the
 * second one is not something this site gets to rely on.
 */
final class PayPalChargeLockTest extends IntegrationTestCase
{
    private bool $captureAttempted = false;

    protected function setUp(): void
    {
        parent::setUp();

        update_option('gratora_gateway_config', ['test_mode' => true]);
        update_option('gratora_currency_locale', [
            'default_currency'     => 'USD',
            'supported_currencies' => ['USD'],
        ]);

        $c       = Plugin::instance()->container;
        $account = $c->get(PayPalAccount::class);
        $account->forget();
        $account->saveKeys(true, 'client-lock', 'secret-lock');
        $account->saveWebhookId(true, 'WH-LOCK-1');

        add_filter('pre_http_request', function ($pre, $args, $url) {
            if (! is_string($url) || ! str_contains($url, 'paypal.com')) return $pre;

            $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');

            if (str_contains($path, '/v1/oauth2/token')) {
                return $this->reply(['access_token' => 'A21AAF_test', 'expires_in' => 32400]);
            }

            if (str_contains($path, '/capture')) {
                $this->captureAttempted = true;

                return $this->reply([
                    'id'             => 'ORDER-LOCK',
                    'status'         => 'COMPLETED',
                    'purchase_units' => [[
                        'payments' => ['captures' => [[
                            'id'     => 'CAPTURE-LOCK',
                            'status' => 'COMPLETED',
                            'amount' => ['currency_code' => 'USD', 'value' => '25.00'],
                        ]]],
                    ]],
                ]);
            }

            return $this->reply(['id' => 'ORDER-LOCK', 'status' => 'CREATED']);
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
                $c->get(PayPalPlanRecorder::class),
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

    /** @return array{reference:string, status_token:string} */
    private function pendingPayPalDonation(): array
    {
        $create = new WP_REST_Request('POST', '/gratora/v1/donations');
        $create->set_header('content-type', 'application/json');
        $create->set_body((string) wp_json_encode([
            'email'        => 'lock-' . uniqid() . '@example.test',
            'amount_cents' => 2500,
            'currency'     => 'USD',
            'gateway'      => 'paypal',
            'frequency'    => 'one_time',
            'profile'      => ['first_name' => 'Charge', 'last_name' => 'Lock'],
        ]));

        $data = (array) rest_do_request($create)->get_data();
        $this->assertArrayHasKey('reference', $data, (string) wp_json_encode($data));
        $this->assertArrayHasKey('status_token', $data, (string) wp_json_encode($data));

        $donations = Plugin::instance()->container->get(DonationRepository::class);
        $donation  = $donations->findByReference((string) $data['reference']);
        $donation->gateway_intent_id = 'ORDER-LOCK';
        $donation->save();

        return ['reference' => (string) $data['reference'], 'status_token' => (string) $data['status_token']];
    }

    private function capture(array $donation): \WP_REST_Response
    {
        $req = new WP_REST_Request('POST', '/gratora/v1/gateways/paypal/capture');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode([
            'reference'    => $donation['reference'],
            'status_token' => $donation['status_token'],
        ]));

        return rest_do_request($req);
    }

    /**
     * The one that matters. If the second request captures too, the order is
     * attempted twice and only one of the outcomes is on the record.
     */
    public function test_a_capture_makes_no_paypal_call_while_the_lock_is_held(): void
    {
        $donation  = $this->pendingPayPalDonation();
        $donations = Plugin::instance()->container->get(DonationRepository::class);

        // Taken through the real lock rather than by writing the row by hand,
        // so this cannot pass against a key the route stopped using.
        $held = new ChargeLock('paypal');
        $this->assertTrue($held->claim($donations->findByReference($donation['reference'])));

        $response = $this->capture($donation);

        $this->assertFalse($this->captureAttempted, 'nothing was captured while another request held the lock');

        // And the donor is told where they stand rather than shown a failure
        // that is not theirs: a lost race is not a declined card.
        $this->assertSame(200, $response->get_status());
        $this->assertNotSame('failed', $donations->findByReference($donation['reference'])->status);
    }

    public function test_the_lock_is_released_once_the_capture_is_done(): void
    {
        $donation  = $this->pendingPayPalDonation();
        $donations = Plugin::instance()->container->get(DonationRepository::class);

        $response = $this->capture($donation);

        $this->assertSame(200, $response->get_status(), (string) wp_json_encode($response->get_data()));
        $this->assertTrue($this->captureAttempted, 'the capture really ran');

        // Donation ids never repeat, so a lock left behind is never claimed
        // again and never expires into anything. The next claim proves the
        // finally ran rather than the row merely being old.
        $this->assertTrue(
            (new ChargeLock('paypal'))->claim($donations->findByReference($donation['reference'])),
            'the route gave the lock back'
        );
    }

    /**
     * The lock closes only this site's own in-flight window, and it expires. So
     * what makes an admin's stop durable is the row itself: a donor-facing
     * charge route treats a trashed donation exactly as it treats a settled one.
     */
    public function test_a_capture_on_a_trashed_row_makes_no_paypal_call(): void
    {
        $donation  = $this->pendingPayPalDonation();
        $donations = Plugin::instance()->container->get(DonationRepository::class);

        $donations->findByReference($donation['reference'])->updateColumns([
            'trashed_at' => gmdate('Y-m-d H:i:s'),
            'trashed_by' => 1,
        ]);

        $response = $this->capture($donation);

        $this->assertFalse($this->captureAttempted, 'a payment an admin stopped is never sent to PayPal');
        $this->assertSame(200, $response->get_status());

        // Left exactly as it was: the route reports where the row stands, it
        // does not fail the donor over a decision an admin made.
        $this->assertSame('pending', $donations->findByReference($donation['reference'])->status);
    }
}
