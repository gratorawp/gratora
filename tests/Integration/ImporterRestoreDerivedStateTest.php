<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Campaigns\Campaign;
use Dono\Donations\AggregateSyncer;
use Dono\Donations\Donation;
use Dono\Donations\DonationIntent;
use Dono\Donations\DonationService;
use Dono\Donors\Donor;
use Dono\Donors\DonorService;
use Dono\Foundation\Crypto\Crypto;
use Dono\Foundation\Identity\IdentityHasher;
use Dono\Foundation\Plugin;
use Dono\Foundation\References\ReferenceGenerator;
use Dono\Foundation\Transfer\DataExporter;
use Dono\Foundation\Transfer\DataImporter;
use Dono\Funds\Fund;
use Dono\Receipts\Receipt;
use Dono\Vendor\Queryable\DB;

/**
 * What a restore has to rebuild rather than insert.
 *
 * Two kinds of state do not travel in the file. The reference counters are per
 * install, so a restored donation holding DONO-2026-00001 leaves the counter
 * behind it and the next donor mints a reference the unique index refuses. And
 * the fund, campaign and donor totals are columns on rows the restore matches
 * rather than writes, so the money that just landed is missing from every
 * screen that reads them.
 */
final class ImporterRestoreDerivedStateTest extends IntegrationTestCase
{
    /** @return array<string,mixed> */
    private function export(): array
    {
        $out = fopen('php://temp', 'r+');
        Plugin::instance()->container->get(DataExporter::class)->writeJson($out);
        rewind($out);
        $decoded = json_decode((string) stream_get_contents($out), true);
        fclose($out);

        $this->assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param  array<string,mixed> $export
     * @return array<string,mixed>
     */
    private function import(array $export): array
    {
        return (new DataImporter(
            Plugin::instance()->container->get(Crypto::class),
            Plugin::instance()->container->get(IdentityHasher::class),
        ))->import($export);
    }

    private function references(): ReferenceGenerator
    {
        return Plugin::instance()->container->get(ReferenceGenerator::class);
    }

    private function year(): int
    {
        return (int) gmdate('Y');
    }

    /** A site that has never minted a reference. */
    private function forgetCounters(): void
    {
        foreach (['donation', 'receipt', 'refund'] as $scope) {
            delete_option("dono_reference_counter_{$scope}");
            delete_option("dono_reference_counter_{$scope}_" . $this->year());
        }
    }

    private function wipeRecords(): void
    {
        $prefix = DB::getPrefix();
        foreach ([
            'dono_receipts',
            'dono_refunds',
            'dono_consents',
            'dono_donation_notes',
            'dono_donor_notes',
            'dono_donations',
            'dono_donors',
            'dono_form_donation_stats',
            'dono_forms',
            'dono_campaigns',
            'dono_funds',
        ] as $table) {
            DB::raw("DELETE FROM {$prefix}{$table}");
        }
    }

    private function seedDonor(string $email): Donor
    {
        return Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate($email, ['first_name' => 'Restore', 'last_name' => 'Probe']);
    }

    private function seedDonation(
        int $donorId,
        string $reference,
        int $cents = 4200,
        ?int $fundId = null,
        ?int $campaignId = null
    ): Donation {
        $now = gmdate('Y-m-d H:i:s');

        $d = Donation::make();
        $d->donor_id          = $donorId;
        $d->fund_id           = $fundId;
        $d->campaign_id       = $campaignId;
        $d->reference         = $reference;
        $d->amount_cents      = $cents;
        $d->net_cents         = $cents;
        $d->base_amount_cents = $cents;
        $d->base_currency     = 'USD';
        $d->currency          = 'USD';
        $d->gateway           = 'manual';
        $d->status            = 'paid';
        $d->paid_at           = $now;
        $d->created_at        = $now;
        $d->updated_at        = $now;
        $d->save();

        return $d;
    }

    private function seedFund(string $code): Fund
    {
        $now = gmdate('Y-m-d H:i:s');

        $f             = Fund::make();
        $f->code       = $code;
        $f->name       = 'Restore probe fund';
        $f->created_at = $now;
        $f->updated_at = $now;
        $f->save();

        return $f;
    }

    private function seedCampaign(string $slug): Campaign
    {
        $now = gmdate('Y-m-d H:i:s');

        $c             = Campaign::make();
        $c->title      = 'Restore probe campaign';
        $c->slug       = $slug;
        $c->status     = 'active';
        $c->currency   = 'USD';
        $c->created_at = $now;
        $c->updated_at = $now;
        $c->save();

        return $c;
    }

    /**
     * The restore-then-take-a-donation case nothing in the round-trip suite
     * exercises: the very next reference must not be one the file brought in.
     */
    public function test_the_donation_counter_clears_the_references_the_restore_brought_in(): void
    {
        $references = $this->references();
        $donor      = $this->seedDonor('counter@example.test');

        $this->seedDonation((int) $donor->id, $references->format('donation', $this->year(), 1));
        $this->seedDonation((int) $donor->id, $references->format('donation', $this->year(), 2));

        $export = $this->export();

        $this->wipeRecords();
        $this->forgetCounters();
        $this->assertSame(1, $references->peekNext('donation'), 'precondition: a site that has minted nothing');

        $this->import($export);

        $this->assertSame(3, $references->peekNext('donation'), 'the counter is past both restored references');

        // The whole point: a donor reaching the form after a restore. Without
        // the counter raised this throws on UNIQUE(reference) instead.
        $taken = Plugin::instance()->container->get(DonationService::class)->createPending(new DonationIntent(
            email: 'first-after-restore@example.test',
            amount_cents: 2500,
            currency: 'USD',
            gateway: 'offline',
        ))['donation'];

        $this->assertSame(
            $references->format('donation', $this->year(), 3),
            (string) $taken->reference,
            'the first donation after the restore is taken, and numbered after the file'
        );
    }

    /**
     * References are not consecutive on a real site: a reversed attempt or a
     * purge leaves the numbers behind it in use. Counting the rows that landed
     * would put the counter at 2 and collide on the third donation taken.
     */
    public function test_the_counter_clears_a_gap_in_the_restored_references(): void
    {
        $references = $this->references();
        $donor      = $this->seedDonor('gap@example.test');

        $this->seedDonation((int) $donor->id, $references->format('donation', $this->year(), 1));
        $this->seedDonation((int) $donor->id, $references->format('donation', $this->year(), 9));

        $export = $this->export();

        $this->wipeRecords();
        $this->forgetCounters();

        $this->import($export);

        $this->assertSame(10, $references->peekNext('donation'), 'the counter clears the highest, not the count');
    }

    /**
     * Receipt numbers are minted by the same counters and land under
     * UNIQUE(renderer_id, receipt_number), so a restore that leaves the receipt
     * counter behind stops receipts issuing at all.
     */
    public function test_the_receipt_counter_clears_the_numbers_the_restore_brought_in(): void
    {
        $references = $this->references();
        $donor      = $this->seedDonor('receipt@example.test');
        $donation   = $this->seedDonation((int) $donor->id, 'RESTORE-RECEIPT-1');

        $receipt                 = Receipt::make();
        $receipt->donation_id    = (int) $donation->id;
        $receipt->donor_id       = (int) $donor->id;
        $receipt->renderer_id    = 'generic.v1';
        $receipt->locale         = 'en_US';
        $receipt->receipt_number = $references->format('receipt', $this->year(), 7);
        $receipt->issued_at      = gmdate('Y-m-d H:i:s');
        $receipt->save();

        $export = $this->export();

        $this->wipeRecords();
        $this->forgetCounters();

        $this->import($export);

        $this->assertSame(8, $references->peekNext('receipt'), 'the receipt counter is past the restored number');
    }

    /**
     * A number from another year, or from an org numbering its references
     * differently, cannot collide with what this counter mints, so it must not
     * drag the counter up with it.
     */
    public function test_a_reference_this_counter_could_not_have_minted_leaves_it_alone(): void
    {
        $references = $this->references();
        $donor      = $this->seedDonor('foreign@example.test');

        $this->seedDonation((int) $donor->id, $references->format('donation', $this->year() - 3, 4100));
        $this->seedDonation((int) $donor->id, 'LEGACY-SYSTEM-8800');

        $export = $this->export();

        $this->wipeRecords();
        $this->forgetCounters();

        $this->import($export);

        $this->assertSame(1, $references->peekNext('donation'), 'neither reference is one this year mints');
    }

    /**
     * The canonical restore: the target already holds the fund, campaign and
     * donor the file describes, so each is matched rather than written and
     * keeps the total it had. Every screen reads those columns.
     */
    public function test_a_merge_restore_rebuilds_the_fund_campaign_and_donor_totals(): void
    {
        $fund     = $this->seedFund('annual-restore');
        $campaign = $this->seedCampaign('annual-restore');
        $donor    = $this->seedDonor('shared@example.test');

        $this->seedDonation((int) $donor->id, 'RESTORE-BIG-1', 90000, (int) $fund->id, (int) $campaign->id);

        $syncer = new AggregateSyncer();
        $syncer->syncFund((int) $fund->id);
        $syncer->syncCampaign((int) $campaign->id);
        $syncer->syncDonor((int) $donor->id);

        $export = $this->export();

        DB::raw('DELETE FROM ' . DB::getPrefix() . 'dono_donations');
        $this->seedDonation((int) $donor->id, 'RESTORE-SMALL-1', 5000, (int) $fund->id, (int) $campaign->id);
        $syncer->syncFund((int) $fund->id);
        $syncer->syncCampaign((int) $campaign->id);
        $syncer->syncDonor((int) $donor->id);

        $this->assertSame(5000, (int) Fund::query()->where('id', (int) $fund->id)->get()->raised_cents);

        $records = $this->import($export);

        $this->assertSame(1, $records['created']['dono_donations'] ?? 0, 'the restored donation landed');
        $this->assertSame(1, $records['existing']['dono_funds'] ?? 0, 'and the fund was matched, not written');

        $fundRow = Fund::query()->where('id', (int) $fund->id)->get();
        $this->assertSame(95000, (int) $fundRow->raised_cents, 'the fund holds both donations');
        $this->assertSame(2, (int) $fundRow->donations_count);
        $this->assertSame(1, (int) $fundRow->donors_count);

        $campaignRow = Campaign::query()->where('id', (int) $campaign->id)->get();
        $this->assertSame(95000, (int) $campaignRow->raised_cents, 'so does the campaign progress bar');
        $this->assertSame(2, (int) $campaignRow->donations_count);

        $donorRow = Donor::query()->where('id', (int) $donor->id)->get();
        $this->assertSame(95000, (int) $donorRow->total_donated_cents, 'and the donor lifetime total');
        $this->assertSame(2, (int) $donorRow->donations_count);
    }
}
