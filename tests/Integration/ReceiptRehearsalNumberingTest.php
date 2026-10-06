<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Donations\Donation;
use Gratora\Donors\Donor;
use Gratora\Donors\DonorService;
use Gratora\Foundation\Maintenance\TestDataPurger;
use Gratora\Foundation\Plugin;
use Gratora\Foundation\References\ReferenceGenerator;
use Gratora\Foundation\Transfer\DataExporter;
use Gratora\Foundation\Transfer\DataImporter;
use Gratora\Receipts\Receipt;
use Gratora\Vendor\Queryable\DB;
use WP_REST_Request;

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

    /**
     * One rehearsal goes the way the purge takes them all. Its receipt is on
     * the rehearsal counter, so removing it leaves the live sequence with no
     * number to explain, and refusing it left a new site unable to tidy away
     * the one test donation its first run asks for.
     */
    public function test_a_rehearsal_can_be_deleted_from_the_donations_screen_with_its_receipt(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $this->issue($this->paidDonation('live@example.test', false));
        $test = $this->paidDonation('rehearsal@example.test', true);
        $this->issue($test);

        $row = $this->rowFor($test);
        $this->assertNull($row['delete_blocked']);
        $this->assertTrue($row['deletable']);

        $this->assertSame([], $this->deleteFromTheScreen($test));

        $this->assertNull(Donation::query()->where('id', (int) $test->id)->get(), 'the rehearsal is gone');
        $this->assertSame(
            0,
            (int) Receipt::query()->where('donation_id', (int) $test->id)->count(),
            'and its receipt with it'
        );
        $this->assertSame(
            2,
            Plugin::instance()->container->get(ReferenceGenerator::class)->peekNext('receipt'),
            'the live sequence did not move'
        );
    }

    public function test_a_live_donation_keeps_its_receipt_and_says_what_to_do(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $live = $this->paidDonation('live@example.test', false);
        $this->issue($live);

        $row = $this->rowFor($live);
        $this->assertFalse($row['deletable']);
        $this->assertStringContainsString('Refund it first', (string) $row['delete_blocked']);

        $this->assertCount(1, $this->deleteFromTheScreen($live));
        $this->assertNotNull(Donation::query()->where('id', (int) $live->id)->get());
    }

    /**
     * The row as the Donations screen is given it.
     *
     * @return array<string,mixed>
     */
    private function rowFor(Donation $donation): array
    {
        $request = new WP_REST_Request('GET', '/gratora/v1/admin/donations');
        $request->set_param('include_test', true);
        $request->set_param('per_page', 100);

        foreach ((array) rest_do_request($request)->get_data() as $row) {
            if ((int) $row['id'] === (int) $donation->id) {
                return $row;
            }
        }

        $this->fail('the donation is not on the list');
    }

    /**
     * Delete permanently, as the screen asks for it.
     *
     * @return list<array<string,mixed>> the rows the server refused
     */
    private function deleteFromTheScreen(Donation $donation): array
    {
        $request = new WP_REST_Request('POST', '/gratora/v1/admin/donations/delete');
        $request->set_param('references', [(string) $donation->reference]);
        $request->set_param('confirmation', 'DELETE');
        $request->set_param('delete_donors', false);

        $response = rest_do_request($request);
        $this->assertSame(200, $response->get_status(), (string) wp_json_encode($response->get_data()));

        return $response->get_data()['refused'];
    }

    /** @return array<string,mixed> */
    private function exportEverything(): array
    {
        $out = fopen('php://temp', 'r+');
        Plugin::instance()->container->get(DataExporter::class)->writeJson($out);
        rewind($out);
        $decoded = json_decode((string) stream_get_contents($out), true);
        fclose($out);

        $this->assertNotEmpty($decoded['tables']['gratora_receipts'] ?? [], 'precondition: the file carries the rehearsal receipt');

        return ['tables' => $decoded['tables']];
    }

    private function wipe(): void
    {
        $prefix = DB::getPrefix();
        foreach (['gratora_receipts', 'gratora_donations', 'gratora_donors'] as $table) {
            DB::raw("DELETE FROM {$prefix}{$table}");
        }
        DB::raw("DELETE FROM {$prefix}options WHERE option_name LIKE 'gratora_reference_counter%'");
        wp_cache_delete('alloptions', 'options');
    }

    /** Runs the issuer for a donation and returns the number it minted. */
    private function issue(Donation $donation): string
    {
        do_action('gratora.async.issue_receipt', ['donation_id' => (int) $donation->id]);

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
        $donation->reference         = 'GRATORA-R-' . bin2hex(random_bytes(4));
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
