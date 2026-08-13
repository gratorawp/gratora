<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Currency\FxBackfill;
use Dono\Currency\FxRates;
use Dono\Donations\AggregateSyncer;
use Dono\Donations\Donation;
use Dono\Donations\DonationRepository;
use Dono\Donors\Donor;
use Dono\Donors\DonorService;
use Dono\Foundation\Crypto\Crypto;
use Dono\Foundation\Helpers\Money;
use Dono\Foundation\Plugin;
use Dono\Foundation\Transfer\CsvImporter;

/**
 * Someone else's spreadsheet, which is the only thing this can assume about it.
 *
 * The promise the dry run makes is that its numbers are the numbers: an admin
 * who is told 3 will be imported and 1 skipped has to get exactly that, or the
 * preview is worse than no preview.
 *
 * The second promise is about the money. An import has to leave the same state
 * a real donation would: a base-currency snapshot, because every total sums
 * COALESCE(base_amount_cents, 0) and scores a row without one as nothing, and
 * synced donor rollups, because the columns the donor screens read are written
 * by lifecycle hooks that a direct save never fires.
 */
final class CsvImporterTest extends IntegrationTestCase
{
    private function importer(): CsvImporter
    {
        return Plugin::instance()->container->get(CsvImporter::class);
    }

    private const MAPPING = [
        'email'      => 'Email',
        'first_name' => 'First Name',
        'last_name'  => 'Last Name',
        'amount'     => 'Amount',
        'date'       => 'Date',
    ];

    /** The same columns with the amount left unmapped: a donor list. */
    private const DONORS_ONLY = [
        'email'      => 'Email',
        'first_name' => 'First Name',
        'last_name'  => 'Last Name',
    ];

    private function csv(string ...$rows): string
    {
        return "Email,First Name,Last Name,Amount,Date\n" . implode("\n", $rows) . "\n";
    }

    public function test_it_guesses_a_mapping_from_the_headers(): void
    {
        $found = $this->importer()->inspect("Email Address,Full Name,Total,Donation Date\na@b.test,A B,10,2026-01-01\n");

        $this->assertSame('Email Address', $found['mapping']['email'] ?? null);
        $this->assertSame('Full Name', $found['mapping']['full_name'] ?? null);
        $this->assertSame('Total', $found['mapping']['amount'] ?? null);
        $this->assertSame('Donation Date', $found['mapping']['date'] ?? null);
        $this->assertSame(1, $found['rows']);
    }

    public function test_it_guesses_the_donor_profile_columns_too(): void
    {
        $found = $this->importer()->inspect(
            "Email,Organization,Phone,Street Address,City,State,Zip Code,Country\n"
            . "a@b.test,Acme,555,1 Main St,Springfield,IL,62701,US\n"
        );

        $map = $found['mapping'];
        $this->assertSame('Organization', $map['company'] ?? null);
        $this->assertSame('Phone', $map['phone'] ?? null);
        $this->assertSame('Street Address', $map['address_line1'] ?? null);
        $this->assertSame('City', $map['city'] ?? null);
        $this->assertSame('State', $map['region'] ?? null);
        $this->assertSame('Zip Code', $map['postal'] ?? null);
        $this->assertSame('Country', $map['country'] ?? null);
    }

    /**
     * "State" is a synonym for both a region and a payment status, and a donor
     * export means the first. Claiming a header on its first match is what
     * stops one column being read as two different things.
     */
    public function test_a_column_is_only_guessed_for_one_field(): void
    {
        $map = $this->importer()->inspect("Email,State\na@b.test,NY\n")['mapping'];

        $this->assertSame('State', $map['region'] ?? null);
        $this->assertArrayNotHasKey('status', $map, 'an address is not a payment status');
    }

