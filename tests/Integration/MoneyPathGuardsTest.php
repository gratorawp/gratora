<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Donations\Donation;
use Gratora\Foundation\Plugin;
use Gratora\Funds\Fund;
use Gratora\Funds\FundService;
use Gratora\Receipts\ReceiptIssuer;
use Gratora\Recurring\RecurringPlan;
use InvalidArgumentException;

/**
 * Four guards on paths where getting it wrong moves real money or tells a
 * donor something untrue about theirs. Each one existed somewhere already and
 * was missing on a sibling path.
 */
final class MoneyPathGuardsTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The PayPal cases need a connected account, or the API fails closed
        // before the guard under test is reached and every call looks skipped.
        update_option('gratora_gateway_config', ['test_mode' => true]);
        $account = Plugin::instance()->container->get(\Gratora\Gateways\PayPal\PayPalAccount::class);
        $account->forget();
        $account->saveKeys(true, 'AeA1QIZ_client', 'EO422dn3_secret');
        $account->saveWebhookId(true, 'WH-GUARD-1');
    }

    private function funds(): FundService
    {
        return Plugin::instance()->container->get(FundService::class);
    }

    private function paidDonation(string $kind): Donation
    {
        $now = gmdate('Y-m-d H:i:s');

        $d = Donation::make();
        $d->reference         = strtoupper($kind) . '-' . uniqid();
        $d->donor_id          = 5150;
        $d->amount_cents      = 10000;
        $d->base_amount_cents = 10000;
        $d->currency          = 'USD';
        $d->base_currency     = 'USD';
        $d->status            = 'paid';
        $d->gateway           = 'offline';
        $d->frequency         = 'one_time';
        $d->kind              = $kind;
        $d->is_test           = false;
        $d->paid_at           = $now;
        $d->created_at        = $now;
        $d->updated_at        = $now;
        $d->save();

        return $d;
    }

    public function test_a_fund_cannot_be_created_default_and_inactive_at_once(): void
    {
        // Every donation with no fund chosen goes to the default, and an
        // inactive fund is offered to nobody, so this would demote the working
        // default and route that money to whatever the resolver reached next.
        $this->expectException(InvalidArgumentException::class);

        $this->funds()->create([
            'code'       => 'ghost',
            'name'       => 'Ghost fund',
            'is_default' => true,
            'is_active'  => false,
        ]);
    }

    public function test_the_working_default_survives_a_refused_create(): void
    {
        $live = $this->funds()->create(['code' => 'general', 'name' => 'General', 'is_default' => true]);

        try {
            $this->funds()->create([
                'code'       => 'ghost',
                'name'       => 'Ghost fund',
                'is_default' => true,
                'is_active'  => false,
            ]);
        } catch (InvalidArgumentException $e) {
            // Expected.
        }

        $fresh = Fund::query()->find('id', (int) $live->id);
        $this->assertTrue((bool) $fresh->is_default, 'the default was demoted by a create that was refused');
    }

    public function test_a_create_that_is_default_and_active_still_works(): void
    {
        $fund = $this->funds()->create([
            'code'       => 'ok',
            'name'       => 'Fine',
            'is_default' => true,
            'is_active'  => true,
        ]);

        $this->assertTrue((bool) $fund->is_default);
        $this->assertTrue((bool) $fund->is_active);
    }

    public function test_resend_honours_the_policy_that_suppressed_the_receipt(): void
    {
        // What an add-on selling tickets does: this row is a purchase, and its
        // receipt is issued by the add-on with the meal value stated.
        $suppress = static fn (bool $should, Donation $d): bool
            => (string) $d->kind === 'order' ? false : $should;
        add_filter('gratora.receipt.should_issue', $suppress, 10, 2);

        try {
            $order = $this->paidDonation('order');
            $issuer = Plugin::instance()->container->get(ReceiptIssuer::class);

            $this->assertFalse(
                $issuer->requeueForDonation((int) $order->id),
                'Resend reissued a receipt the policy had suppressed'
            );
        } finally {
            remove_filter('gratora.receipt.should_issue', $suppress, 10);
        }
    }

    public function test_resend_still_works_for_an_ordinary_donation(): void
    {
        $donation = $this->paidDonation('donation');
        $issuer   = Plugin::instance()->container->get(ReceiptIssuer::class);

        $this->assertTrue(
            $issuer->requeueForDonation((int) $donation->id),
            'the guard must not stop a receipt this site does issue'
        );
    }

    /** @return list<string> */
    private function paypalCalls(callable $act): array
    {
        $calls = [];
        $spy = function ($pre, $args, $url) use (&$calls) {
            if (! is_string($url) || ! str_contains($url, 'paypal')) {
                return $pre;
            }

            $calls[] = $url;

            // The token has to succeed or nothing reaches the endpoint under
            // test and every call reads as guarded.
            if (str_contains($url, '/oauth2/token')) {
                return [
                    'headers'  => [],
                    'body'     => (string) wp_json_encode(['access_token' => 'A21_test', 'expires_in' => 3600]),
                    'response' => ['code' => 200, 'message' => 'OK'],
                    'cookies'  => [], 'filename' => null,
                ];
            }

            return [
                'headers' => [], 'body' => '{}',
                'response' => ['code' => 404, 'message' => 'Not Found'],
                'cookies' => [], 'filename' => null,
            ];
        };
        add_filter('pre_http_request', $spy, 10, 3);

        try {
            $act();
        } finally {
            remove_filter('pre_http_request', $spy, 10);
        }

        return $calls;
    }

    private function seededPlan(string $subscriptionId): RecurringPlan
    {
        $now = gmdate('Y-m-d H:i:s');

        $p = RecurringPlan::make();
        $p->donor_id                = 5151;
        $p->gateway                 = 'paypal';
        $p->gateway_subscription_id = $subscriptionId;
        $p->amount_cents            = 2500;
        $p->currency                = 'USD';
        $p->interval_unit           = 'month';
        $p->interval_count          = 1;
        $p->status                  = 'active';
        $p->is_test                 = true;
        $p->created_at              = $now;
        $p->updated_at              = $now;
        $p->save();

        return $p;
    }

    private function paypal(): \Gratora\Gateways\PayPal\PayPalGateway
    {
        $c = Plugin::instance()->container;

        return new \Gratora\Gateways\PayPal\PayPalGateway(
            $c->get(\Gratora\Gateways\PayPal\PayPalApi::class),
            $c->get(\Gratora\Gateways\PayPal\PayPalAccount::class),
            $c->get(\Gratora\Donations\DonationRepository::class),
            $c->get(\Gratora\Donations\DonationService::class),
            $c->get(\Gratora\Gateways\PayPal\PayPalPlans::class),
            $c->get(\Gratora\Recurring\RecurringPlanRepository::class),
            $c->get(\Gratora\Foundation\Time\Clock::class),
            $c->get(\Gratora\Gateways\PayPal\PayPalPlanRecorder::class),
        );
    }

    public function test_paypal_is_never_sent_an_id_it_could_not_have_issued(): void
    {
        // What DemoSeeder and the Give importer leave behind. PayPal answers
        // RESOURCE_NOT_FOUND, which is not a state the idempotency check knows,
        // so the donor's cancel threw and the plan stayed active for good.
        $plan    = $this->seededPlan('demo-sub007');
        $gateway = $this->paypal();

        $calls = $this->paypalCalls(function () use ($gateway, $plan): void {
            $gateway->cancelSubscription($plan, 'donor asked');
            $gateway->pauseSubscription($plan, null);
            $gateway->resumeSubscription($plan);
        });

        $subscriptionCalls = array_values(array_filter(
            $calls,
            static fn (string $u): bool => str_contains($u, '/billing/subscriptions/')
        ));

        $this->assertSame([], $subscriptionCalls, 'a subscription id PayPal never issued was sent to PayPal');
    }

    public function test_a_real_paypal_id_still_reaches_paypal(): void
    {
        $plan    = $this->seededPlan('I-REAL-1');
        $gateway = $this->paypal();

        $calls = $this->paypalCalls(function () use ($gateway, $plan): void {
            try {
                $gateway->cancelSubscription($plan, 'donor asked');
            } catch (\RuntimeException $e) {
                // The fake answers 404; what matters is that it was asked.
            }
        });

        // The token call comes first; what matters is the cancel behind it.
        $this->assertNotSame([], $calls, 'the guard must not stop a real cancel');
        $this->assertNotSame(
            [],
            array_values(array_filter($calls, static fn (string $u): bool => str_contains($u, 'I-REAL-1'))),
            'the cancel never reached PayPal: ' . implode(', ', $calls)
        );
    }
}
