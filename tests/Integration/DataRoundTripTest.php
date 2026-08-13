<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Campaigns\Campaign;
use Dono\Donations\Donation;
use Dono\Donations\Refund;
use Dono\Donors\Consent;
use Dono\Donors\Donor;
use Dono\Donors\DonorService;
use Dono\Exports\DonorExporter;
use Dono\Forms\Form;
use Dono\Foundation\Plugin;
use Dono\Foundation\Transfer\DataExporter;
use Dono\Foundation\Transfer\DataImporter;
use Dono\Receipts\Receipt;
use Dono\Vendor\Queryable\DB;

/**
 * Export then import, which is the only way to know either works.
 *
 * The interesting cases are not the happy path. They are: running it twice must
 * not duplicate, an address must survive being re-sealed with a different key,
 * and a donor erased here must not be brought back by a file that still
 * remembers them.
 *
 * An erased donor is the sharp edge in both directions. Erasure keeps their
 * donations, refunds, receipts and consents on purpose and clears only the PII,
 * so a restore that cannot place the donor destroys precisely the financial
 * records the erasure preserved. The shell has to travel, its money has to
 * travel with it, and no address may come back with either.
 */
final class DataRoundTripTest extends IntegrationTestCase
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
     * @return array{created:array<string,int>, existing:array<string,int>, skipped:array<string,int>, dropped:array<string, array<string,int>>}
     */
    private function import(array $export): array
    {
        // A fresh importer per run: the id map belongs to one import, and
        // reusing it would hide a failure to match on the natural key.
        return (new DataImporter(
            Plugin::instance()->container->get(\Dono\Foundation\Crypto\Crypto::class),
            Plugin::instance()->container->get(\Dono\Foundation\Identity\IdentityHasher::class),
        ))->import($export);
    }

    private function wipeDonors(): void
    {
        $prefix = DB::getPrefix();
        DB::raw("DELETE FROM {$prefix}dono_donations");
        DB::raw("DELETE FROM {$prefix}dono_donors");
    }

    private function seedDonor(string $email, string $first = 'Round', string $last = 'Trip'): Donor
    {
        return Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate($email, ['first_name' => $first, 'last_name' => $last]);
    }

    private function wipeEverything(): void
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
        ] as $table) {
            DB::raw("DELETE FROM {$prefix}{$table}");
        }
    }

    private function seedDonation(int $donorId, string $reference, ?int $formId = null): Donation
    {
        $now = gmdate('Y-m-d H:i:s');

        $d = Donation::make();
        $d->donor_id          = $donorId;
        $d->form_id           = $formId;
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

    /**
     * A donor with money behind them: a paid donation, a partial refund of it,
     * the receipt that was issued for it, and the consent that is the lawful
     * basis for having mailed them. Erasure keeps every one of these.
     */
    private function seedDonorWithHistory(string $email, string $reference): Donor
    {
        $now   = gmdate('Y-m-d H:i:s');
        $donor = $this->seedDonor($email, 'Erased', 'Supporter');
        $d     = $this->seedDonation((int) $donor->id, $reference);

        $r = Refund::make();
        $r->donation_id       = (int) $d->id;
        $r->amount_cents      = 1000;
        $r->currency          = 'USD';
        $r->initiated_by      = 'admin';
        $r->gateway_refund_id = 're_' . $reference;
        $r->reason            = 'Donor asked for part of it back.';
        $r->occurred_at       = $now;
        $r->save();

        $receipt = Receipt::make();
        $receipt->donation_id    = (int) $d->id;
        $receipt->donor_id       = (int) $donor->id;
        $receipt->renderer_id    = 'default';
        $receipt->locale         = 'en_US';
        $receipt->receipt_number = 'RCPT-' . $reference;
        $receipt->issued_at      = $now;
        $receipt->save();

        $consent = Consent::make();
        $consent->donor_id    = (int) $donor->id;
        $consent->purpose     = 'marketing';
        $consent->granted     = true;
        $consent->source      = 'form';
        $consent->occurred_at = $now;
        $consent->save();

        return $donor;
    }

    private function redact(Donor $donor): void
    {
        Plugin::instance()->container->get(DonorService::class)->redact($donor);
    }

    public function test_a_donor_survives_the_round_trip_with_their_address(): void
    {
        $this->seedDonor('roundtrip@example.test', 'Ada', 'Lovelace');
        $export = $this->export();

        $this->wipeDonors();
        $this->assertSame(0, Donor::query()->count(), 'precondition: the donor is gone');

        $this->import($export);

        $restored = Donor::query()->where('first_name', 'Ada')->get();
        $this->assertNotNull($restored, 'the donor came back');

        $email = Plugin::instance()->container->get(DonorService::class)->decryptEmail($restored);
        $this->assertSame(
            'roundtrip@example.test',
            $email,
            'the address was re-sealed with this site key and reads back'
        );
    }

    /**
     * The property that makes a half-finished import safe to start again, and
     * an export safe to apply to a site that already has some of it.
     */
    public function test_importing_the_same_file_twice_creates_nothing_the_second_time(): void
    {
        $this->seedDonor('twice@example.test');
        $export = $this->export();

        $this->wipeDonors();

        $first  = $this->import($export);
        $before = Donor::query()->count();

        $second = $this->import($export);
        $after  = Donor::query()->count();

        $this->assertGreaterThan(0, $first['created']['dono_donors'] ?? 0, 'the first run creates');
        $this->assertSame(0, $second['created']['dono_donors'] ?? 0, 'the second creates nothing');
        $this->assertGreaterThan(0, $second['existing']['dono_donors'] ?? 0, 'and reports them as already here');
        $this->assertSame($before, $after, 'no duplicate rows');
    }

    /**
     * Someone exercised their right to be forgotten on this site. A file from
     * before that must not undo it.
     */
    public function test_an_erased_donor_is_not_resurrected(): void
    {
        $donor  = $this->seedDonor('erased@example.test', 'Gone', 'Away');
        $export = $this->export();

        Plugin::instance()->container->get(DonorService::class)->redact($donor);
        $this->assertNotNull(Donor::query()->where('id', (int) $donor->id)->get()->redacted_at);

        $this->import($export);

        $after = Donor::query()->where('id', (int) $donor->id)->get();
        $this->assertNotNull($after->redacted_at, 'still erased');
        $this->assertNull($after->first_name, 'and their name did not come back');
    }

    /** Ids are rewritten, so a donation must still point at its own donor. */
    public function test_a_donation_still_belongs_to_the_right_donor(): void
    {
        $donor = $this->seedDonor('owner@example.test', 'Owner', 'Person');

        $d = Donation::make();
        $d->donor_id     = (int) $donor->id;
        $d->reference    = 'RT-' . uniqid();
        $d->amount_cents = 4200;
        $d->currency     = 'USD';
        $d->status       = 'paid';
        $d->created_at   = gmdate('Y-m-d H:i:s');
        $d->updated_at   = $d->created_at;
        $d->save();
        $reference = $d->reference;

        $export = $this->export();
        $this->wipeDonors();
        $this->import($export);

        $donation = Donation::query()->where('reference', $reference)->get();
        $this->assertNotNull($donation, 'the donation came back');

        $owner = Donor::query()->where('id', (int) $donation->donor_id)->get();
        $this->assertNotNull($owner, 'its donor id points at a real donor');
        $this->assertSame('Owner', $owner->first_name, 'and it is the right one');
    }

    /**
     * The records erasure deliberately keeps are the ones the restore used to
     * destroy: no address means no donor, and no donor meant no donation,
     * refund, receipt or consent behind them.
     */
    public function test_an_erased_donors_donations_refunds_receipts_and_consents_all_come_back(): void
    {
        $reference = 'RT-ERASED-' . uniqid();
        $donor     = $this->seedDonorWithHistory('history@example.test', $reference);
        $this->redact($donor);

        $export = $this->export();
        $this->wipeEverything();

        $result = $this->import($export);

        $shell = Donor::query()->get();
        $this->assertNotNull($shell, 'the erased donor came back');
        $this->assertNotNull($shell->redacted_at, 'still erased');

        $donation = Donation::query()->where('reference', $reference)->get();
        $this->assertNotNull($donation, 'their donation came back');
        $this->assertSame((int) $shell->id, (int) $donation->donor_id, 'and still belongs to them');

        $refund = Refund::query()->where('gateway_refund_id', 're_' . $reference)->get();
        $this->assertNotNull($refund, 'the refund came back');
        $this->assertSame((int) $donation->id, (int) $refund->donation_id, 'against the same donation');

        $receipt = Receipt::query()->where('receipt_number', 'RCPT-' . $reference)->get();
        $this->assertNotNull($receipt, 'the receipt came back');
        $this->assertSame((int) $donation->id, (int) $receipt->donation_id, 'against the same donation');
        $this->assertSame((int) $shell->id, (int) $receipt->donor_id, 'and the same donor');

        $this->assertSame(
            1,
            Consent::query()->where('donor_id', (int) $shell->id)->count(),
            'the consent that is the lawful basis for what was mailed came back'
        );

        $this->assertSame([], $result['dropped'], 'and nothing was dropped on the way');
    }

    /** Erasure is not undone by travelling: the shell arrives as a shell. */
    public function test_a_restored_erased_donor_brings_back_no_address_and_no_name(): void
    {
        $donor = $this->seedDonorWithHistory('noaddress@example.test', 'RT-NOPII-' . uniqid());
        $this->redact($donor);

        $export = $this->export();
        $this->wipeEverything();
        $this->import($export);

        $shell = Donor::query()->get();
        $this->assertNotNull($shell);
        $this->assertNull($shell->first_name, 'no name');
        $this->assertNull($shell->last_name, 'no name');
        $this->assertSame('', $shell->email_encrypted, 'no address, and marked the way redaction marks it');
        $this->assertNull(
            Plugin::instance()->container->get(DonorService::class)->decryptEmail($shell),
            'and nothing reads one back out'
        );
    }

    /**
     * The donor CSV goes to a fulfillment house. An erased donor restored from
     * a file must land on the same side of that as one erased here.
     */
    public function test_a_restored_erased_donor_is_not_in_the_donor_csv(): void
    {
        $donor = $this->seedDonorWithHistory('notmailable@example.test', 'RT-CSV-' . uniqid());
        $this->redact($donor);

        $export = $this->export();
        $this->wipeEverything();
        $this->import($export);

        $shell = Donor::query()->get();
        $this->assertNotNull($shell, 'precondition: the shell is here');

        $live = $this->seedDonor('stillhere@example.test', 'Still', 'Here');
        $csv  = Plugin::instance()->container->get(DonorExporter::class)->toCsv(['columns' => ['donor_id']]);

        $this->assertMatchesRegularExpression('/^' . (int) $live->id . '\b/m', $csv, 'precondition: a live donor is in the file');
        $this->assertDoesNotMatchRegularExpression('/^' . (int) $shell->id . '\b/m', $csv, 'the erased one is not');
    }

    /**
     * Ids are rewritten per import, so a shell has to be recognised by
     * something else or a second run leaves a second anonymous donor behind.
     */
    public function test_restoring_an_erased_donor_twice_leaves_one_of_them(): void
    {
        $donor = $this->seedDonorWithHistory('twiceerased@example.test', 'RT-TWICE-' . uniqid());
        $this->redact($donor);

        $export = $this->export();
        $this->wipeEverything();

        $this->import($export);
        $second = $this->import($export);

        $this->assertSame(1, Donor::query()->count(), 'one donor, not two');
        $this->assertSame(0, $second['created']['dono_donors'] ?? 0, 'the second run created none');
        $this->assertSame(1, $second['existing']['dono_donors'] ?? 0, 'and reported them as already here');
    }

    /**
     * Restoring a file onto the site it came from is what a resumed or repeated
     * run is. The erased donor's address hash is peppered per install and never
     * travels, so a donation of theirs is the only handle the file and the site
     * still share.
     */
    public function test_restoring_a_file_onto_the_site_it_came_from_finds_the_erased_donor_already_here(): void
    {
        $reference = 'RT-SAMESITE-' . uniqid();
        $donor     = $this->seedDonorWithHistory('samesite@example.test', $reference);
        $this->redact($donor);

        $result = $this->import($this->export());

        $this->assertSame(1, Donor::query()->count(), 'no second anonymous donor beside them');
        $this->assertSame((int) $donor->id, (int) Donor::query()->get()->id, 'and it is the row already here');
        $this->assertSame(1, $result['existing']['dono_donors'] ?? 0, 'reported as already here, not created');
        $this->assertSame([], $result['dropped'], 'and nothing was dropped');
    }

    /**
     * Form statistics are derived, so the export leaves them behind and the
     * restore owes the site a rebuild. Without one every restored form reads
     * as nothing raised until the next donation to it.
     */
    public function test_form_statistics_are_rebuilt_by_the_restore(): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $c = Campaign::make();
        $c->title      = 'Stats Rebuild';
        $c->slug       = 'stats-rebuild-' . uniqid();
        $c->status     = 'published';
        $c->currency   = 'USD';
        $c->created_at = $now;
        $c->updated_at = $now;
        $c->save();

        $f = Form::make();
        $f->title       = 'Stats Rebuild Form';
        $f->slug        = 'stats-rebuild-form-' . uniqid();
        $f->status      = 'published';
        $f->blocks      = '';
        $f->campaign_id = (int) $c->id;
        $f->created_at  = $now;
        $f->updated_at  = $now;
        $f->save();

        $donor = $this->seedDonor('formstats@example.test', 'Form', 'Stats');
        $this->seedDonation((int) $donor->id, 'RT-STATS-' . uniqid(), (int) $f->id);

        $export = $this->export();
        $this->wipeEverything();
        $this->import($export);

        $restored = Form::query()->where('slug', $f->slug)->get();
        $this->assertNotNull($restored, 'precondition: the form came back');

        $stats = DB::table('dono_form_donation_stats')->where('form_id', (int) $restored->id)->get();
        $this->assertNotNull($stats, 'the form has statistics again');
        $this->assertSame(4200, (int) ($stats['raised_cents'] ?? 0), 'counting what actually landed');
        $this->assertSame(1, (int) ($stats['donations_count'] ?? 0), 'and how many donations landed');
    }

    /**
     * An add-on contributes its tables to the export through
     * dono.export.tables, and the importer has no contract for restoring one.
     * Being told is the difference between a partial restore and a silent one.
     */
    public function test_a_table_the_importer_does_not_know_is_reported_not_ignored(): void
    {
        $result = $this->import([
            'tables' => [
                'dono_ticket_orders' => [
                    ['id' => 1, 'reference' => 'TCK-1'],
                    ['id' => 2, 'reference' => 'TCK-2'],
                ],
            ],
        ]);

        $this->assertSame(
            2,
            $result['dropped']['dono_ticket_orders']['unsupported_table'] ?? 0,
            'both rows are named as not restored'
        );
        $this->assertSame(2, $result['skipped']['dono_ticket_orders'] ?? 0, 'and counted with the rest');
    }

    /** A campaign keeps its slug, which is what the import matches it by. */
    public function test_a_campaign_is_matched_by_slug_not_by_id(): void
    {
        $c = Campaign::make();
        $c->title      = 'Slug Match';
        $c->slug       = 'slug-match-' . uniqid();
        $c->status     = 'published';
        $c->currency   = 'USD';
        $c->created_at = gmdate('Y-m-d H:i:s');
        $c->updated_at = $c->created_at;
        $c->save();

        $export = $this->export();
        $result = $this->import($export);

        $this->assertGreaterThan(
            0,
            $result['existing']['dono_campaigns'] ?? 0,
            'the campaign is already here, matched on its slug'
        );
        $this->assertSame(
            1,
            Campaign::query()->where('slug', $c->slug)->count(),
            'and was not duplicated under a new id'
        );
    }
}