    /** The whole point of a preview. */
    public function test_a_dry_run_writes_nothing(): void
    {
        $before = Donation::query()->count();

        $result = $this->importer()->import(
            $this->csv('dry@example.test,Dry,Run,25.00,2026-01-01'),
            self::MAPPING,
            true
        );

        $this->assertSame(1, $result['donations_imported']);
        $this->assertTrue($result['dry_run']);
        $this->assertSame($before, Donation::query()->count(), 'a preview must not write');
    }

    public function test_the_dry_run_count_matches_what_the_real_run_does(): void
    {
        $csv = $this->csv(
            'match1@example.test,One,Donor,10.00,2026-01-01',
            'match2@example.test,Two,Donor,20.00,2026-01-02',
            ',No,Email,30.00,2026-01-03'
        );

        $preview = $this->importer()->import($csv, self::MAPPING, true);
        $real    = $this->importer()->import($csv, self::MAPPING, false);

        $this->assertSame($preview['donations_imported'], $real['donations_imported'], 'the preview promised this number');
        $this->assertSame($preview['skipped'], $real['skipped'], 'and these reasons');
        $this->assertSame($preview['donors_created'], $real['donors_created'], 'and this many people');
    }

    /**
     * One donor giving three times is one donor. The dry run has no database
     * writes to notice that with, so it has to track the addresses it has
     * already counted or it promises three people and produces one.
     */
    public function test_a_donor_on_several_rows_is_counted_as_one_person(): void
    {
        $csv = $this->csv(
            'repeat@example.test,Re,Peat,10.00,2026-01-01',
            'repeat@example.test,Re,Peat,20.00,2026-01-02',
            'repeat@example.test,Re,Peat,30.00,2026-01-03'
        );

        $preview = $this->importer()->import($csv, self::MAPPING, true);
        $real    = $this->importer()->import($csv, self::MAPPING, false);

        $this->assertSame(3, $preview['donations_imported'], 'three separate donations');
        $this->assertSame(1, $preview['donors_created'], 'but one person');
        $this->assertSame($preview['donors_created'], $real['donors_created']);
    }

    public function test_running_the_same_file_twice_imports_nothing_the_second_time(): void
    {
        $csv = $this->csv('twice@example.test,Run,Twice,42.00,2026-02-02');

        $first  = $this->importer()->import($csv, self::MAPPING, false);
        $second = $this->importer()->import($csv, self::MAPPING, false);

        $this->assertSame(1, $first['donations_imported']);
        $this->assertSame(0, $second['donations_imported']);
        $this->assertSame(1, $second['skipped']['already_imported'] ?? 0);
    }

    /**
     * Two identical donation rows are two donations.
     *
     * Nothing in a CSV distinguishes a donor who gave twice at the same event
     * from a row someone pasted twice, and the two mistakes are not equal:
     * an extra donation is visible and can be deleted, while a lost one is
     * silent and leaves the org's totals wrong for good. Same-day repeat giving
     * is ordinary at events and at year end.
     */
    public function test_a_donor_can_give_the_same_amount_twice_in_one_file(): void
    {
        $result = $this->importer()->import(
            $this->csv(
                'dupe@example.test,Dupe,Row,15.00,2026-03-03',
                'dupe@example.test,Dupe,Row,15.00,2026-03-03'
            ),
            self::MAPPING,
            true
        );

        $this->assertSame(2, $result['donations_imported']);
        $this->assertSame(0, $result['skipped']['duplicate_in_file'] ?? 0);
    }

    /**
     * The counting is per file position, not per import, so the same file twice
     * is still caught. This is what stops the change above from turning every
     * re-import into a pile of duplicates.
     */
    public function test_the_same_repeated_file_imported_twice_adds_nothing(): void
    {
        $csv = $this->csv(
            'twice@example.test,Twice,Over,15.00,2026-03-03',
            'twice@example.test,Twice,Over,15.00,2026-03-03'
        );

        $first  = $this->importer()->import($csv, self::MAPPING, false);
        $second = $this->importer()->import($csv, self::MAPPING, false);

        $this->assertSame(2, $first['donations_imported']);
        $this->assertSame(0, $second['donations_imported']);
        $this->assertSame(2, $second['skipped']['already_imported'] ?? 0);
    }

