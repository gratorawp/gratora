<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Analytics\Event;
use Gratora\Donations\Donation;
use Gratora\Donations\DonationIntent;
use Gratora\Donations\DonationService;
use Gratora\Donations\DonationTrasher;
use Gratora\Donations\TrashOutcome;
use Gratora\Donors\Consent;
use Gratora\Donors\Donor;
use Gratora\Donors\DonorService;
use Gratora\Foundation\Plugin;
use Gratora\Recurring\RecurringPlan;
use InvalidArgumentException;
use WP_REST_Request;

/**
 * Deleting a spam donor, and what goes with their attempts.
 *
 * The cascade runs each donation through the one service that knows what a
 * donation drags behind it, rather than deleting the rows and leaving the rest.
 * A bulk delete is indistinguishable from this until you look at what it leaves
 * on the floor: the consent the attempt recorded, and no record of the removal
 * at all.
 */
final class DonorCascadeDeleteTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    /** A stopped spam attempt: what the donor gate lets through. */
    private function stoppedAttempt(?string $email = null): Donation
    {
        $donation = Plugin::instance()->container->get(DonationService::class)->createPending(new DonationIntent(
            email:        $email ?? 'cascade-' . uniqid() . '@example.test',
            amount_cents: 2500,
            currency:     'USD',
            gateway:      'offline',
            frequency:    'one_time',
        ))['donation'];

        $outcome = Plugin::instance()->container->get(DonationTrasher::class)->trash($donation);
        $this->assertSame(TrashOutcome::TRASHED, $outcome->outcome, (string) $outcome->reason);

        return $donation;
    }

    private function donors(): DonorService
    {
        return Plugin::instance()->container->get(DonorService::class);
    }

    private function donorFor(Donation $donation): Donor
    {
        return Donor::query()->find('id', (int) $donation->donor_id);
    }

    public function test_the_cascade_records_each_removal_and_the_record_outlives_the_donor(): void
    {
        $donation  = $this->stoppedAttempt();
        $reference = (string) $donation->reference;
        $donorId   = (int) $donation->donor_id;

        $this->donors()->delete($this->donorFor($donation));

        $this->assertNull(Donor::query()->find('id', $donorId));
        $this->assertNull(Donation::query()->find('id', (int) $donation->id));

        $rows = Event::query()->where('type', 'donation.deleted')->getAll();
        $this->assertCount(1, $rows, 'the cascade says what it removed');

        // It survives the donor sweep that runs in the same transaction, which
        // is the only thing left that can explain the gap in the numbering.
        $this->assertSame($reference, (string) ((array) $rows[0]->payload)['reference']);
        $this->assertNull($rows[0]->donor_id, 'and it is not filed as something the donor did');
    }

    /**
     * A bulk row delete leaves this behind. The consent is append-only, so a
     * spam attempt's tick would stand as the donor's answer for a donor who no
     * longer exists, and be restored by any later import of that address.
     */
    public function test_the_cascade_takes_the_consent_the_attempt_recorded(): void
    {
        $donation = $this->stoppedAttempt();

        $consent                     = Consent::make();
        $consent->donor_id           = (int) $donation->donor_id;
        $consent->purpose            = 'marketing';
        $consent->granted            = true;
        $consent->source             = 'donation_form';
        $consent->source_donation_id = (int) $donation->id;
        $consent->occurred_at        = gmdate('Y-m-d H:i:s');
        $consent->save();

        $this->donors()->delete($this->donorFor($donation));

        $this->assertNull(Consent::query()->where('id', (int) $consent->id)->get());
    }

    /**
     * New reach, and worth stating: each row now goes through the deleter, so
     * an add-on that refuses one refuses the whole donor delete. The refusal
     * has to leave everything standing rather than half of it.
     */
    public function test_an_add_on_refusing_one_donation_leaves_the_donor_intact(): void
    {
        $donation = $this->stoppedAttempt();
        $donorId  = (int) $donation->donor_id;

        add_filter('gratora.donation.undeletable_reason', static fn () => 'An add-on still needs this.');

        try {
            $this->donors()->delete($this->donorFor($donation));
            $this->fail('the veto should have refused the donor delete');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('add-on', $e->getMessage());
        } finally {
            remove_all_filters('gratora.donation.undeletable_reason');
        }

        $this->assertNotNull(Donor::query()->find('id', $donorId), 'nothing is half deleted');
        $this->assertNotNull(Donation::query()->find('id', (int) $donation->id));
        $this->assertSame([], Event::query()->where('type', 'donation.deleted')->getAll());
    }

    /**
     * The typed DELETE names the rows it names. A donor's other attempts, still
     * in the bin and confirmed by nobody, are not part of that answer.
     *
     * The gate is what makes this reachable rather than theoretical: a trashed
     * attempt always carries payment_stopped_at, so it can never become money
     * and never holds its donor. The donor therefore reads as somebody this one
     * attempt was the whole of, the cascade removes them, and removing a donor
     * removes every donation they have.
     */
    /**
     * The cascade is the tail of a request whose donations are already gone,
     * so a donor it cannot delete is skipped. Throwing would answer a batch
     * that half succeeded with a 500 and abandon every donor after this one.
     */
    public function test_a_mandate_that_cannot_be_stopped_does_not_take_the_batch_down(): void
    {
        $donation = $this->stoppedAttempt();
        $donorId  = (int) $donation->donor_id;

        $now = gmdate('Y-m-d H:i:s');
        $p = RecurringPlan::make();
        $p->donor_id                = $donorId;
        $p->gateway                 = 'stripe';
        $p->gateway_subscription_id = 'sub_' . uniqid();
        $p->status                  = 'active';
        $p->amount_cents            = 2500;
        $p->currency                = 'USD';
        $p->interval_unit           = 'month';
        $p->interval_count          = 1;
        $p->started_at              = $now;
        $p->created_at              = $now;
        $p->updated_at              = $now;
        $p->save();

        $request = new WP_REST_Request('POST', '/gratora/v1/admin/donations/delete');
        $request->set_param('references', [(string) $donation->reference]);
        $request->set_param('confirmation', 'DELETE');
        $request->set_param('delete_donors', true);

        $response = rest_do_request($request);

        $this->assertSame(200, $response->get_status());
        $this->assertNull(Donation::query()->find('id', (int) $donation->id), 'the donation it named is gone');
        $this->assertNotNull(Donor::query()->find('id', $donorId), 'and the donor it could not stop billing stayed');
        $this->assertSame([], $response->get_data()['donors_deleted'] ?? []);
    }

    public function test_deleting_one_binned_attempt_spares_the_donors_other_binned_attempts(): void
    {
        $email  = 'cascade-pair-' . uniqid() . '@example.test';
        $first  = $this->stoppedAttempt($email);
        $second = $this->stoppedAttempt($email);

        $this->assertSame(
            (int) $first->donor_id,
            (int) $second->donor_id,
            'the fixture needs both attempts on one donor'
        );

        $request = new WP_REST_Request('POST', '/gratora/v1/admin/donations/delete');
        $request->set_param('references', [(string) $first->reference]);
        $request->set_param('confirmation', 'DELETE');
        $request->set_param('delete_donors', true);

        $response = rest_do_request($request);
        $this->assertSame(200, $response->get_status(), (string) wp_json_encode($response->get_data()));

        $this->assertNull(Donation::query()->find('id', (int) $first->id), 'the named row goes');
        $this->assertNotNull(
            Donation::query()->find('id', (int) $second->id),
            'a row in the bin that nobody confirmed was destroyed by the donor cascade'
        );
    }
}
