<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\Donation;
use Dono\Donations\DonationIntent;
use Dono\Donations\DonationService;
use Dono\Donors\Donor;
use Dono\Donors\DonorService;
use Dono\Foundation\Plugin;
use Dono\Foundation\References\ReferenceGenerator;
use Dono\Foundation\Transfer\DataExporter;
use Dono\Receipts\Receipt;
use Dono\Settings\SettingsService;
use Dono\Vendor\Queryable\DB;
use WP_REST_Request;

/**
 * A restore carries the org's numbering with it, so the references in the file
 * are printed in the file's own prefix, padding and separator. The counter that
 * mints the next one has to clear them under the numbering the site ends the
 * import with, not the numbering it started it with, or the first donation
 * after the restore reprints a reference the unique index already holds.
 */
final class ToolsImportNumberingCounterTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->wipeRecords();
    }

    private function settings(): SettingsService
    {
        return Plugin::instance()->container->get(SettingsService::class);
    }

    private function references(): ReferenceGenerator
    {
        return Plugin::instance()->container->get(ReferenceGenerator::class);
    }

    private function year(): int
    {
        return (int) gmdate('Y');
    }

    /** @param array<string,mixed> $numbering */
    private function numbering(array $numbering): void
    {
        $this->settings()->update('numbering', $numbering);
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

    /** A site that has never minted a reference. */
    private function forgetCounters(): void
    {
        $prefix = DB::getPrefix();
        DB::raw("DELETE FROM {$prefix}options WHERE option_name LIKE 'dono_reference_counter%'");
        wp_cache_delete('alloptions', 'options');
    }

    private function seedDonor(string $email): Donor
    {
        return Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate($email, ['first_name' => 'Numbering', 'last_name' => 'Probe']);
    }

    private function seedDonation(int $donorId, string $reference): Donation
    {
        $now = gmdate('Y-m-d H:i:s');

        $d                    = Donation::make();
        $d->donor_id          = $donorId;
        $d->reference         = $reference;
        $d->amount_cents      = 4200;
        $d->net_cents         = 4200;
        $d->base_amount_cents = 4200;
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

    /** @param array<string,mixed> $body */
    private function post(array $body): \WP_REST_Response
    {
        $req = new WP_REST_Request('POST', '/dono/v1/admin/tools/import');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode($body));

        return rest_do_request($req);
    }

    private function storedPrefix(): string
    {
        $stored = get_option(ReferenceGenerator::OPTION_SETTINGS, []);

        return (string) (is_array($stored) ? ($stored['prefixes']['donation'] ?? '') : '');
    }

    /**
     * The primary restore: a fresh site, a file from an org that numbers its
     * references its own way. The file's numbering lands in the same request, so
     * the counter has to be past what the file printed in it.
     */
    public function test_the_counter_clears_references_printed_in_the_file_own_numbering(): void
    {
        $this->numbering(['prefixes' => ['donation' => 'GIVE'], 'padding' => 6]);

        $references = $this->references();
        $first      = $references->format('donation', $this->year(), 1);
        $second     = $references->format('donation', $this->year(), 2);
        $this->assertSame('GIVE' . '-' . $this->year() . '-000001', $first, 'precondition: the source numbering is in force');

        $donor = $this->seedDonor('numbering-source@example.test');
        $this->seedDonation((int) $donor->id, $first);
        $this->seedDonation((int) $donor->id, $second);

        $export = $this->export();
        $this->assertSame(
            'GIVE',
            (string) ($export['settings'][ReferenceGenerator::OPTION_SETTINGS]['prefixes']['donation'] ?? ''),
            'precondition: the file carries the numbering its references were minted under'
        );

        // The target: a fresh site on stock numbering that has minted nothing.
        $this->wipeRecords();
        $this->forgetCounters();
        $this->numbering(ReferenceGenerator::DEFAULT_SETTINGS);
        $this->assertSame('DONO', $this->storedPrefix(), 'precondition: the target numbers its references differently');
        $this->assertSame(1, $references->peekNext('donation'), 'precondition: a site that has minted nothing');

        $res = $this->post($export);
        $this->assertSame(200, $res->get_status(), 'the restore itself succeeds');

        $this->assertSame('GIVE', $this->storedPrefix(), 'the file numbering is what the site ends the request on');
        $this->assertSame(3, $references->peekNext('donation'), 'the counter is past both restored references');

        // The whole point: the first donor to reach the form after the restore.
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
     * Receipt numbers come off the same counters under
     * UNIQUE(renderer_id, receipt_number), and carry the file's receipt prefix
     * for the same reason.
     */
    public function test_the_receipt_counter_clears_numbers_printed_in_the_file_own_numbering(): void
    {
        $this->numbering(['prefixes' => ['donation' => 'GIVE', 'receipt' => 'RCPT'], 'padding' => 6]);

        $references = $this->references();
        $donor      = $this->seedDonor('numbering-receipt@example.test');
        $donation   = $this->seedDonation((int) $donor->id, $references->format('donation', $this->year(), 1));

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
        $this->numbering(ReferenceGenerator::DEFAULT_SETTINGS);

        $this->assertSame(200, $this->post($export)->get_status());

        $this->assertSame(8, $references->peekNext('receipt'), 'the receipt counter is past the restored number');
    }

    /**
     * A file that carries no numbering group leaves the site on its own, so the
     * counter is raised against the numbering already installed.
     */
    public function test_a_file_without_a_numbering_group_is_read_in_the_site_own_numbering(): void
    {
        $references = $this->references();
        $donor      = $this->seedDonor('numbering-absent@example.test');
        $this->seedDonation((int) $donor->id, $references->format('donation', $this->year(), 5));

        $export = $this->export();
        unset($export['settings'][ReferenceGenerator::OPTION_SETTINGS]);

        $this->wipeRecords();
        $this->forgetCounters();

        $this->assertSame(200, $this->post($export)->get_status());

        $this->assertSame(6, $references->peekNext('donation'), 'the counter clears the restored reference');
        $this->assertSame('DONO', $this->storedPrefix(), 'and the numbering is untouched');
    }
}