    /**
     * A donations row with no readable date is refused rather than dated today.
     * The old fallback stamped the import moment, which put a decade of history
     * in this afternoon's accounts and made the row's key change on every run,
     * so re-importing the file imported it all again.
     */
    public function test_a_row_without_a_readable_date_is_refused(): void
    {
        $result = $this->importer()->import(
            $this->csv('nodate@example.test,No,Date,15.00,'),
            self::MAPPING,
            false
        );

        $this->assertSame(0, $result['donations_imported']);
        $this->assertSame(1, $result['skipped']['invalid_date'] ?? 0);
    }

    public function test_a_dateless_file_imported_twice_still_imports_nothing(): void
    {
        $csv = $this->csv('nodate@example.test,No,Date,15.00,');

        $this->importer()->import($csv, self::MAPPING, false);
        $second = $this->importer()->import($csv, self::MAPPING, false);

        $this->assertSame(0, $second['donations_imported']);
    }

    public function test_rows_without_a_usable_email_or_amount_are_skipped_by_reason(): void
    {
        $result = $this->importer()->import(
            $this->csv(
                ',No,Email,10.00,2026-01-01',
                'not-an-address,Bad,Email,10.00,2026-01-01',
                'noamount@example.test,No,Amount,,2026-01-01',
                'zero@example.test,Zero,Amount,0.00,2026-01-01'
            ),
            self::MAPPING,
            true
        );

        $this->assertSame(0, $result['donations_imported']);
        $this->assertSame(1, $result['skipped']['no_email'] ?? 0);
        $this->assertSame(1, $result['skipped']['invalid_email'] ?? 0);
        $this->assertSame(2, $result['skipped']['invalid_amount'] ?? 0);
    }

    /** Erasure is a decision. A spreadsheet from before it is not consent. */
    public function test_a_donor_erased_here_is_not_brought_back(): void
    {
        $donor = Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate('erased-csv@example.test', ['first_name' => 'Gone']);
        Plugin::instance()->container->get(DonorService::class)->redact($donor);

        $result = $this->importer()->import(
            $this->csv('erased-csv@example.test,Gone,Away,99.00,2026-04-04'),
            self::MAPPING,
            false
        );

        $this->assertSame(0, $result['donations_imported']);
        $this->assertSame(1, $result['skipped']['donor_erased'] ?? 0);
    }

    /** Exports write money in more than one shape. */
    public function test_it_reads_the_amount_formats_exports_actually_produce(): void
    {
        $csv = "Email,First Name,Last Name,Amount,Date\n"
            . "a1@example.test,A,One,\"\$1,234.56\",2026-01-01\n"
            . "a2@example.test,A,Two,\"1.234,56\",2026-01-01\n"
            . "a3@example.test,A,Three,99,2026-01-01\n";

        $this->importer()->import($csv, self::MAPPING, false);

        $one = Donation::query()->where('donor_id', $this->donorId('a1@example.test'))->get();
        $two = Donation::query()->where('donor_id', $this->donorId('a2@example.test'))->get();
        $three = Donation::query()->where('donor_id', $this->donorId('a3@example.test'))->get();

        $this->assertSame(123456, (int) $one->amount_cents, 'thousands separator and symbol');
        $this->assertSame(123456, (int) $two->amount_cents, 'european decimal comma');
        $this->assertSame(9900, (int) $three->amount_cents, 'no decimals at all');
    }

    public function test_it_refuses_to_run_without_an_email_column(): void
    {
        $result = $this->importer()->import(
            $this->csv('x@example.test,No,Mapping,10.00,2026-01-01'),
            ['first_name' => 'First Name'],
            true
        );

        $this->assertSame(0, $result['donations_imported']);
        $this->assertNotSame([], $result['errors']);
    }

