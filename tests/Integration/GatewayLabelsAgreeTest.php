<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Donations\Donation;
use Gratora\Donors\DonorService;
use Gratora\Gateways\GatewayLabels;
use Gratora\Gateways\GatewayManager;
use Gratora\Recurring\PlanRow;
use Gratora\Recurring\RecurringPlan;
use Gratora\Recurring\RecurringPlanRepository;
use Gratora\Foundation\Plugin;
use WP_REST_Request;

/**
 * One donation read "PayPal" on the list and "Paypal" on its own page, because
 * only the list asked the server what the gateway is called and every other
 * surface capitalised the slug itself.
 *
 * The slug is not a name. An add-on registers its own through
 * gratora.gateway_admin_labels, and anything capitalising the raw value shows
 * "Authorize_net" to somebody who has only ever seen Authorize.Net.
 */
final class GatewayLabelsAgreeTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    protected function tearDown(): void
    {
        remove_all_filters('gratora.gateway_admin_labels');
        parent::tearDown();
    }

    public function test_the_shipped_gateways_are_named_the_way_they_spell_themselves(): void
    {
        $this->assertSame('PayPal', GatewayLabels::for('paypal'));
        $this->assertSame('Stripe', GatewayLabels::for('stripe'));
    }

    /** An add-on's own name reaches the label rather than its slug. */
    public function test_an_add_on_can_name_its_gateway(): void
    {
        add_filter('gratora.gateway_admin_labels', static function (array $labels): array {
            $labels['authorize_net'] = 'Authorize.Net';
            return $labels;
        });

        $this->assertSame('Authorize.Net', GatewayLabels::for('authorize_net'));
    }

    /** An unnamed slug is still readable rather than dropped. */
    public function test_an_unknown_slug_is_made_presentable(): void
    {
        $this->assertSame('Some Bank', GatewayLabels::for('some_bank'));
    }

    public function test_a_donation_row_carries_the_name_it_should_be_shown_by(): void
    {
        $donation = $this->paidDonation('paypal');

        $req = new WP_REST_Request('GET', '/gratora/v1/admin/donations/' . $donation->reference);
        $row = (array) ((array) rest_do_request($req)->get_data())['donation'];

        $this->assertSame('paypal', $row['gateway'], 'the slug is still there for filtering');
        $this->assertSame('PayPal', $row['gateway_label']);
    }

    public function test_a_recurring_plan_row_carries_it_too(): void
    {
        $plan = $this->makePlan('paypal');

        $row = PlanRow::common($plan, Plugin::instance()->container->get(GatewayManager::class));

        $this->assertSame('PayPal', $row['gateway_label']);
    }

    /** The subscriptions filter reads from its own query and said Paypal. */
    public function test_the_gateway_filter_options_agree(): void
    {
        $this->makePlan('paypal');

        $options = Plugin::instance()->container->get(RecurringPlanRepository::class)->gatewaysInUse();
        $labels  = array_column($options, 'label', 'value');

        $this->assertSame('PayPal', $labels['paypal'] ?? null);
    }

    private function paidDonation(string $gateway): Donation
    {
        $now = gmdate('Y-m-d H:i:s');

        $d = Donation::make();
        $d->reference         = 'GWL-' . bin2hex(random_bytes(4));
        $d->donor_id          = (int) Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate('gwl-' . uniqid() . '@example.test', ['first_name' => 'Ada'])->id;
        $d->amount_cents      = 5000;
        $d->base_amount_cents = 5000;
        $d->currency          = 'USD';
        $d->base_currency     = 'USD';
        $d->status            = 'paid';
        $d->gateway           = $gateway;
        $d->frequency         = 'one_time';
        $d->kind              = 'donation';
        $d->is_test           = false;
        $d->paid_at           = $now;
        $d->created_at        = $now;
        $d->updated_at        = $now;
        $d->save();

        return $d;
    }

    private function makePlan(string $gateway): RecurringPlan
    {
        $now = gmdate('Y-m-d H:i:s');

        $plan = RecurringPlan::make();
        $plan->donor_id                = 4242;
        $plan->gateway                 = $gateway;
        $plan->gateway_subscription_id = 'sub_' . uniqid();
        $plan->amount_cents            = 2500;
        $plan->currency                = 'USD';
        $plan->interval_unit           = 'month';
        $plan->interval_count          = 1;
        $plan->status                  = 'active';
        $plan->started_at              = $now;
        $plan->created_at              = $now;
        $plan->updated_at              = $now;
        $plan->save();

        return $plan;
    }
}
