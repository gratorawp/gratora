<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Campaigns\Campaign;
use Gratora\Donations\Donation;
use Gratora\Donations\DonationRepository;
use Gratora\Donations\DonationService;
use Gratora\Donors\Donor;
use Gratora\Analytics\Event;
use Gratora\Foundation\Plugin;
use WP_REST_Request;

/**
 * Reversals remove money from aggregates without creating a Refund row, including after partial
 * refunds.
 */
final class DonationReversalTest extends IntegrationTestCase
{
    private function service(): DonationService
    {
        return Plugin::instance()->container->get(DonationService::class);
    }

    private function reload(string $reference): Donation
    {
        return Plugin::instance()->container->get(DonationRepository::class)->findByReference($reference);
    }

    private function seedCampaign(): int
    {
        $campaign = Campaign::make();
        $campaign->title      = 'Reversal';
        $campaign->slug       = 'reversal-' . uniqid();
        $campaign->status     = 'published';
        $campaign->created_at = gmdate('Y-m-d H:i:s');
        $campaign->updated_at = $campaign->created_at;
        $campaign->save();

        return (int) $campaign->id;
    }

    /** @return list<Event> */
    private function eventsFor(string $type, int $donationId): array
    {
        return Event::query()->where('type', $type)->where('donation_id', $donationId)->getAll();
    }

    private function paidDonation(?int $campaignId = null): Donation
    {
        $request = new WP_REST_Request('POST', '/gratora/v1/donations');
        $request->set_header('content-type', 'application/json');
        $request->set_body((string) wp_json_encode(array_filter([
            'email'        => 'sarah@example.com',
            'amount_cents' => 5000,
            'currency'     => 'EUR',
            'gateway'      => 'offline',
            'campaign_id'  => $campaignId,
            'profile'      => ['first_name' => 'Sarah', 'last_name' => 'Muller'],
        ])));

        $donation = $this->reload((string) rest_do_request($request)->get_data()['reference']);

        return $this->service()->confirm($donation, ['gateway_payment_id' => 'off_' . uniqid()]);
    }

    public function test_a_paid_donation_can_be_reversed(): void
    {
        $donation = $this->paidDonation();

        $this->service()->markReversed($donation, 'chargeback', 'Direct Debit Guarantee claim');

        $this->assertSame('disputed', $this->reload((string) $donation->reference)->status);
    }

    /** An admin needs to know which kind, because the two are handled differently. */
    public function test_the_kind_and_the_reason_are_both_kept(): void
    {
        $donation = $this->paidDonation();

        $this->service()->markReversed($donation, 'late_failure', 'insufficient funds');

        $reloaded = $this->reload((string) $donation->reference);
        $this->assertSame('insufficient funds', $reloaded->failure_reason);
        $this->assertSame('late_failure', $reloaded->reversal_kind);
    }

    /**
     * The whole point. Aggregates count paid and partial_refund, so moving off
     * paid takes the money out, but only if the syncers actually run.
     */
    public function test_the_money_comes_back_out_of_the_campaign_total(): void
    {
        $campaign = Campaign::query()->find('id', (int) $this->seedCampaign());
        $donation = $this->paidDonation((int) $campaign->id);

        $before = (int) Campaign::query()->find('id', (int) $campaign->id)->raised_cents;
        $this->assertSame(5000, $before, 'precondition: the donation was counted');

        $this->service()->markReversed($donation, 'chargeback', 'bank reversed it');

        $this->assertSame(0, (int) Campaign::query()->find('id', (int) $campaign->id)->raised_cents);
    }

    public function test_the_reversal_is_recorded_as_an_event(): void
    {
        $donation = $this->paidDonation();

        $this->service()->markReversed($donation, 'chargeback', 'bank reversed it');

        $this->assertNotSame([], $this->eventsFor('donation.disputed', (int) $donation->id));
    }

    public function test_the_reversal_fires_an_action_add_ons_can_hang_on(): void
    {
        $donation = $this->paidDonation();
        $seen     = [];
        add_action('gratora.donation.disputed', static function ($d, $kind) use (&$seen): void {
            $seen[] = [(int) $d->id, $kind];
        }, 10, 2);

        $this->service()->markReversed($donation, 'chargeback', 'bank reversed it');

        $this->assertSame([[(int) $donation->id, 'chargeback']], $seen);
    }