    /**
     * The mailing list and the gift history are usually separate exports and
     * the list arrives first, so a file with no amounts has to import the
     * people rather than be refused.
     */
    public function test_a_file_with_no_amount_column_imports_the_donors_alone(): void
    {
        $before = Donation::query()->count();

        $result = $this->importer()->import(
            $this->csv(
                'list1@example.test,List,One,,',
                'list2@example.test,List,Two,,'
            ),
            self::DONORS_ONLY,
            false
        );

        $this->assertSame('donors', $result['mode']);
        $this->assertSame(2, $result['donors_created']);
        $this->assertSame(0, $result['donations_imported']);
        $this->assertSame($before, Donation::query()->count(), 'a donor list creates no donations');
        $this->assertGreaterThan(0, $this->donorId('list1@example.test'));
    }

    /** Without an amount, the person is the thing that repeats. */
    public function test_a_donor_list_deduplicates_by_address(): void
    {
        $result = $this->importer()->import(
            $this->csv(
                'listdupe@example.test,List,Dupe,,',
                'listdupe@example.test,List,Dupe,,'
            ),
            self::DONORS_ONLY,
            true
        );

        $this->assertSame(1, $result['donors_created']);
        $this->assertSame(1, $result['skipped']['duplicate_in_file'] ?? 0);
    }

    public function test_a_donor_already_here_is_matched_rather_than_created(): void
    {
        Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate('known@example.test', ['first_name' => 'Known']);

        $result = $this->importer()->import(
            $this->csv('known@example.test,Known,Donor,,'),
            self::DONORS_ONLY,
            true
        );

        $this->assertSame(0, $result['donors_created']);
        $this->assertSame(1, $result['donors_matched']);
    }

    public function test_a_donor_list_carries_the_profile_columns(): void
    {
        $csv = "Email,Organization,Phone,Street Address,City,State,Zip Code,Country\n"
            . "profile@example.test,Acme Ltd,+15551234,1 Main St,Springfield,IL,62701,us\n";

        $this->importer()->import($csv, [
            'email'         => 'Email',
            'company'       => 'Organization',
            'phone'         => 'Phone',
            'address_line1' => 'Street Address',
            'city'          => 'City',
            'region'        => 'State',
            'postal'        => 'Zip Code',
            'country'       => 'Country',
        ], false);

        $donor  = Donor::query()->find('id', $this->donorId('profile@example.test'));
        $crypto = Plugin::instance()->container->get(Crypto::class);

        $this->assertSame('Acme Ltd', (string) $donor->company);
        $this->assertSame('US', (string) $donor->country, 'a two-letter code is upper-cased');
        $this->assertSame('+15551234', $crypto->decrypt((string) $donor->phone_encrypted));

        $address = json_decode((string) $crypto->decrypt((string) $donor->address_encrypted), true);
        $this->assertSame('1 Main St', $address['line1'] ?? null);
        $this->assertSame('Springfield', $address['city'] ?? null);
        $this->assertSame('IL', $address['region'] ?? null);
        $this->assertSame('62701', $address['postal'] ?? null);
    }

    /**
     * There is no country list on the PHP side, so "United States" cannot be
     * resolved. Storing its first two letters would file the donor under UN.
     */
    public function test_a_country_written_out_in_full_is_left_unset(): void
    {
        $this->importer()->import(
            "Email,Country\nfullcountry@example.test,United States\n",
            ['email' => 'Email', 'country' => 'Country'],
            false
        );

        $donor = Donor::query()->find('id', $this->donorId('fullcountry@example.test'));

        $this->assertEmpty($donor->country, 'better unset than wrong');
    }

