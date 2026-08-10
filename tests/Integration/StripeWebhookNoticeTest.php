<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Gateways\Stripe\StripeAccount;
use Dono\Gateways\Stripe\StripeApi;
use Dono\Gateways\Stripe\StripeWebhookNotice;
use Dono\Foundation\Plugin;
use Dono\Recurring\RecurringPlan;

/**
 * The missing-webhook-secret warning is about deliveries that will be rejected.
 * Switching Stripe off in Settings stops it being offered on a form, but any
 * subscription already running there keeps billing and keeps sending webhooks,
 * so the two questions are not the same one.
 */
final class StripeWebhookNoticeTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $account = Plugin::instance()->container->get(StripeAccount::class);
        $account->saveKeys(true, 'pk_test_x', 'sk_test_x');
    }

    private function enableStripe(bool $on): void
    {
        update_option('dono_gateway_config', [
            'test_mode' => true,
            'stripe'    => ['enabled' => $on, 'webhook_secret_test' => '', 'webhook_secret_live' => ''],
        ]);
    }

    private function stripePlan(string $status): void
    {
        $p = RecurringPlan::make();
        $p->donor_id                = 1;
        $p->gateway                 = 'stripe';
        $p->gateway_subscription_id = 'sub_' . uniqid();
        $p->amount_cents            = 1000;
        $p->currency                = 'USD';
        $p->interval_unit           = 'month';
        $p->interval_count          = 1;
        $p->status                  = $status;
        $p->is_test                 = false;
        $p->started_at              = gmdate('Y-m-d H:i:s');
        $p->created_at              = $p->started_at;
        $p->updated_at              = $p->started_at;
        $p->save();
    }

    private function noticeShown(): bool
    {
        $c = Plugin::instance()->container;

        return (new StripeWebhookNotice(
            $c->get(StripeAccount::class),
            $c->get(StripeApi::class),
        ))->shouldWarn();
    }

    public function test_it_warns_while_stripe_is_switched_on(): void
    {
        $this->enableStripe(true);

        $this->assertTrue($this->noticeShown());
    }

    public function test_it_goes_quiet_once_stripe_is_switched_off(): void
    {
        $this->enableStripe(false);

        $this->assertFalse($this->noticeShown(), 'nothing is arriving to be rejected');
    }

    /**
     * The case that stops this being a one-line check: an org that stopped
     * offering Stripe still has people on Stripe subscriptions, and every
     * renewal of those arrives by webhook.
     */
    public function test_it_keeps_warning_when_a_subscription_is_still_billing(): void
    {
        $this->enableStripe(false);
        $this->stripePlan('active');

        $this->assertTrue($this->noticeShown(), 'those renewals still need the secret');
    }

    public function test_a_cancelled_subscription_does_not_keep_it_alive(): void
    {
        $this->enableStripe(false);
        $this->stripePlan('cancelled');

        $this->assertFalse($this->noticeShown());
    }
}
