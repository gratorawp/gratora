<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Donations\Donation;
use FundKit\Donations\DonationIntent;
use FundKit\Donations\DonationService;
use FundKit\Donors\Donor;
use FundKit\Donors\DonorService;
use FundKit\Foundation\Maintenance\AbandonedPendingReaper;
use FundKit\Foundation\Plugin;
use WP_REST_Request;

/**
 * What un-erases someone: money, and only money.
 *
 * Someone who erased themselves and then gives again is the re-engagement the
 * retention window exists for. But the donation row is written before the
 * gateway is contacted, so deciding it there let any stranger who typed their
 * address un-redact them and refill their name, company, phone and address from
 * the caller's own payload, with a request that never paid.
 *
 * And declining it outright is not free either: erasure empties
 * email_encrypted, and every donor-facing email reads its address from the
 * donor row, so a returning donor would be charged and then hear nothing.
 *
 * The address waits on the attempt, and the settlement spends it.
 */
final class ErasedDonorReturnsTest extends IntegrationTestCase
{
    private function donors(): DonorService
    {
        return Plugin::instance()->container->get(DonorService::class);
    }

    private function donations(): DonationService
    {
        return Plugin::instance()->container->get(DonationService::class);
    }

    /** An erased donor, as the retention window leaves them. */
    private function erasedDonor(string $email): Donor
    {
        $donor = $this->donors()->findOrCreate($email, ['first_name' => 'Ada', 'last_name' => 'Lovelace']);
        $this->donors()->redact($donor);

        $fresh = $this->donors()->findById((int) $donor->id);
        $this->assertNotNull($fresh->redacted_at, 'the donor must actually be erased');
        $this->assertSame('', (string) $fresh->email_encrypted);

        return $fresh;
    }

    private function submit(string $email): Donation
    {
        $req = new WP_REST_Request('POST', '/fundkit/v1/donations');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode([
            'email'        => $email,
            'amount_cents' => 5000,
            'currency'     => 'USD',
            'gateway'      => 'offline',
            'frequency'    => 'one_time',
            'profile'      => ['first_name' => 'Not', 'last_name' => 'Them'],
        ]));
        $data = (array) rest_do_request($req)->get_data();
        $this->assertArrayHasKey('reference', $data, (string) wp_json_encode($data));

        return Plugin::instance()->container->get(\FundKit\Donations\DonationRepository::class)
            ->findByReference((string) $data['reference']);
    }

    private function stateOf(int $donorId): ?string
    {
        return $this->donors()->findById($donorId)->redacted_at;
    }

    public function test_an_unpaid_submission_does_not_un_erase_anyone(): void
    {
        $email = 'erased-' . uniqid() . '@example.test';
        $donor = $this->erasedDonor($email);

        $this->submit($email);

        $this->assertNotNull(
            $this->stateOf((int) $donor->id),
            'a stranger typing an address must not undo an erasure by asking'
        );
        $this->assertSame('', (string) $this->donors()->findById((int) $donor->id)->email_encrypted);
    }

    public function test_the_profile_is_not_refilled_from_the_callers_payload(): void
    {
        $email = 'erased-' . uniqid() . '@example.test';
        $donor = $this->erasedDonor($email);

        $this->submit($email);

        $after = $this->donors()->findById((int) $donor->id);
        $this->assertNull($after->first_name, 'an erased name must not come back from a stranger\'s form');
        $this->assertNull($after->last_name);
    }

    public function test_money_reunites_them_and_restores_the_address(): void
    {
        $email = 'erased-' . uniqid() . '@example.test';
        $donor = $this->erasedDonor($email);

        $donation = $this->submit($email);
        $this->assertNotNull($donation->pending_reactivation_email, 'the attempt carries the address');

        $this->donations()->confirm($donation, ['gateway_txn_id' => 'txn_paid']);

        $after = $this->donors()->findById((int) $donor->id);
        $this->assertNull($after->redacted_at, 'a donation they paid for is the re-engagement the window exists for');
        $this->assertSame(
            $email,
            $this->donors()->decryptEmail($after),
            'and they can be sent a receipt, which needs the address back'
        );
    }

    /** Spent once, so a replayed settlement cannot mean anything twice. */
    public function test_the_carried_address_is_cleared_once_used(): void
    {
        $email = 'erased-' . uniqid() . '@example.test';
        $this->erasedDonor($email);

        $donation = $this->submit($email);
        $this->donations()->confirm($donation, ['gateway_txn_id' => 'txn_paid']);

        $fresh = Plugin::instance()->container->get(\FundKit\Donations\DonationRepository::class)
            ->findById((int) $donation->id);
        $this->assertNull($fresh->pending_reactivation_email);
    }

    /** An ordinary donor never has one written at all. */
    public function test_a_donor_who_was_never_erased_carries_nothing(): void
    {
        $donation = $this->submit('ordinary-' . uniqid() . '@example.test');

        $this->assertNull($donation->pending_reactivation_email);
    }

    public function test_an_abandoned_attempt_gives_the_address_up(): void
    {
        $email = 'erased-' . uniqid() . '@example.test';
        $donor = $this->erasedDonor($email);
        $donation = $this->submit($email);

        Donation::query()
            ->where('id', (int) $donation->id)
            ->update(['created_at' => gmdate('Y-m-d H:i:s', time() - (90 * DAY_IN_SECONDS)), 'gateway' => 'stripe']);

        $c = Plugin::instance()->container;
        (new AbandonedPendingReaper($c->get(\FundKit\Async\AsyncDispatcher::class), $c->get(\FundKit\Foundation\Time\Clock::class)))->run();

        $fresh = $c->get(\FundKit\Donations\DonationRepository::class)->findById((int) $donation->id);
        $this->assertNull($fresh->pending_reactivation_email, 'an attempt that never paid does not keep it');
        $this->assertNotNull($this->stateOf((int) $donor->id));
    }

    /**
     * A second attempt, left in flight when they erase themselves again.
     *
     * Two attempts can carry the address at once. One settles and reunites
     * them, so they are a live donor again; then they erase a second time
     * while the other is still open. That attempt could otherwise settle
     * afterwards and undo the second erasure on its own.
     *
     * The erasure sweep names every field it clears on a donation, and warns
     * that a field missing from its skip check is silently protected. This is
     * the case that makes that warning true here: by then the row has nothing
     * else left on it, so the skip is what decides.
     */
    public function test_an_attempt_left_in_flight_cannot_undo_a_later_erasure(): void
    {
        $email = 'erased-' . uniqid() . '@example.test';
        $donor = $this->erasedDonor($email);

        $settles = $this->submit($email);
        $inFlight = $this->submit($email);
        $this->assertNotNull($inFlight->pending_reactivation_email);

        // The first reunites them, so they are a live donor once more.
        $this->donations()->confirm($settles, ['gateway_txn_id' => 'txn_paid']);
        $this->assertNull($this->stateOf((int) $donor->id));

        // And they leave again, with the second attempt still open.
        $this->donors()->redact($this->donors()->findById((int) $donor->id));

        $fresh = Plugin::instance()->container->get(\FundKit\Donations\DonationRepository::class)
            ->findById((int) $inFlight->id);
        $this->assertNull(
            $fresh->pending_reactivation_email,
            'an attempt still open when they erased again must not be able to bring them back'
        );
    }
}