    /** A CSV may enrich a donor. It may never blank one. */
    public function test_an_empty_cell_does_not_erase_what_the_donor_already_has(): void
    {
        Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate('keepme@example.test', ['first_name' => 'Keep', 'company' => 'Original Ltd']);

        $this->importer()->import(
            "Email,First Name,Organization\nkeepme@example.test,Keep,\n",
            ['email' => 'Email', 'first_name' => 'First Name', 'company' => 'Organization'],
            false
        );

        $donor = Donor::query()->find('id', $this->donorId('keepme@example.test'));

        $this->assertSame('Original Ltd', (string) $donor->company);
    }

    /**
     * The headline. An org arrives by importing its history, and the first
     * screen it looks at is the one that adds the history up.
     */
    public function test_an_imported_history_is_worth_its_own_money_in_the_totals(): void
    {
        $result = $this->importer()->import(
            $this->csv(
                'total1@example.test,Total,One,100.00,2026-01-01',
                'total2@example.test,Total,Two,250.00,2026-01-02'
            ),
            self::MAPPING,
            false
        );

        $this->assertSame(2, $result['donations_imported']);

        $agg = Plugin::instance()->container->get(DonationRepository::class)->aggregatePaidBetween();

        $this->assertSame(2, $agg['donations_count']);
        $this->assertSame(35000, $agg['amount_cents'], 'the dashboard has to see the 350.00 it was handed');
    }

    /** The org's own currency needs no rate, and converts at one. */
    public function test_a_row_in_the_base_currency_is_snapshotted_at_a_rate_of_one(): void
    {
        $this->importer()->import(
            $this->csv('base@example.test,Base,Row,42.00,2026-01-05'),
            self::MAPPING,
            false
        );

        $donation = Donation::query()->where('donor_id', $this->donorId('base@example.test'))->get();

        $this->assertSame(strtoupper(Money::defaultCurrency()), (string) $donation->base_currency);
        $this->assertSame('1.00000000', (string) $donation->fx_rate);
        $this->assertSame(4200, (int) $donation->base_amount_cents);
    }

    public function test_a_foreign_row_is_converted_at_the_rate_the_site_holds(): void
    {
        $foreign = $this->seedForeignRate(0.5);

        $this->importer()->import(
            "Email,Amount,Currency,Date\nfx@example.test,100.00,{$foreign},2026-01-06\n",
            ['email' => 'Email', 'amount' => 'Amount', 'currency' => 'Currency', 'date' => 'Date'],
            false
        );

        $donation = Donation::query()->where('donor_id', $this->donorId('fx@example.test'))->get();

        $this->assertSame(10000, (int) $donation->amount_cents, 'the file amount is kept as written');
        $this->assertSame(20000, (int) $donation->base_amount_cents, 'two base units to one foreign unit');
        $this->assertSame('2.00000000', (string) $donation->fx_rate);
    }

    /**
     * A currency the site has no rate for is imported anyway. The donation
     * happened, and refusing the row to protect a report would lose it; the
     * maintenance backfill is what brings it into the totals later, and it can
     * only find rows that were written.
     */
    public function test_a_currency_with_no_rate_is_imported_and_left_for_the_backfill(): void
    {
        $foreign = $this->seedForeignRate(null);

        $this->importer()->import(
            "Email,Amount,Currency,Date\nnorate@example.test,100.00,{$foreign},2026-01-07\n",
            ['email' => 'Email', 'amount' => 'Amount', 'currency' => 'Currency', 'date' => 'Date'],
            false
        );

        $donation = Donation::query()->where('donor_id', $this->donorId('norate@example.test'))->get();

        $this->assertSame(10000, (int) $donation->amount_cents);
        $this->assertNull($donation->base_amount_cents, 'nothing is invented at a rate nobody has');

        $waiting = array_values(array_filter(
            FxBackfill::pending(),
            static fn (array $row): bool => $row['currency'] === $foreign
        ));

        $this->assertSame(1, $waiting[0]['count'] ?? 0, 'and the maintenance screen names it');
    }

