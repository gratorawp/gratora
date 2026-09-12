<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Donations\Donation;
use Gratora\Donations\DonationRepository;
use Gratora\Donations\DonationService;
use Gratora\Foundation\Plugin;
use Gratora\Foundation\Time\Clock;
use Gratora\Gateways\ChargeLock;
use Gratora\Gateways\CloseUnsettledResult;
use Gratora\Gateways\GatewayManager;
use Gratora\Gateways\PayPal\PayPalAccount;
use Gratora\Gateways\PayPal\PayPalApi;
use Gratora\Gateways\PayPal\PayPalGateway;
use Gratora\Gateways\PayPal\PayPalPlanRecorder;
use Gratora\Gateways\PayPal\PayPalPlans;
use Gratora\Recurring\RecurringPlanRepository;

/**
 * Asking PayPal to close the payment behind a spam attempt.
 *
 * PayPal's Orders API has no cancel, so closing here is establishing that
 * nothing capturable is left rather than making it so. That makes the read
 * racy in a way Stripe's is not: a capture in flight looks like an order with
 * nothing on it yet, which is why the read happens under the charge lock.
 */
final class PayPalCloseUnsettledTest extends IntegrationTestCase
{
    /** Capture status the stubbed order carries, or '' for an order with none. */
    private string $captureStatus = '';

    /** @var list<string> paths the stub was asked for, in order. */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();

        update_option('gratora_gateway_config', ['test_mode' => true]);

        $c       = Plugin::instance()->container;
        $account = $c->get(PayPalAccount::class);
        $account->forget();
        $account->saveKeys(true, 'client-close', 'secret-close');
        $account->saveWebhookId(true, 'WH-CLOSE-1');

        add_filter('pre_http_request', function ($pre, $args, $url) {
            if (! is_string($url) || ! str_contains($url, 'paypal.com')) return $pre;

            $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');

            if (str_contains($path, '/v1/oauth2/token')) {
                return $this->reply(['access_token' => 'A21AAF_test', 'expires_in' => 32400]);
            }

            $this->calls[] = $path;

            $order = ['id' => 'ORDER-CLOSE', 'status' => 'CREATED'];
            if ($this->captureStatus !== '') {
                $order['purchase_units'] = [[
                    'payments' => ['captures' => [[
                        'id'     => 'CAPTURE-CLOSE',
                        'status' => $this->captureStatus,
                        'amount' => ['currency_code' => 'USD', 'value' => '25.00'],
                    ]]],
                ]];
            }

            return $this->reply($order);
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

    private function gateway(): PayPalGateway
    {
        $gateway = Plugin::instance()->container->get(GatewayManager::class)->get('paypal');
        $this->assertInstanceOf(PayPalGateway::class, $gateway);

        return $gateway;
    }

    private function donation(string $intentId = 'ORDER-CLOSE'): Donation
    {
        $d                    = Donation::make();
        $d->reference         = 'PPCLOSE-' . uniqid();
        $d->amount_cents      = 2500;
        $d->base_amount_cents = 2500;
        $d->currency          = 'USD';
        $d->base_currency     = 'USD';
        $d->status            = 'pending';
        $d->gateway           = 'paypal';
        $d->gateway_intent_id = $intentId;
        $d->frequency         = 'one_time';
        $d->kind              = 'donation';
        $d->is_test           = true;
        $d->save();

        return $d;
    }

    public function test_an_approved_order_with_nothing_captured_is_closed(): void
    {
        $this->captureStatus = '';

        $result = $this->gateway()->closeUnsettled($this->donation());

        $this->assertTrue($result->isClosed());
        $this->assertNotSame([], $this->calls, 'the order was actually read');
    }

    public function test_a_completed_capture_refuses(): void
    {
        $this->captureStatus = 'COMPLETED';

        $result = $this->gateway()->closeUnsettled($this->donation());

        $this->assertSame(CloseUnsettledResult::MONEY_MAY_ARRIVE, $result->outcome);
        $this->assertStringContainsString('COMPLETED', (string) $result->reason);
    }

    /** A held capture is money PayPal has taken and will settle by webhook. */
    public function test_a_pending_capture_refuses(): void
    {
        $this->captureStatus = 'PENDING';

        $result = $this->gateway()->closeUnsettled($this->donation());

        $this->assertSame(CloseUnsettledResult::MONEY_MAY_ARRIVE, $result->outcome);
    }

    /**
     * Told apart from "money may still arrive" on purpose. Folding the two
     * would tell the second admin their spam row may have been paid, which is
     * false and is the one message that stops a cleanup.
     */
    public function test_a_held_charge_lock_is_reported_as_a_collision_not_as_money(): void
    {
        $donation = $this->donation();

        $held = new ChargeLock('paypal');
        $this->assertTrue($held->claim($donation));

        $result = $this->gateway()->closeUnsettled($donation);

        $this->assertSame(CloseUnsettledResult::LOCKED, $result->outcome);
        $this->assertNotSame(CloseUnsettledResult::MONEY_MAY_ARRIVE, $result->outcome);
        $this->assertSame([], $this->calls, 'PayPal was never asked while another request held the lock');
        $this->assertFalse($result->mayFallBackOnAge());
    }

    public function test_the_lock_is_given_back_after_the_read(): void
    {
        $donation = $this->donation();

        $this->gateway()->closeUnsettled($donation);

        $this->assertTrue(
            (new ChargeLock('paypal'))->claim($donation),
            'a close that kept the lock would block every later attempt on this row'
        );
    }

    public function test_a_recurring_placeholder_is_closed_without_asking_paypal(): void
    {
        $result = $this->gateway()->closeUnsettled($this->donation('pending_subscription_abc'));

        $this->assertTrue($result->isClosed());
        $this->assertSame([], $this->calls, 'there is no order behind a signup placeholder');
    }
}
