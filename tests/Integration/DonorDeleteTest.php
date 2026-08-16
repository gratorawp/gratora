<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\Donation;
use Dono\Donors\Consent;
use Dono\Donors\Donor;
use Dono\Donors\DonorService;
use Dono\Donors\MagicLinkToken;
use Dono\Donors\PendingSignup;
use Dono\Foundation\Identity\IdentityHasher;
use Dono\Foundation\Plugin;
use Dono\Recurring\RecurringPlan;
use WP_REST_Request;

/**
 * Removing a donor who should never have been a record.
 *
 * Erasure and deletion answer different questions. A donor who gave keeps their
 * row: the donation is a financial record that has to survive, and a donation
 * whose donor is missing is a broken one, so erasure wipes the person and
 * deliberately leaves the row behind. Deletion is for the other case, the one
 * the admin could previously do nothing about: an address that never became a
 * donation, sitting in the list with no way to get rid of it.
 */
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
        return rest_do_request(new WP_REST_Request('DELETE', '/dono/v1/admin/donors/' . $id));
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

    /**
     * The refusal that matters. Money has to survive, and a donation pointing at
     * a donor who is gone is a record nobody can reconcile.
     */
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

    /** A live mandate is money in motion, whether or not it has charged yet. */
    public function test_a_donor_on_a_recurring_plan_cannot_be_removed(): void
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

        $this->assertSame(409, $this->deleteViaRest((int) $donor->id)->get_status());
        $this->assertTrue($this->exists((int) $donor->id));
    }

    /**
     * Core cannot see what an add-on hangs off a donor, so the add-on says so.
     * dono-p2p uses this to keep a donor whose fundraiser page is still public.
     */
    public function test_an_add_on_can_refuse(): void
    {
        $donor = $this->donor('vetoed-' . uniqid() . '@example.test');

        $veto = static fn () => 'They still run something of ours.';
        add_filter('dono.donor.undeletable_reason', $veto, 10, 2);

        $res = $this->deleteViaRest((int) $donor->id);

        remove_filter('dono.donor.undeletable_reason', $veto, 10);

        $this->assertSame(409, $res->get_status());
        $this->assertTrue($this->exists((int) $donor->id));
    }

    /** Nothing describing them is left pointing at a donor who is gone. */
    public function test_what_only_described_them_goes_too(): void
    {
        $email = 'tidy-' . uniqid() . '@example.test';
        $donor = $this->donor($email);
        $id    = (int) $donor->id;
        $hash  = Plugin::instance()->container->get(IdentityHasher::class)->emailHash($email);

        Plugin::instance()->container->get(\Dono\Donors\ConsentService::class)
            ->record($id, 'email_updates', true, ['source' => 'admin']);
        Plugin::instance()->container->get(\Dono\Donors\MagicLinkService::class)
            ->issue($id, 'donor_portal');
        Plugin::instance()->container->get(\Dono\Donors\PendingSignupRepository::class)
            ->put($email);

        $this->deleteViaRest($id);

        $this->assertFalse($this->exists($id));
        $this->assertSame(0, Consent::query()->where('donor_id', $id)->count());
        $this->assertSame(0, MagicLinkToken::query()->where('donor_id', $id)->count());
        $this->assertSame(0, PendingSignup::query()->where('email_hash', $hash)->count(), 'a live link is not left behind');
    }

    /** An erased donor with nothing to keep can still be tidied away. */
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