    /**
     * The donor columns are written by the donation lifecycle hooks, which a
     * straight save does not fire. last_donation_at matters twice: the donor
     * screens read it, and so does the sweep that erases inactive donors.
     */
    public function test_an_imported_history_lands_on_the_donor_lifetime_total(): void
    {
        $this->importer()->import(
            $this->csv(
                'life@example.test,Life,Time,10.00,2026-01-01',
                'life@example.test,Life,Time,20.00,2026-02-01',
                'life@example.test,Life,Time,30.00,2026-03-01'
            ),
            self::MAPPING,
            false
        );

        $donor = Donor::query()->find('id', $this->donorId('life@example.test'));

        $this->assertSame(6000, (int) $donor->total_donated_cents);
        $this->assertSame(3, (int) $donor->donations_count);
        $this->assertSame('2026-01-01 00:00:00', (string) $donor->first_donation_at);
        $this->assertSame('2026-03-01 00:00:00', (string) $donor->last_donation_at);
    }

    /** A donor who was already here keeps what they gave here. */
    public function test_importing_history_for_a_known_donor_adds_to_what_they_already_gave(): void
    {
        $donor = Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate('mixed@example.test', ['first_name' => 'Mixed']);

        $this->paidDonation((int) $donor->id, 5000, '2026-05-01 00:00:00');
        Plugin::instance()->container->get(AggregateSyncer::class)->syncDonor((int) $donor->id);

        $this->importer()->import(
            $this->csv('mixed@example.test,Mixed,Donor,15.00,2026-01-01'),
            self::MAPPING,
            false
        );

        $fresh = Donor::query()->find('id', (int) $donor->id);

        $this->assertSame(6500, (int) $fresh->total_donated_cents, 'the history joins the donation this site took');
        $this->assertSame(2, (int) $fresh->donations_count);
        $this->assertSame('2026-01-01 00:00:00', (string) $fresh->first_donation_at, 'the imported one came first');
    }

    /**
     * Seeds an FX snapshot the base currency is in and the returned currency is
     * not, unless a rate is asked for. Returns the currency to write in the file.
     */
    private function seedForeignRate(?float $perBase): string
    {
        $base    = strtoupper(Money::defaultCurrency());
        $foreign = $base === 'JPY' ? 'KRW' : 'JPY';
        $rates   = [$base => 1.0];
        if ($perBase !== null) {
            $rates[$foreign] = $perBase;
        }

        update_option(FxRates::OPTION, [
            'base'       => $base,
            'date'       => gmdate('Y-m-d'),
            'fetched_at' => gmdate('c'),
            'rates'      => $rates,
        ], false);

        return $foreign;
    }

    private function paidDonation(int $donorId, int $cents, string $paidAt): void
    {
        $donation = Donation::make();
        $donation->reference         = 'SITE-' . bin2hex(random_bytes(4));
        $donation->donor_id          = $donorId;
        $donation->amount_cents      = $cents;
        $donation->net_cents         = $cents;
        $donation->currency          = strtoupper(Money::defaultCurrency());
        $donation->base_currency     = strtoupper(Money::defaultCurrency());
        $donation->fx_rate           = '1.00000000';
        $donation->base_amount_cents = $cents;
        $donation->status            = 'paid';
        $donation->gateway           = 'manual';
        $donation->frequency         = 'one_time';
        $donation->paid_at           = $paidAt;
        $donation->created_at        = $paidAt;
        $donation->updated_at        = $paidAt;
        $donation->save();
    }

    private function donorId(string $email): int
    {
        $hasher = Plugin::instance()->container->get(\Dono\Foundation\Identity\IdentityHasher::class);
        $donor  = Donor::query()->where('email_hash', $hasher->emailHash($email))->get();

        return is_array($donor) ? (int) $donor['id'] : (int) ($donor->id ?? 0);
    }
}
