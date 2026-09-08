<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Campaigns\Campaign;
use FundKit\Donations\Donation;
use FundKit\Donations\DonationNote;
use FundKit\Donations\Refund;
use FundKit\Donors\Consent;
use FundKit\Donors\Donor;
use FundKit\Donors\DonorService;
use FundKit\Exports\DonorExporter;
use FundKit\Forms\Form;
use FundKit\Funds\Fund;
use FundKit\Foundation\Plugin;
use FundKit\Foundation\Transfer\DataExporter;
use FundKit\Foundation\Transfer\DataImporter;
use FundKit\Receipts\Receipt;
use FundKit\Vendor\Queryable\DB;

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
            Plugin::instance()->container->get(\FundKit\Foundation\Crypto\Crypto::class),
            Plugin::instance()->container->get(\FundKit\Foundation\Identity\IdentityHasher::class),
        ))->import($export);
    }

    private function wipeDonors(): void
    {
        $prefix = DB::getPrefix();
        DB::raw("DELETE FROM {$prefix}fundkit_donations");
        DB::raw("DELETE FROM {$prefix}fundkit_donors");
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
            'fundkit_receipts',
            'fundkit_refunds',
            'fundkit_consents',
            'fundkit_donation_notes',
            'fundkit_donor_notes',
            'fundkit_donations',
            'fundkit_donors',
            'fundkit_form_donation_stats',
            'fundkit_forms',
            'fundkit_campaigns',
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

    public function test_importing_the_same_file_twice_creates_nothing_the_second_time(): void
    {
        $this->seedDonor('twice@example.test');
        $export = $this->export();

        $this->wipeDonors();

        $first  = $this->import($export);
        $before = Donor::query()->count();

        $second = $this->import($export);
        $after  = Donor::query()->count();

        $this->assertGreaterThan(0, $first['created']['fundkit_donors'] ?? 0, 'the first run creates');
        $this->assertSame(0, $second['created']['fundkit_donors'] ?? 0, 'the second creates nothing');
        $this->assertGreaterThan(0, $second['existing']['fundkit_donors'] ?? 0, 'and reports them as already here');
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
     * The records erasure deliberately keeps are the ones a restore is most
     * likely to lose: no address means no donor, and no donor means no
     * donation, refund, receipt or consent behind them.
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

    public function test_restoring_an_erased_donor_twice_leaves_one_of_them(): void
    {
        $donor = $this->seedDonorWithHistory('twiceerased@example.test', 'RT-TWICE-' . uniqid());
        $this->redact($donor);

        $export = $this->export();
        $this->wipeEverything();

        $this->import($export);
        $second = $this->import($export);

        $this->assertSame(1, Donor::query()->count(), 'one donor, not two');
        $this->assertSame(0, $second['created']['fundkit_donors'] ?? 0, 'the second run created none');
        $this->assertSame(1, $second['existing']['fundkit_donors'] ?? 0, 'and reported them as already here');
        $this->assertSame(
            1,
            Consent::query()->count(),
            'and the lawful basis for mailing them is one record, not the same one twice'
        );
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
        $this->assertSame(1, $result['existing']['fundkit_donors'] ?? 0, 'reported as already here, not created');
        $this->assertSame(0, $result['skipped']['fundkit_donors'] ?? 0, 'matched, not passed over');
        $this->assertSame([], $result['dropped'], 'and nothing was dropped');
    }

    /**
     * The handle that finds an erased donor again is a donation of theirs, and
     * a donation number only means anything on the site that issued it. The
     * counter behind it starts at one on every install and the default prefix
     * is the same everywhere, so the same number is held by a different person
     * on nearly every other site.
     *
     * Resolving a nameless erased donor onto whoever holds that number here is
     * worse than the loss it was meant to prevent: their consents, receipts and
     * plans would attach to a named, mailable supporter.
     */
    public function test_a_donation_number_that_belongs_to_someone_else_here_is_not_taken_as_the_erased_donor(): void
    {
        $reference = 'FUNDKIT-2026-00001';

        $jane = $this->seedDonor('jane@example.test', 'Jane', 'Regular');
        $this->seedDonation((int) $jane->id, $reference);

        $now = gmdate('Y-m-d H:i:s');

        // A file from another organization, whose donor 5 was erased there and
        // whose first-ever donation carries the same number as Jane's.
        $result = $this->import([
            'site_url' => 'https://another-charity.example',
            'tables'   => [
                'fundkit_donors' => [[
                    'id'          => 5,
                    'redacted_at' => $now,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ]],
                'fundkit_donations' => [[
                    'id'           => 9,
                    'donor_id'     => 5,
                    'reference'    => $reference,
                    'amount_cents' => 9900,
                    'net_cents'    => 9900,
                    'currency'     => 'USD',
                    'gateway'      => 'manual',
                    'status'       => 'paid',
                    'created_at'   => '2026-01-02 03:04:05',
                    'updated_at'   => '2026-01-02 03:04:05',
                ]],
                'fundkit_consents' => [[
                    'id'          => 3,
                    'donor_id'    => 5,
                    'purpose'     => 'marketing',
                    'granted'     => 1,
                    'source'      => 'form',
                    'occurred_at' => $now,
                ]],
            ],
        ]);

        $this->assertSame(2, Donor::query()->count(), 'the stranger landed beside Jane, not on top of her');

        $shell = Donor::query()->whereIsNotNull('redacted_at')->get();
        $this->assertNotNull($shell, 'the erased donor is here as their own row');
        $this->assertNotSame((int) $jane->id, (int) $shell->id, 'and it is not Jane');

        $this->assertSame(
            0,
            Consent::query()->where('donor_id', (int) $jane->id)->count(),
            'none of the erased person records attached to Jane'
        );
        $this->assertSame(
            1,
            Consent::query()->where('donor_id', (int) $shell->id)->count(),
            'they stayed with the row they belong to'
        );

        $fresh = Donor::query()->where('id', (int) $jane->id)->get();
        $this->assertSame('Jane', $fresh->first_name, 'and Jane is untouched');
        $this->assertSame(
            'jane@example.test',
            Plugin::instance()->container->get(DonorService::class)->decryptEmail($fresh),
            'address and all'
        );

        // Report cross-donor reference collisions instead of merging their dependent records.
        $this->assertSame(0, $result['existing']['fundkit_donations'] ?? 0, 'not read as the donation already here');
        $this->assertSame(
            1,
            $result['dropped']['fundkit_donations']['reference_collision'] ?? 0,
            'the operator is told, instead of it vanishing into the existing count'
        );
        $this->assertSame(1, Donation::query()->count(), 'so it did not land as its own row');
        $this->assertSame(
            (int) $jane->id,
            (int) Donation::query()->where('reference', $reference)->get()->donor_id,
            'and the only donation on this site still belongs to Jane'
        );
    }

    /**
     * A note on a donation is what a fundraiser wrote about the money, and no
     * unique index stops a second copy of it. Nothing else in the suite reaches
     * the donation-scoped key, so a typo in it would ship silently.
     */
    public function test_a_note_on_a_donation_does_not_arrive_twice(): void
    {
        $donor = $this->seedDonorWithHistory('noted@example.test', 'RT-NOTE-' . uniqid());

        $note = DonationNote::make();
        $note->donation_id    = (int) Donation::query()->where('donor_id', (int) $donor->id)->get()->id;
        $note->body_encrypted = Plugin::instance()->container
            ->get(\FundKit\Foundation\Crypto\Crypto::class)
            ->encrypt('Rang to say the address on the receipt is wrong.');
        $note->created_at     = gmdate('Y-m-d H:i:s');
        $note->updated_at     = $note->created_at;
        $note->save();

        $export = $this->export();
        $this->wipeEverything();

        $this->import($export);
        $second = $this->import($export);

        $this->assertSame(1, DonationNote::query()->count(), 'one note, not the same one twice');
        $this->assertSame(0, $second['created']['fundkit_donation_notes'] ?? 0, 'the second run created none');
        $this->assertSame(1, $second['existing']['fundkit_donation_notes'] ?? 0, 'it recognised the one it wrote');
    }

    /**
     * Withdrawing consent and giving it are the same row but for one column, and
     * a form submitted twice puts both in the same second. Restoring only one of
     * them would leave the site mailing someone who said stop.
     */
    public function test_a_grant_and_a_revoke_in_the_same_second_stay_two_records(): void
    {
        $donor    = $this->seedDonor('twominds@example.test', 'Two', 'Minds');
        $occurred = gmdate('Y-m-d H:i:s');

        foreach ([true, false] as $granted) {
            $consent = Consent::make();
            $consent->donor_id    = (int) $donor->id;
            $consent->purpose     = 'marketing';
            $consent->granted     = $granted;
            $consent->source      = 'form';
            $consent->occurred_at = $occurred;
            $consent->save();
        }

        $export = $this->export();
        $this->wipeEverything();

        $this->import($export);
        $this->import($export);

        $this->assertSame(2, Consent::query()->count(), 'both survived, and neither arrived twice');
        $this->assertSame(1, Consent::query()->where('granted', 1)->count(), 'the grant');
        $this->assertSame(1, Consent::query()->where('granted', 0)->count(), 'and the withdrawal');
    }

    /**
     * A receipt number is per site and the two counters have drifted, so a
     * receipt can be new by number and still be the second one for a donation
     * this site already has. The database refuses that pair, and the restore
     * runs in no transaction and catches nothing: the throw would end the REST
     * call half done, with no report of what landed and every re-run dying on
     * the same row.
     */
    public function test_a_receipt_for_a_donation_already_here_is_matched_not_inserted(): void
    {
        $reference = 'FUNDKIT-2026-00002';

        $jane     = $this->seedDonor('janereceipt@example.test', 'Jane', 'Regular');
        $donation = $this->seedDonation((int) $jane->id, $reference);

        $mine                 = Receipt::make();
        $mine->donation_id    = (int) $donation->id;
        $mine->donor_id       = (int) $jane->id;
        $mine->renderer_id    = 'default';
        $mine->locale         = 'en_US';
        $mine->receipt_number = 'RCPT-MINE-0001';
        $mine->issued_at      = gmdate('Y-m-d H:i:s');
        $mine->save();

        $result = $this->import([
            'site_url' => 'https://another-charity.example',
            'tables'   => [
                'fundkit_donors' => [[
                    'id'         => 5,
                    'email'      => 'stranger@example.test',
                    'first_name' => 'Stranger',
                    'created_at' => gmdate('Y-m-d H:i:s'),
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ]],
                // The SAME donation, not merely the same number: amount,
                // currency and created_at all match the row seeded above. A
                // colliding reference on a DIFFERENT donation is now reported
                // rather than matched, and that is not what this test is about.
                'fundkit_donations' => [[
                    'id'           => 9,
                    'donor_id'     => 5,
                    'reference'    => $reference,
                    'amount_cents' => 4200,
                    'net_cents'    => 4200,
                    'currency'     => 'USD',
                    'gateway'      => 'manual',
                    'status'       => 'paid',
                    'created_at'   => (string) $donation->created_at,
                    'updated_at'   => (string) $donation->updated_at,
                ]],
                'fundkit_receipts' => [[
                    'id'             => 4,
                    'donation_id'    => 9,
                    'donor_id'       => 5,
                    'renderer_id'    => 'default',
                    'locale'         => 'en_US',
                    'receipt_number' => 'RCPT-THEIRS-0417',
                    'issued_at'      => '2026-01-02 03:04:05',
                ]],
            ],
        ]);

        $this->assertSame(1, Receipt::query()->count(), 'the donation keeps the one receipt it is allowed');
        $this->assertSame(1, $result['existing']['fundkit_receipts'] ?? 0, 'the incoming one was matched onto it');
        $this->assertSame(0, $result['created']['fundkit_receipts'] ?? 0, 'and none was inserted');
    }

    /**
     * A refund taken by hand carries no gateway id, and the unique index that
     * stops one being recorded twice is nullable for exactly that reason. So a
     * repeated or resumed run is held off by nothing but the import, and every
     * copy it inserts comes off the org's totals again.
     */
    public function test_a_refund_with_no_gateway_id_does_not_arrive_twice(): void
    {
        $donor    = $this->seedDonor('offline@example.test', 'Offline', 'Supporter');
        $donation = $this->seedDonation((int) $donor->id, 'RT-OFFLINE-' . uniqid());

        $refund = Refund::make();
        $refund->donation_id  = (int) $donation->id;
        $refund->amount_cents = 1000;
        $refund->currency     = 'USD';
        $refund->initiated_by = 'admin';
        $refund->reason       = 'Paid back at the counter.';
        $refund->occurred_at  = gmdate('Y-m-d H:i:s');
        $refund->save();

        $this->assertNull(
            Refund::query()->get()->gateway_refund_id,
            'precondition: nothing outside this site names this refund'
        );

        $export = $this->export();
        $this->wipeEverything();

        $this->import($export);
        $second = $this->import($export);

        $this->assertSame(1, Refund::query()->count(), 'one refund, not the same money given back twice');
        $this->assertSame(0, $second['created']['fundkit_refunds'] ?? 0, 'the second run created none');
        $this->assertSame(1, $second['existing']['fundkit_refunds'] ?? 0, 'it recognised the one it wrote');
    }

    /**
     * A charge.refunded array is recorded in one pass and every refund in it is
     * stamped with the processing time, so two equal partial refunds of one
     * donation are byte-identical on donation, amount and second. They are
     * still two refunds, each named by the gateway, and a restore that folds
     * them into one hands the org back money it gave away.
     */
    public function test_two_gateway_refunds_of_the_same_amount_in_the_same_second_both_come_back(): void
    {
        $donor    = $this->seedDonor('twice@example.test', 'Twice', 'Refunded');
        $donation = $this->seedDonation((int) $donor->id, 'RT-TWICE-' . uniqid());
        $at       = gmdate('Y-m-d H:i:s');

        foreach (['re_first_half', 're_second_half'] as $gatewayId) {
            $refund = Refund::make();
            $refund->donation_id       = (int) $donation->id;
            $refund->gateway_refund_id = $gatewayId;
            $refund->amount_cents      = 2100;
            $refund->currency          = 'USD';
            $refund->initiated_by      = 'gateway';
            $refund->occurred_at       = $at;
            $refund->save();
        }

        $export = $this->export();
        $this->wipeEverything();

        $result = $this->import($export);

        $this->assertSame(2, Refund::query()->count(), 'both halves of the refund came back');
        $this->assertSame(2, $result['created']['fundkit_refunds'] ?? 0, 'and both were inserted');
        $this->assertSame(0, $result['existing']['fundkit_refunds'] ?? 0, 'neither was read as the other');
    }

    /**
     * A shell with no donation left to it is matched on the file it came from
     * and the row it held there, and source ids start at 1 in every file. So
     * what names the file has to be part of it, or two unrelated erased people
     * become one row.
     */
    public function test_two_files_that_do_not_name_their_origin_do_not_merge_their_erased_donors(): void
    {
        $erasedDonorFile = static function (string $exportedAt): array {
            $now = gmdate('Y-m-d H:i:s');

            return [
                'exported_at' => $exportedAt,
                'tables'      => [
                    'fundkit_donors' => [[
                        'id'          => 5,
                        'redacted_at' => $now,
                        'created_at'  => $now,
                        'updated_at'  => $now,
                    ]],
                ],
            ];
        };

        $this->import($erasedDonorFile('2026-01-02T03:04:05+00:00'));
        $this->import($erasedDonorFile('2026-06-07T08:09:10+00:00'));

        $this->assertSame(2, Donor::query()->count(), 'two erased people, two rows');
    }

    /**
     * The other way a file arrives with no address for a donor: the key that
     * sealed it did not travel. Crypto::decrypt hands back null and the
     * exporter drops the column, so nothing in the file says who they are.
     *
     * They land anyway, marked erased, because that is what the row is:
     * unidentifiable and unreachable. Dropping them would take every donation,
     * refund, receipt and consent behind them with it, and the money is what
     * the organization still has to account for.
     */
    public function test_a_donor_whose_address_this_site_cannot_read_still_brings_their_donations(): void
    {
        $reference = 'RT-KEYLOSS-' . uniqid();
        $result    = $this->restoreWithAnUnreadableAddress('keylost@example.test', $reference);

        $shell = Donor::query()->get();
        $this->assertNotNull($shell, 'the donor came back');
        $this->assertNotNull($shell->redacted_at, 'marked erased, so nothing treats them as reachable');
        $this->assertSame('', $shell->email_encrypted, 'with no address');
        $this->assertSame([], $result['dropped'], 'and nothing was dropped on the way');

        $donation = Donation::query()->where('reference', $reference)->get();
        $this->assertNotNull($donation, 'their donation came back');
        $this->assertSame((int) $shell->id, (int) $donation->donor_id, 'and still belongs to them');

        $receipt = Receipt::query()->where('receipt_number', 'RCPT-' . $reference)->get();
        $this->assertNotNull($receipt, 'so did the receipt issued for it');
    }

    /**
     * The mark is what the mailing surfaces read. Asserted through the donor
     * CSV, which is the file that goes to a fulfillment house, and with nothing
     * about the mark asserted first: whether the row is excluded is the claim,
     * and it has to fail on its own.
     */
    public function test_a_donor_whose_address_this_site_cannot_read_is_kept_out_of_the_donor_csv(): void
    {
        $this->restoreWithAnUnreadableAddress('keylostcsv@example.test', 'RT-KEYLOSS-CSV-' . uniqid());

        $shell = Donor::query()->get();
        $this->assertNotNull($shell, 'precondition: the restored donor is here');

        $live = $this->seedDonor('readable@example.test', 'Readable', 'Donor');
        $csv  = Plugin::instance()->container->get(DonorExporter::class)->toCsv(['columns' => ['donor_id']]);

        $this->assertMatchesRegularExpression(
            '/^' . (int) $live->id . '\b/m',
            $csv,
            'precondition: a live donor is in the file'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/^' . (int) $shell->id . '\b/m',
            $csv,
            'the unreadable one is not, because nothing could reach them'
        );
    }

    /**
     * @return array{created:array<string,int>, existing:array<string,int>, skipped:array<string,int>, dropped:array<string, array<string,int>>}
     */
    private function restoreWithAnUnreadableAddress(string $email, string $reference): array
    {
        $donor = $this->seedDonorWithHistory($email, $reference);

        // What key loss leaves behind: ciphertext the current key cannot open.
        DB::table('fundkit_donors')
            ->where('id', (int) $donor->id)
            ->update(['email_encrypted' => base64_encode(random_bytes(64))]);

        $export = $this->export();
        $this->assertArrayNotHasKey(
            'email',
            $export['tables']['fundkit_donors'][0],
            'precondition: no address travelled in the file'
        );

        $this->wipeEverything();

        return $this->import($export);
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

        $stats = DB::table('fundkit_form_donation_stats')->where('form_id', (int) $restored->id)->get();
        $this->assertNotNull($stats, 'the form has statistics again');
        $this->assertSame(4200, (int) ($stats['raised_cents'] ?? 0), 'counting what actually landed');
        $this->assertSame(1, (int) ($stats['donations_count'] ?? 0), 'and how many donations landed');
    }

    /**
     * An add-on contributes its tables to the export through
     * fundkit.export.tables, and the importer has no contract for restoring one.
     * Being told is the difference between a partial restore and a silent one.
     */
    public function test_a_table_the_importer_does_not_know_is_reported_not_ignored(): void
    {
        $result = $this->import([
            'tables' => [
                'fundkit_ticket_orders' => [
                    ['id' => 1, 'reference' => 'TCK-1'],
                    ['id' => 2, 'reference' => 'TCK-2'],
                ],
            ],
        ]);

        $this->assertSame(
            2,
            $result['dropped']['fundkit_ticket_orders']['unsupported_table'] ?? 0,
            'both rows are named as not restored'
        );
        $this->assertSame(2, $result['skipped']['fundkit_ticket_orders'] ?? 0, 'and counted with the rest');
    }

    /** A campaign keeps its slug, which is what the import matches it by. */
    /**
     * A campaign already here is matched on its slug and left exactly as it is,
     * which is what makes running an import twice safe. The deferred pass wrote
     * through the same id map without asking how the id got there, so it
     * repointed a live campaign at a form out of the file. A same-site round
     * trip cannot show it: the form is matched back onto the local one, so the
     * write lands on the value already there.
     */
    public function test_a_campaign_already_here_keeps_its_own_default_form(): void
    {
        [$campaign, $form] = $this->seedCampaignWithDefaultForm();
        $localFormId = (int) $form->id;

        $export = $this->export();

        // The file's form arrives as a stranger rather than as this one, which
        // is what a merge from another site looks like.
        foreach ($export['tables']['fundkit_forms'] as $i => $row) {
            if ((int) ($row['id'] ?? 0) === $localFormId) {
                $export['tables']['fundkit_forms'][$i]['slug'] = 'incoming-form-' . uniqid();
            }
        }

        $result = $this->import($export);

        $this->assertGreaterThan(
            0,
            $result['existing']['fundkit_campaigns'] ?? 0,
            'precondition: the campaign was matched rather than created'
        );
        $this->assertSame(
            $localFormId,
            (int) Campaign::query()->where('id', (int) $campaign->id)->get()->default_form_id,
            'a campaign that was never written to now opens a form out of the file'
        );
    }

    /**
     * The guard cannot be provenance. An import runs in no transaction, so a
     * run that inserted campaigns and stopped before the deferred pass leaves
     * rows whose pointer is still empty, and the next run matches those same
     * rows as existing. Those are exactly the rows the pass has to finish.
     */
    public function test_a_pointer_left_empty_by_a_stopped_run_is_still_filled(): void
    {
        [$campaign, $form] = $this->seedCampaignWithDefaultForm();

        $export = $this->export();

        Campaign::query()->where('id', (int) $campaign->id)->update(['default_form_id' => null]);

        $this->import($export);

        $this->assertSame(
            (int) $form->id,
            (int) Campaign::query()->where('id', (int) $campaign->id)->get()->default_form_id,
            'a half-finished import cannot be finished by running it again'
        );
    }

    /** @return array{0:Campaign,1:Form} */
    private function seedCampaignWithDefaultForm(): array
    {
        $now = gmdate('Y-m-d H:i:s');

        $campaign = Campaign::make();
        $campaign->title      = 'Deferred Campaign';
        $campaign->slug       = 'deferred-campaign-' . uniqid();
        $campaign->status     = 'published';
        $campaign->currency   = 'USD';
        $campaign->created_at = $now;
        $campaign->updated_at = $now;
        $campaign->save();

        $form = Form::make();
        $form->title       = 'Deferred Form';
        $form->slug        = 'deferred-form-' . uniqid();
        $form->status      = 'published';
        $form->blocks      = '';
        $form->campaign_id = (int) $campaign->id;
        $form->created_at  = $now;
        $form->updated_at  = $now;
        $form->save();

        Campaign::query()->where('id', (int) $campaign->id)->update(['default_form_id' => (int) $form->id]);

        return [$campaign, $form];
    }

    /**
     * The fund picker keeps its allowlist and its preselection inside the block
     * markup, so the column remapping cannot reach them. Every install seeds a
     * 'general' fund, so a restored file's funds land on shifted ids and a
     * stale allowlist names funds that exist and are the wrong ones: the picker
     * offers them and FormSubmissionValidator accepts a posted fund against the
     * same wrong list.
     *
     * The source ids are rewritten in the file rather than staged by shuffling
     * rows here, so the id the form must end up with is a number this test
     * knows rather than one it recomputes the way the importer does.
     */
    public function test_a_restored_form_picker_names_the_funds_here_not_the_ones_it_left(): void
    {
        $fund = $this->seedFund('rt-picker-' . uniqid());

        $form = $this->seedPickerForm([9001], '9001');

        $export = $this->export();
        $this->retargetFundInExport($export, (int) $fund->id, 9001);

        // The form has to arrive as new: a form already here is matched on its
        // slug, and a restore that matched everything would rewrite nothing.
        // The fund stays, which is the point, and its id here is not 9001.
        $this->wipeForms();
        $this->import($export);

        $attrs = $this->pickerAttrs((string) Form::query()->where('slug', $form->slug)->get()->blocks);

        $this->assertSame(
            [(int) $fund->id],
            array_map('intval', (array) $attrs['fundIds']),
            'the picker still offers whichever fund happens to hold the id it left behind'
        );
        $this->assertSame(
            (string) $fund->id,
            (string) $attrs['defaultId'],
            'the preselected fund is whichever fund happens to hold that id here'
        );
    }

    /**
     * A restriction whose funds did not survive must stay a restriction. An
     * empty fundIds means "every active fund" to FundPickerBlock and to
     * FormSubmissionValidator alike, so dropping the unmatched ids would turn a
     * form that offered one fund into one that offers all of them and switch
     * off the check that stops a crafted POST routing anywhere in the org.
     */
    public function test_a_picker_whose_funds_did_not_survive_offers_nothing_rather_than_everything(): void
    {
        $this->seedFund('rt-orphan-' . uniqid());
        $form = $this->seedPickerForm([9002], '9002');

        $export = $this->export();
        $this->wipeForms();
        $this->import($export);

        $attrs = $this->pickerAttrs((string) Form::query()->where('slug', $form->slug)->get()->blocks);

        $this->assertNotSame([], $attrs['fundIds'], 'a closed picker was reopened onto every fund in the org');
        $this->assertSame(
            [],
            array_filter(array_map('intval', (array) $attrs['fundIds']), static fn (int $id): bool => $id > 0),
            'an id that mapped to nothing was kept and now names a stranger fund'
        );
        $this->assertSame('', (string) $attrs['defaultId'], 'a preselection that mapped to nothing was kept');
    }

    private function wipeForms(): void
    {
        $prefix = DB::getPrefix();
        DB::raw("DELETE FROM {$prefix}fundkit_form_donation_stats");
        DB::raw("DELETE FROM {$prefix}fundkit_forms");
    }

    private function seedFund(string $code): Fund
    {
        $fund = Fund::make();
        $fund->code       = $code;
        $fund->name       = 'Round Trip Fund';
        $fund->is_active  = true;
        $fund->created_at = gmdate('Y-m-d H:i:s');
        $fund->updated_at = $fund->created_at;
        $fund->save();

        return $fund;
    }

    /** @param list<int> $fundIds */
    private function seedPickerForm(array $fundIds, string $defaultId): Form
    {
        $attrs = wp_json_encode(['fundIds' => $fundIds, 'defaultId' => $defaultId]);

        $campaign = Campaign::make();
        $campaign->title      = 'Picker Campaign';
        $campaign->slug       = 'picker-campaign-' . uniqid();
        $campaign->status     = 'published';
        $campaign->currency   = 'USD';
        $campaign->created_at = gmdate('Y-m-d H:i:s');
        $campaign->updated_at = $campaign->created_at;
        $campaign->save();

        $form = Form::make();
        $form->title       = 'Picker Form';
        $form->slug        = 'picker-form-' . uniqid();
        $form->status      = 'published';
        $form->campaign_id = (int) $campaign->id;
        $form->blocks     = '<!-- wp:fundkit/columns -->'
            . '<!-- wp:fundkit/fund-picker ' . $attrs . ' /-->'
            . '<!-- /wp:fundkit/columns -->';
        $form->created_at = gmdate('Y-m-d H:i:s');
        $form->updated_at = $form->created_at;
        $form->save();

        return $form;
    }

    /**
     * Give the fund a source id the picker names, so the file reads as one
     * written on another site where that fund held that number.
     *
     * @param array<string,mixed> $export
     */
    private function retargetFundInExport(array &$export, int $realId, int $sourceId): void
    {
        foreach ($export['tables']['fundkit_funds'] as $i => $row) {
            if ((int) ($row['id'] ?? 0) === $realId) {
                $export['tables']['fundkit_funds'][$i]['id'] = $sourceId;
            }
        }
    }

    /** @return array<string,mixed> */
    private function pickerAttrs(string $markup): array
    {
        foreach (parse_blocks($markup) as $block) {
            foreach ($block['innerBlocks'] ?? [] as $inner) {
                if (($inner['blockName'] ?? '') === 'fundkit/fund-picker') {
                    return (array) ($inner['attrs'] ?? []);
                }
            }
        }

        $this->fail('the restored form has no fund picker at all');
    }

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
            $result['existing']['fundkit_campaigns'] ?? 0,
            'the campaign is already here, matched on its slug'
        );
        $this->assertSame(
            1,
            Campaign::query()->where('slug', $c->slug)->count(),
            'and was not duplicated under a new id'
        );
    }
}
