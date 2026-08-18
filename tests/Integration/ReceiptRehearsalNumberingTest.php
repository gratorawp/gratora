<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\Donation;
use Dono\Donors\Donor;
use Dono\Donors\DonorService;
use Dono\Foundation\Maintenance\TestDataPurger;
use Dono\Foundation\Plugin;
use Dono\Foundation\References\ReferenceGenerator;
use Dono\Foundation\Transfer\DataExporter;
use Dono\Foundation\Transfer\DataImporter;
use Dono\Receipts\Receipt;
use Dono\Vendor\Queryable\DB;

/**
 * The receipt sequence an org hands a tax authority has to be gap-free, and the
 * test-data purge is free to delete everything a rehearsal left behind. Both
 * hold only while a rehearsal draws its number from somewhere else.
 */
final class ReceiptRehearsalNumberingTest extends IntegrationTestCase
{
    public function test_a_rehearsal_does_not_take_a_number_from_the_live_sequence(): void
    {
        $live1     = $this->issue($this->paidDonation('first@example.test', false));
        $rehearsal = $this->issue($this->paidDonation('rehearsal@example.test', true));
        $live2     = $this->issue($this->paidDonation('second@example.test', false));

        $this->assertMatchesRegularExpression('/^REC-\d{4}-00001$/', $live1);
        $this->assertMatchesRegularExpression('/^REC-\d{4}-00002$/', $live2);

        // Still a complete receipt, so a form can be rehearsed end to end; it
        // just says on its face that it is one.
        $this->assertMatchesRegularExpression('/^TEST_RECEIPT-\d{4}-00001$/', $rehearsal);
    }

    public function test_purging_the_rehearsal_leaves_the_live_sequence_whole(): void
    {
        $this->issue($this->paidDonation('before@example.test', false));
        $test = $this->paidDonation('rehearsal@example.test', true);
        $this->issue($test);
        $this->issue($this->paidDonation('after@example.test', false));

        (new TestDataPurger(Plugin::instance()->container->get(DonorService::class)))->purge();

        $this->assertNull(Donation::query()->where('id', (int) $test->id)->get(), 'the rehearsal is gone');

        $numbers = [];
        foreach (Receipt::query()->orderBy('id', 'ASC')->getAll() as $receipt) {
            $numbers[] = (string) $receipt->receipt_number;
        }

        // Two live receipts, numbered 1 and 2, and a counter standing at 3: no
        // issued number is missing and none is unaccounted for.
        $this->assertCount(2, $numbers);
        $this->assertMatchesRegularExpression('/^REC-\d{4}-00001$/', $numbers[0]);
        $this->assertMatchesRegularExpression('/^REC-\d{4}-00002$/', $numbers[1]);
        $this->assertSame(3, Plugin::instance()->container->get(ReferenceGenerator::class)->peekNext('receipt'));
    }

    /**
     * The rehearsal counter has to be restored like any other, because the
     * export carries a test receipt row the same as a live one. Left at zero, a
     * restore leaves the next rehearsal minting a number the file already
     * brought in, the unique index refuses the insert, and the org can no
     * longer test a form at all: the failure is inside an async job, so nothing
     * on screen says why.
     */
    public function test_a_rehearsal_still_works_after_the_site_is_restored(): void
    {
        $first = $this->issue($this->paidDonation('rehearsal@example.test', true));
        $this->assertMatchesRegularExpression('/^TEST_RECEIPT-\d{4}-00001$/', $first);

        $export = $this->exportEverything();

        $this->wipe();
        $this->assertSame(1, Plugin::instance()->container->get(ReferenceGenerator::class)->peekNext('test_receipt'));

        Plugin::instance()->container->get(DataImporter::class)->import($export);

        $second = $this->issue($this->paidDonation('again@example.test', true));
        $this->assertMatchesRegularExpression(
            '/^TEST_RECEIPT-\d{4}-00002$/',
            $second,
            'the restore raised the rehearsal counter past what the file carried'
        );
    }

    /** @return array<string,mixed> */
    private function exportEverything(): array
    {
        $out = fopen('php://temp', 'r+');
        Plugin::instance()->container->get(DataExporter::class)->writeJson($out);
        rewind($out);
        $decoded = json_decode((string) stream_get_contents($out), true);
        fclose($out);

        $this->assertNotEmpty($decoded['tables']['dono_receipts'] ?? [], 'precondition: the file carries the rehearsal receipt');

        return ['tables' => $decoded['tables']];
    }

    private function wipe(): void
    {
        $prefix = DB::getPrefix();
        foreach (['dono_receipts', 'dono_donations', 'dono_donors'] as $table) {
            DB::raw("DELETE FROM {$prefix}{$table}");
        }
        DB::raw("DELETE FROM {$prefix}options WHERE option_name LIKE 'dono_reference_counter%'");
        wp_cache_delete('alloptions', 'options');
    }

    /** Runs the issuer for a donation and returns the number it minted. */
    private function issue(Donation $donation): string
    {
        do_action('dono.async.issue_receipt', ['donation_id' => (int) $donation->id]);

        $receipt = Receipt::query()->where('donation_id', (int) $donation->id)->get();
        $this->assertInstanceOf(Receipt::class, $receipt, 'the issuer produced no receipt');

        return (string) $receipt->receipt_number;
    }

    private function paidDonation(string $email, bool $isTest): Donation
    {
        $now = gmdate('Y-m-d H:i:s');

        $donor = Donor::make();
        $donor->email_encrypted = 'enc-' . $email;
        $donor->email_hash      = hash('sha256', $email);
        $donor->first_name      = 'Rehearsal';
        $donor->last_name       = 'Donor';
        $donor->created_at      = $now;
        $donor->updated_at      = $now;
        $donor->save();

        $donation = Donation::make();
        $donation->reference         = 'DONO-R-' . bin2hex(random_bytes(4));
        $donation->donor_id          = (int) $donor->id;
        $donation->amount_cents      = 2500;
        $donation->currency          = 'USD';
        $donation->base_amount_cents = 2500;
        $donation->gateway           = 'offline';
        $donation->status            = 'paid';
        $donation->is_test           = $isTest;
        $donation->paid_at           = $now;
        $donation->created_at        = $now;
        $donation->updated_at        = $now;
        $donation->save();

        return $donation;
    }
}
