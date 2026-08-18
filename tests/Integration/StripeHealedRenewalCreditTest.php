<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\Donation;
use Dono\Donations\DonationRepository;
use Dono\Donors\DonorService;
use Dono\Foundation\Plugin;
use Dono\Gateways\GatewayManager;
use Dono\Gateways\Stripe\StripeAccount;
use Dono\Recurring\RecurringPlan;
use WP_REST_Request;

/**
 * A redelivery that finishes a half-written renewal is the payment landing, so
 * it has to credit the plan: otherwise the plan stays dunned forever and the
 * next real decline reaches nobody.
 */
final class StripeHealedRenewalCreditTest extends IntegrationTestCase
{
    private string $secret = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->secret = 'whsec_test_' . bin2hex(random_bytes(8));
        update_option('dono_gateway_config', [
            'stripe' => ['webhook_secret_test' => $this->secret, 'test_mode' => true],
        ]);

        $c       = Plugin::instance()->container;
        $account = $c->get(StripeAccount::class);
        $account->saveKeys(true, 'sk_test_connected', 'pk_test_seed');
        $account->refresh(['id' => 'acct_test_org', 'charges_enabled' => true]);

        $manager = $c->get(GatewayManager::class);
        if (! $manager->get('stripe')) {
            $manager->register(new \Dono\Gateways\Stripe\StripeGateway(
                $c->get(\Dono\Gateways\Stripe\StripeApi::class),
                $c->get(DonationRepository::class),
                $c->get(\Dono\Donations\DonationService::class),
                $account,
                $c->get(\Dono\Donors\DonorRepository::class),
                $c->get(DonorService::class),
                $c->get(\Dono\Foundation\Time\Clock::class),
                $c->get(\Dono\Recurring\RecurringPlanRepository::class),
            ));
        }
    }

    public function test_a_redelivery_that_settles_a_pending_renewal_credits_the_plan(): void
    {
        $plan = $this->seedPlan(['failed_renewals_count' => 2, 'status' => 'past_due']);
        $piId = 'pi_heal_' . bin2hex(random_bytes(5));

        // What a delivery that died between inserting the renewal and crediting
        // the plan leaves behind.
        $this->seedPendingRenewal($plan, $piId, 2500);

        $this->postWebhook('invoice.payment_succeeded', $this->invoice($plan, $piId, 2500));

        $renewal = Donation::query()->where('gateway_intent_id', $piId)->get();
        $this->assertSame('paid', (string) $renewal->status, 'precondition: the redelivery settled the renewal');

        $fresh = RecurringPlan::query()->find('id', (int) $plan->id);
        $this->assertSame(2, (int) $fresh->payments_count, 'the payment that landed is counted');
        $this->assertSame(5000, (int) $fresh->total_paid_cents, 'and added to what the donor has given');
        $this->assertSame(0, (int) $fresh->failed_renewals_count, 'a plan that just paid is not failing');
        $this->assertNotNull($fresh->last_payment_at, 'and it paid at a knowable time');
        $this->assertSame(
            gmdate('Y-m-d H:i:s', 1740787200),
            (string) $fresh->next_payment_at,
            'the schedule moves to the period Stripe just billed'
        );
    }

    public function test_a_renewal_that_was_already_settled_is_not_credited_twice(): void
    {
        $plan = $this->seedPlan(['payments_count' => 2, 'total_paid_cents' => 5000]);
        $piId = 'pi_settled_' . bin2hex(random_bytes(5));

        $renewal = $this->seedPendingRenewal($plan, $piId, 2500);
        $renewal->status  = 'paid';
        $renewal->paid_at = gmdate('Y-m-d H:i:s');
        $renewal->save();

        $this->postWebhook('invoice.payment_succeeded', $this->invoice($plan, $piId, 2500));

        $fresh = RecurringPlan::query()->find('id', (int) $plan->id);
        $this->assertSame(2, (int) $fresh->payments_count, 'a plain redelivery must not inflate the counters');
        $this->assertSame(5000, (int) $fresh->total_paid_cents);
    }

    /** @param array<string,mixed> $overrides */
    private function seedPlan(array $overrides = []): RecurringPlan
    {
        $donor = Plugin::instance()->container
            ->get(DonorService::class)
            ->findOrCreate('healed-' . uniqid() . '@example.test', ['first_name' => 'Healed', 'last_name' => 'Renewer']);

        $plan = RecurringPlan::make();
        $plan->donor_id                = (int) $donor->id;
        $plan->gateway                 = 'stripe';
        $plan->is_test                 = true;
        $plan->gateway_subscription_id = 'sub_heal_' . bin2hex(random_bytes(4));
        $plan->gateway_customer_id     = 'cus_heal';
        $plan->amount_cents            = 2500;
        $plan->currency                = 'USD';
        $plan->interval_unit           = 'month';
        $plan->interval_count          = 1;
        $plan->status                  = 'active';
        $plan->started_at              = '2026-01-01 00:00:00';
        $plan->next_payment_at         = '2026-02-01 00:00:00';
        $plan->payments_count          = 1;
        $plan->total_paid_cents        = 2500;
        $plan->failed_renewals_count   = 0;
        $plan->created_at              = '2026-01-01 00:00:00';
        $plan->updated_at              = '2026-01-01 00:00:00';
        foreach ($overrides as $field => $value) {
            $plan->{$field} = $value;
        }
        $plan->save();

        return $plan;
    }

    private function seedPendingRenewal(RecurringPlan $plan, string $piId, int $amountCents): Donation
    {
        $now = gmdate('Y-m-d H:i:s');
        $d   = Donation::make();
        $d->reference         = 'STRIPE-HEAL-' . strtoupper(bin2hex(random_bytes(4)));
        $d->status_token_hash = '';
        $d->donor_id          = (int) $plan->donor_id;
        $d->recurring_plan_id = (int) $plan->id;
        $d->amount_cents      = $amountCents;
        $d->net_cents         = $amountCents;
        $d->base_amount_cents = $amountCents;
        $d->base_currency     = 'USD';
        $d->fx_rate           = sprintf('%.8F', 1);
        $d->currency          = 'USD';
        $d->status            = 'pending';
        $d->frequency         = 'monthly';
        $d->gateway           = 'stripe';
        $d->gateway_intent_id = $piId;
        $d->is_test           = true;
        $d->created_at        = $now;
        $d->updated_at        = $now;
        $d->save();

        return $d;
    }

    /** @return array<string,mixed> */
    private function invoice(RecurringPlan $plan, string $piId, int $amountCents): array
    {
        return [
            'id'             => 'in_heal_' . bin2hex(random_bytes(4)),
            'subscription'   => (string) $plan->gateway_subscription_id,
            'billing_reason' => 'subscription_cycle',
            'payment_intent' => $piId,
            'amount_paid'    => $amountCents,
            'currency'       => 'usd',
            'period_start'   => 1738368000,
            'period_end'     => 1740787200,
            'lines'          => ['data' => [['period' => ['start' => 1738368000, 'end' => 1740787200]]]],
        ];
    }

    /** @param array<string,mixed> $object */
    private function postWebhook(string $type, array $object): \WP_REST_Response
    {
        $event = [
            'id'       => 'evt_' . bin2hex(random_bytes(6)),
            'type'     => $type,
            'livemode' => false,
            'data'     => ['object' => $object],
        ];
        $payload   = (string) wp_json_encode($event);
        $timestamp = (string) time();
        $sig       = hash_hmac('sha256', "{$timestamp}.{$payload}", $this->secret);

        $req = new WP_REST_Request('POST', '/dono/v1/webhooks/stripe');
        $req->set_header('content-type', 'application/json');
        $req->set_header('stripe_signature', "t={$timestamp},v1={$sig}");
        $req->set_body($payload);

        return rest_do_request($req);
    }
}
