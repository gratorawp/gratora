<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Donations\Donation;
use Gratora\Donors\Consent;
use Gratora\Donors\Donor;
use Gratora\Donors\DonorService;
use Gratora\Donors\MagicLinkToken;
use Gratora\Donors\PendingSignup;
use Gratora\Foundation\Identity\IdentityHasher;
use Gratora\Foundation\Plugin;
use Gratora\Recurring\RecurringPlan;
use WP_REST_Request;

final class DonorDeleteTest extends IntegrationTestCase
{
    private function service(): DonorService
    {
        return Plugin::instance()->container->get(DonorService::class);
    }

    private function donor(string $email): Donor
    {
        return $this->service()->findOrCreate($email, ['first_name' => 'Del', 'last_name' => 'Probe']);
    }

    private function gave(Donor $donor): void
    {
        $d = Donation::make();
        $d->donor_id     = (int) $donor->id;
        $d->campaign_id  = 1;
        $d->reference    = 'DEL-' . bin2hex(random_bytes(4));
        $d->amount_cents = 2500;
        $d->currency     = 'USD';
        $d->status       = 'paid';
        $d->gateway      = 'offline';
        $d->is_test      = false;
        $d->created_at   = gmdate('Y-m-d H:i:s');
        $d->updated_at   = gmdate('Y-m-d H:i:s');
        $d->save();
    }

    private function deleteViaRest(int $id): \WP_REST_Response|\WP_Error
    {
        $request = new WP_REST_Request('DELETE', '/gratora/v1/admin/donors/' . $id);
        $request->set_param('confirmation', 'DELETE');

        return rest_do_request($request);
    }

    /**
     * The donations bin demands a typed DELETE before it removes one attempt.
     * Removing a donor takes their record and every attempt attached to it, so
     * it cannot be the cheaper of the two acts.
     */
    public function test_the_route_refuses_a_delete_nobody_typed_a_confirmation_for(): void
    {
        $donor = $this->donor('unconfirmed-' . uniqid() . '@example.test');

        $res = rest_do_request(new WP_REST_Request('DELETE', '/gratora/v1/admin/donors/' . (int) $donor->id));

        $this->assertSame(400, $res->get_status(), (string) wp_json_encode($res->get_data()));
        $this->assertTrue($this->exists((int) $donor->id), 'the donor survives an unconfirmed delete');
    }

    private function exists(int $id): bool
    {
        return Donor::query()->find('id', $id) !== null;
    }

    public function test_a_donor_who_never_gave_can_be_removed(): void
    {
        $donor = $this->donor('never-gave-' . uniqid() . '@example.test');

        $res = $this->deleteViaRest((int) $donor->id);

        $this->assertSame(200, $res->get_status());
        $this->assertFalse($this->exists((int) $donor->id));
    }

    public function test_a_donor_who_gave_cannot_be_removed(): void
    {
        $donor = $this->donor('gave-' . uniqid() . '@example.test');
        $this->gave($donor);

        $res = $this->deleteViaRest((int) $donor->id);

        $this->assertSame(409, $res->get_status());
        $this->assertTrue($this->exists((int) $donor->id), 'the record stays');
    }

    /** And it says where to go instead rather than only declining. */
    public function test_the_refusal_points_at_erasure(): void
    {
        $donor = $this->donor('pointed-' . uniqid() . '@example.test');
        $this->gave($donor);

        $this->assertStringContainsString(
            'rase',
            (string) ($this->deleteViaRest((int) $donor->id)->get_data()['message'] ?? '')
        );
    }

    /**
     * Stripe registers only while its credentials are stored, so a plan on an
     * absent gateway is a live mandate nothing here can stop. The refusal has
     * to name the processor and the plan, because stopping it by hand is the
     * only way out.
     */
    public function test_a_mandate_that_cannot_be_stopped_refuses_the_delete(): void
    {
        $donor = $this->donor('planned-' . uniqid() . '@example.test');

        $plan = RecurringPlan::make();
        $plan->donor_id                = (int) $donor->id;
        $plan->gateway                 = 'stripe';
        $plan->gateway_subscription_id = 'sub_del_' . bin2hex(random_bytes(3));
        $plan->amount_cents            = 1000;
        $plan->currency                = 'USD';
        $plan->interval_unit           = 'month';
        $plan->interval_count          = 1;
        $plan->status                  = 'active';
        $plan->started_at              = gmdate('Y-m-d H:i:s');
        $plan->created_at              = gmdate('Y-m-d H:i:s');
        $plan->updated_at              = gmdate('Y-m-d H:i:s');
        $plan->save();

        $res = $this->deleteViaRest((int) $donor->id);

        $this->assertSame(409, $res->get_status());
        $this->assertStringContainsString('stripe', (string) ($res->get_data()['message'] ?? ''));
        $this->assertTrue($this->exists((int) $donor->id));
        $this->assertSame(
            1,
            (int) RecurringPlan::query()->where('donor_id', (int) $donor->id)->count(),
            'the handle that can still stop the billing is kept'
        );
    }

    public function test_an_add_on_can_refuse(): void
    {
        $donor = $this->donor('vetoed-' . uniqid() . '@example.test');

        $veto = static fn () => 'They still run something of ours.';
        add_filter('gratora.donor.undeletable_reason', $veto, 10, 2);

        $res = $this->deleteViaRest((int) $donor->id);

        remove_filter('gratora.donor.undeletable_reason', $veto, 10);

        $this->assertSame(409, $res->get_status());
        $this->assertTrue($this->exists((int) $donor->id));
    }

    public function test_what_only_described_them_goes_too(): void
    {
        $email = 'tidy-' . uniqid() . '@example.test';
        $donor = $this->donor($email);
        $id    = (int) $donor->id;
        $hash  = Plugin::instance()->container->get(IdentityHasher::class)->emailHash($email);

        Plugin::instance()->container->get(\Gratora\Donors\ConsentService::class)
            ->record($id, 'email_updates', true, ['source' => 'admin']);
        Plugin::instance()->container->get(\Gratora\Donors\MagicLinkService::class)
            ->issue($id, 'donor_portal');
        Plugin::instance()->container->get(\Gratora\Donors\PendingSignupRepository::class)
            ->put($email);

        $this->deleteViaRest($id);

        $this->assertFalse($this->exists($id));
        $this->assertSame(0, Consent::query()->where('donor_id', $id)->count());
        $this->assertSame(0, MagicLinkToken::query()->where('donor_id', $id)->count());
        $this->assertSame(0, PendingSignup::query()->where('email_hash', $hash)->count(), 'a live link is not left behind');
    }

    public function test_an_erased_donor_with_no_donations_can_be_removed(): void
    {
        $donor = $this->donor('erased-' . uniqid() . '@example.test');
        $this->service()->redact($donor);

        $this->assertSame(200, $this->deleteViaRest((int) $donor->id)->get_status());
        $this->assertFalse($this->exists((int) $donor->id));
    }

    public function test_a_missing_donor_is_a_404(): void
    {
        $this->assertSame(404, $this->deleteViaRest(99999999)->get_status());
    }
}