    public function test_reversing_twice_neither_double_counts_nor_double_fires(): void
    {
        $donation = $this->paidDonation();
        $this->service()->markReversed($donation, 'chargeback', 'bank reversed it');

        $fired = 0;
        add_action('gratora.donation.disputed', static function () use (&$fired): void { $fired++; }, 10, 2);
        $this->service()->markReversed($this->reload((string) $donation->reference), 'chargeback', 'again');

        $this->assertSame(0, $fired);
        $this->assertCount(1, $this->eventsFor('donation.disputed', (int) $donation->id));
    }

    /** A donation that never landed has nothing to take back. */
    public function test_a_pending_donation_is_not_reversed(): void
    {
        $request = new WP_REST_Request('POST', '/gratora/v1/donations');
        $request->set_header('content-type', 'application/json');
        $request->set_body((string) wp_json_encode([
            'email'        => 'sarah@example.com',
            'amount_cents' => 5000,
            'currency'     => 'EUR',
            'gateway'      => 'offline',
            'profile'      => ['first_name' => 'Sarah', 'last_name' => 'Muller'],
        ]));
        $donation = $this->reload((string) rest_do_request($request)->get_data()['reference']);

        $this->service()->markReversed($donation, 'chargeback', 'nothing to take back');

        $this->assertSame('pending', $this->reload((string) $donation->reference)->status);
    }

    public function test_a_partly_refunded_donation_can_still_be_reversed(): void
    {
        $donation = $this->paidDonation();
        $this->service()->recordExternalRefund($donation, 2000, 'rf_' . uniqid());

        $this->service()->markReversed($this->reload((string) $donation->reference), 'chargeback', 'bank took the rest');

        $this->assertSame('disputed', $this->reload((string) $donation->reference)->status);
    }

    public function test_a_fully_refunded_donation_is_left_alone(): void
    {
        $donation = $this->paidDonation();
        $this->service()->recordExternalRefund($donation, 5000, 'rf_' . uniqid());

        $this->service()->markReversed($this->reload((string) $donation->reference), 'chargeback', 'too late');

        $this->assertSame('refunded', $this->reload((string) $donation->reference)->status);
    }

    /**
     * The campaign total is not the only place the money sits. A reversal that
     * leaves campaign, form and fund but not the donor keeps a supporter in
     * whatever segment the charged-back money bought them, and a fundraiser on
     * a leaderboard place they did not earn.
     */
    public function test_the_money_comes_back_out_of_the_donors_own_totals(): void
    {
        $donation = $this->paidDonation();
        $donorId  = (int) $donation->donor_id;

        $this->assertSame(
            1,
            (int) Donor::query()->find('id', $donorId)->donations_count,
            'precondition: the donation counted'
        );

        $this->service()->markReversed($donation, 'chargeback', 'bank reversed it');

        $this->assertSame(0, (int) Donor::query()->find('id', $donorId)->donations_count);
    }

    /** And back again when the charity contests it and wins. */
    public function test_reinstating_puts_it_back_into_the_donors_totals(): void
    {
        $donation = $this->paidDonation();
        $donorId  = (int) $donation->donor_id;
        $this->service()->markReversed($donation, 'chargeback', 'bank reversed it');

        $this->service()->reinstateReversed($this->reload((string) $donation->reference));

        $this->assertSame(1, (int) Donor::query()->find('id', $donorId)->donations_count);
    }

    public function test_a_reversal_can_be_reinstated_when_the_charity_wins(): void
    {
        $campaign = Campaign::query()->find('id', (int) $this->seedCampaign());
        $donation = $this->paidDonation((int) $campaign->id);
        $this->service()->markReversed($donation, 'chargeback', 'bank reversed it');

        $this->service()->reinstateReversed($this->reload((string) $donation->reference));

        $reloaded = $this->reload((string) $donation->reference);
        $this->assertSame('paid', $reloaded->status);
        $this->assertNull($reloaded->reversal_kind);
        $this->assertSame(5000, (int) Campaign::query()->find('id', (int) $campaign->id)->raised_cents);
    }
}
