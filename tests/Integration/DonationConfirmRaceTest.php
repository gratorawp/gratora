<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\Donation;
use Dono\Donations\DonationService;
use Dono\Donors\DonorService;
use Dono\Foundation\Plugin;

/**
 * confirm() must be a single-winner transition. The sync redirect-return
 * auto_confirm and the payment_intent.succeeded webhook can both load the same
 * pending donation, but only one may flip it to paid and fire the completion
 * side effects (receipt issuance, thank-you email). An in-memory status check
 * alone races between two processes that each loaded the row while pending.
 */
final class DonationConfirmRaceTest extends IntegrationTestCase
{
    public function test_second_confirm_on_a_stale_pending_copy_does_not_refire_completion(): void
    {
        $svc = Plugin::instance()->container->get(DonationService::class);
        $ref = $this->seedPendingDonation();

        // Two in-memory copies, both believing the row is still pending.
        $a = Donation::query()->where('reference', $ref)->get();
        $b = Donation::query()->where('reference', $ref)->get();

        $fired = 0;
        add_action('dono.donation.completed', function () use (&$fired): void {
            $fired++;
        });

        $svc->confirm($a, ['gateway_txn_id' => 'txn_a']);
        $svc->confirm($b, ['gateway_txn_id' => 'txn_b']); // stale copy loses the race

        $this->assertSame(1, $fired, 'completion fires exactly once across both confirms');

        $fresh = Donation::query()->where('reference', $ref)->get();
        $this->assertSame('paid', $fresh->status);
        $this->assertSame('txn_a', $fresh->gateway_txn_id, 'the winner wrote its fields; the loser did not overwrite');
    }

    public function test_markFailed_does_not_clobber_a_concurrently_paid_donation(): void
    {
        $svc = Plugin::instance()->container->get(DonationService::class);
        $ref = $this->seedPendingDonation();

        // Two in-memory copies, both believing the row is still pending.
        $paidCopy   = Donation::query()->where('reference', $ref)->get();
        $failedCopy = Donation::query()->where('reference', $ref)->get();

        // One process confirms it to paid; a concurrent stale copy then tries to
        // fail it. The conditional transition must let paid win.
        $svc->confirm($paidCopy, ['gateway_txn_id' => 'txn_ok']);
        $svc->markFailed($failedCopy, 'gateway timeout');

        $fresh = Donation::query()->where('reference', $ref)->get();
        $this->assertSame('paid', $fresh->status, 'a stale markFailed must not clobber a paid donation');
    }

    private function seedPendingDonation(): string
    {
        $donor = Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate('confirm-race@example.com', ['first_name' => 'C', 'last_name' => 'N']);

        $now = gmdate('Y-m-d H:i:s');
        $d = Donation::make();
        $d->reference         = 'DONO-CN-' . substr(md5(uniqid('', true)), 0, 8);
        $d->donor_id          = (int) $donor->id;
        $d->amount_cents      = 5000;
        $d->net_cents         = 5000;
        $d->currency          = 'USD';
        $d->base_amount_cents = 5000;
        $d->base_currency     = 'USD';
        $d->fx_rate           = '1.00000000';
        $d->gateway           = 'offline';
        $d->status            = 'pending';
        $d->is_test           = false;
        $d->created_at        = $now;
        $d->updated_at        = $now;
        $d->save();

        return $d->reference;
    }
}
