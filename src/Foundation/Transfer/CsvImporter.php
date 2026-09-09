<?php

declare(strict_types=1);

namespace Gratora\Foundation\Transfer;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Gratora\Currency\Currency;
use Gratora\Currency\FxRates;
use Gratora\Donations\AggregateSyncer;
use Gratora\Donations\Donation;
use Gratora\Donations\DonationQueries;
use Gratora\Donors\Donor;
use Gratora\Donors\DonorService;
use Gratora\Foundation\Helpers\Money;
use Gratora\Foundation\Identity\IdentityHasher;
use Throwable;

/**
 * Import CSVs after column mapping and preview. Without amounts, import donors only.
 * Deduplicate donations by supplied reference or a stable address/amount/date reference.
 * Exclude recurring plans without gateway subscriptions.
 *
 * @since 1.0.0
 */
final class CsvImporter
{
    /** What a column can be mapped to. */
    public const FIELDS = [
        'email'         => 'Email',
        'first_name'    => 'First name',
        'last_name'     => 'Last name',
        'full_name'     => 'Full name',
        'company'       => 'Organization',
        'phone'         => 'Phone',
        'address_line1' => 'Address',
        'address_line2' => 'Address line 2',
        'city'          => 'City',
        'region'        => 'State or region',
        'postal'        => 'Postcode',
        'country'       => 'Country (two-letter code)',
        'amount'        => 'Amount',
        'currency'      => 'Currency',
        'date'          => 'Date',
        'status'        => 'Status',
        'reference'     => 'Transaction id',
    ];

    /**
     * An address is the only thing every file has. Everything else, including
     * the amount, is optional: without it this imports the people alone.
     */
    private const REQUIRED = ['email'];

    /** The parts of an address, in the shape DonorService stores them. */
    private const ADDRESS_PARTS = [
        'address_line1' => 'line1',
        'address_line2' => 'line2',
        'city'          => 'city',
        'region'        => 'region',
        'postal'        => 'postal',
    ];

    /** Header names that usually mean a given field, lowercased. */
    private const GUESSES = [
        'email'         => ['email', 'email address', 'donor email', 'e-mail'],
        'first_name'    => ['first name', 'first', 'firstname', 'given name'],
        'last_name'     => ['last name', 'last', 'lastname', 'surname', 'family name'],
        'full_name'     => ['name', 'full name', 'donor', 'donor name'],
        'company'       => ['company', 'organisation', 'organization', 'employer'],
        'phone'         => ['phone', 'phone number', 'telephone', 'mobile'],
        'address_line1' => ['address', 'address 1', 'address line 1', 'street', 'street address'],
        'address_line2' => ['address 2', 'address line 2'],
        'city'          => ['city', 'town'],
        'region'        => ['state', 'region', 'province', 'county'],
        'postal'        => ['zip', 'zip code', 'postcode', 'postal code'],
        'country'       => ['country', 'country code'],
        'amount'        => ['amount', 'total', 'donation amount', 'gift amount', 'value'],
        'currency'      => ['currency', 'currency code'],
        'date'          => ['date', 'donation date', 'created', 'created at', 'paid at', 'timestamp'],
        'status'        => ['status'],
        'reference'     => ['transaction id', 'reference', 'id', 'donation id', 'charge id', 'payment id'],
    ];

    /**
     * The donation status vocabulary, so a file this plugin exported imports
     * back as itself. Anything outside it, and outside SYNONYMS, is refused:
     * defaulting to paid turned a disputed row into money.
     */
    private const STATUSES = ['paid', 'pending', 'processing', 'failed', 'refunded', 'partial_refund', 'disputed'];

    /** What other systems call the same states. */
    private const SYNONYMS = [
        'completed'          => 'paid',
        'complete'           => 'paid',
        'succeeded'          => 'paid',
        'success'            => 'paid',
        'settled'            => 'paid',
        'captured'           => 'paid',
        'canceled'           => 'failed',
        'cancelled'          => 'failed',
        'void'               => 'failed',
        'voided'             => 'failed',
        'chargeback'         => 'disputed',
        'charged_back'       => 'disputed',
        'dispute'            => 'disputed',
        'partially_refunded' => 'partial_refund',
    ];

    /**
     * The status a row claims, or null when it claims one this product has no
     * meaning for. An empty cell is an ordinary donor-history import.
     */
    private function status(string $raw): ?string
    {
        $value = strtolower(trim($raw));
        if ($value === '') {
            return 'paid';
        }

        $value = self::SYNONYMS[$value] ?? $value;

        return in_array($value, self::STATUSES, true) ? $value : null;
    }

    /** @since 1.0.0 */
    public function __construct(
        private DonorService $donors,
        private IdentityHasher $hasher,
        private FxRates $fx = new FxRates(),
        private AggregateSyncer $aggregates = new AggregateSyncer(),
    ) {
    }

    /**
     * Headers and the first few rows, so the admin maps against what is
     * actually in their file rather than against field names alone.
     *
     * @return array{headers:list<string>, sample:list<array<string,string>>, rows:int, mapping:array<string,string>, fields:array<string,string>}
     * @since 1.0.0
     */
    public function inspect(string $csv, int $sampleSize = 5): array
    {
        [$headers, $rows] = $this->parse($csv);

        return [
            'headers' => $headers,
            'sample'  => array_slice($rows, 0, $sampleSize),
            'rows'    => count($rows),
            'mapping' => $this->guessMapping($headers),
            'fields'  => self::FIELDS,
        ];
    }

    /**
     * @param  array<string,string> $mapping field => header
     * @return array{mode:string, donations_imported:int, donors_created:int, donors_matched:int, skipped:array<string,int>, errors:list<string>, dry_run:bool}
     * @since 1.0.0
     */
    public function import(string $csv, array $mapping, bool $dryRun = true): array
    {
        [, $rows] = $this->parse($csv);

        $mapping = array_filter($mapping);
        $mode    = isset($mapping['amount']) ? 'donations' : 'donors';

        $missing = array_values(array_diff(self::REQUIRED, array_keys($mapping)));
        if ($missing !== []) {
            return [
                'mode' => $mode, 'donations_imported' => 0, 'donors_created' => 0,
                'donors_matched' => 0, 'skipped' => [], 'dry_run' => $dryRun,
                'errors' => [sprintf(
                    /* translators: %s: comma-separated field names. */
                    __('Map a column to %s before importing.', 'gratora'),
                    implode(', ', array_map(static fn (string $f): string => self::FIELDS[$f] ?? $f, $missing))
                )],
            ];
        }

        $donations = 0;
        $created   = 0;
        $matched   = 0;
        $skipped   = [];
        $errors    = [];

        // A dry run counts what a real one would do, which means it has to see
        // the same repeats. Held here rather than read back from the database,
        // which a dry run never writes to: without this a file listing one new
        // donor on two rows would promise two donors and then create one.
        $seen      = [];
        $seenEmail = [];
        $touched   = [];

        foreach ($rows as $i => $row) {
            try {
                $outcome = $this->row($row, $mapping, $mode, $dryRun, $seen, $seenEmail);
            } catch (Throwable $e) {
                $errors[] = sprintf(
                    /* translators: 1: row number, 2: error message. */
                    __('Row %1$d: %2$s', 'gratora'),
                    $i + 2,
                    $e->getMessage()
                );
                $skipped['error'] = ($skipped['error'] ?? 0) + 1;
                continue;
            }

            if ($outcome['skip'] !== null) {
                $skipped[$outcome['skip']] = ($skipped[$outcome['skip']] ?? 0) + 1;
                continue;
            }

            if ($outcome['donation']) $donations++;
            if ($outcome['donor_created']) $created++;
            if ($outcome['donor_matched']) $matched++;
            if ($outcome['donor_id'] > 0) $touched[$outcome['donor_id']] = true;
        }

        // A donation written straight to the table fires none of the lifecycle
        // hooks the rollups hang off, so without this an imported history
        // leaves every one of those donors at 0 donations and 0 lifetime, and
        // leaves last_donation_at null for the retention sweep to read. Once
        // per donor, not per row: syncForDonor recomputes the whole donor and
        // there is no bulk form of it.
        foreach (array_keys($touched) as $donorId) {
            $this->aggregates->syncDonor((int) $donorId);
        }

        return [
            'mode'               => $mode,
            'donations_imported' => $donations,
            'donors_created'     => $created,
            'donors_matched'     => $matched,
            'skipped'            => $skipped,
            'errors'             => array_slice($errors, 0, 20),
            'dry_run'            => $dryRun,
        ];
    }

    /**
     * @param  array<string,string> $row
     * @param  array<string,string> $mapping
     * @param  array<string,bool|int> $seen  donor emails seen, or repeat counts per donation row
     * @param  array<string,bool>   $seenEmail
     * @return array{skip:?string, donation:bool, donor_created:bool, donor_matched:bool, donor_id:int}
     * @since 1.0.0
     */
    private function row(array $row, array $mapping, string $mode, bool $dryRun, array &$seen, array &$seenEmail): array
    {
        $nothing = ['skip' => null, 'donation' => false, 'donor_created' => false, 'donor_matched' => false, 'donor_id' => 0];
        $skip    = static fn (string $why): array => ['skip' => $why, 'donation' => false, 'donor_created' => false, 'donor_matched' => false, 'donor_id' => 0];

        $get = static fn (string $field): string => trim((string) ($row[$mapping[$field] ?? ''] ?? ''));

        $email = strtolower($get('email'));
        if ($email === '') {
            return $skip('no_email');
        }
        if (! is_email($email)) {
            return $skip('invalid_email');
        }

        $amountCents = null;
        $paidAt      = null;
        $status      = 'paid';
        if ($mode === 'donations') {
            $amountCents = $this->cents($get('amount'), $get('currency'));
            if ($amountCents === null) {
                return $skip('invalid_amount');
            }

            // No usable date means the row cannot say when the money arrived.
            // Falling back to the clock dates a decade of history to the
            // afternoon of the import, which is wrong in the accounts and wrong
            // for the retention sweep that reads the same column.
            $paidAt = $this->date($get('date'));
            if ($paidAt === null) {
                return $skip('invalid_date');
            }

            // Resolved here rather than at write time: the dry run returns
            // below, and would otherwise count a row the real run refuses.
            $status = $this->status($get('status'));
            if ($status === null) {
                return $skip('unknown_status');
            }
        }

        // A donor list is deduplicated by the person; a donation list is not
        // deduplicated at all within the file. One donor giving the same amount
        // twice on the same day is ordinary at an event or at year end, and
        // collapsing those loses a real donation with no way to notice.
        // Counting them instead keeps the key stable: the same file imported
        // again produces the same counts, so already_imported still catches it.
        if ($mode !== 'donations') {
            $key = 'donor:' . $email;
            if (isset($seen[$key])) {
                return $skip('duplicate_in_file');
            }
            $seen[$key] = true;
        } else {
            // The local calendar day, rendered back from the parsed stamp.
            // The raw cell moves the key when the same day is spelled another
            // way, and the UTC stamp moves it when the org corrects its
            // timezone; a day round-tripped through the site's own zone is the
            // one form that survives both, so a file always matches itself.
            $day = $this->localDay($paidAt) ?? $get('date');
            $base = $email . '|' . (int) $amountCents . '|' . $day;
            $seen[$base] = ($seen[$base] ?? 0) + 1;
            $key = $this->reference($email, (int) $amountCents, $day, $get('reference'), (int) $seen[$base]);
        }

        if ($mode === 'donations' && Donation::query()->where('reference', $key)->get() !== null) {
            return $skip('already_imported');
        }

        // Someone erased here asked to be forgotten. A spreadsheet from before
        // that is not consent to bring them back.
        $existing = Donor::query()->where('email_hash', $this->hasher->emailHash($email))->get();
        if (is_array($existing) ? ($existing['redacted_at'] ?? null) : ($existing->redacted_at ?? null)) {
            return $skip('donor_erased');
        }

        // Counted once per person even when they hold several rows, so the two
        // donor numbers describe people rather than lines in the file.
        $firstSighting = ! isset($seenEmail[$email]);
        $seenEmail[$email] = true;

        $donorCreated = $firstSighting && $existing === null;
        $donorMatched = $firstSighting && $existing !== null;

        if ($dryRun) {
            return ['skip' => null, 'donation' => $mode === 'donations', 'donor_created' => $donorCreated, 'donor_matched' => $donorMatched, 'donor_id' => 0];
        }

        [$first, $last] = $this->names($get('first_name'), $get('last_name'), $get('full_name'));

        // Only non-empty values are offered: DonorService fills blanks and
        // never overwrites, so an empty cell must not present itself as an
        // answer. A CSV can enrich a donor; it cannot blank one.
        $profile = array_filter([
            'first_name' => $first,
            'last_name'  => $last,
            'company'    => $get('company'),
            'phone'      => $get('phone'),
            'country'    => $this->countryCode($get('country')),
        ]);

        $address = [];
        foreach (self::ADDRESS_PARTS as $field => $part) {
            $value = $get($field);
            if ($value !== '') $address[$part] = $value;
        }
        $country = $this->countryCode($get('country'));
        if ($address !== [] && $country !== '') {
            $address['country'] = $country;
        }
        if ($address !== []) {
            $profile['address'] = $address;
        }

        $donor = $this->donors->findOrCreate($email, $profile);

        if ($mode !== 'donations') {
            return ['skip' => null, 'donation' => false, 'donor_created' => $donorCreated, 'donor_matched' => $donorMatched, 'donor_id' => 0];
        }

        $currency = strtoupper($get('currency')) ?: strtoupper(Money::defaultCurrency());

        $donation = Donation::make();
        $donation->reference    = $key;
        $donation->donor_id     = (int) $donor->id;
        $donation->amount_cents = (int) $amountCents;
        $donation->net_cents    = (int) $amountCents;
        $donation->currency     = $currency;
        $donation->status       = $status;
        $donation->gateway      = 'imported';
        $donation->frequency    = 'one_time';
        // Every status whose money moved, not just paid: the aggregates filter
        // on status, so a NULL date would count the row and date it nowhere.
        $donation->paid_at      = in_array($status, DonationQueries::MONEY_MOVED, true) ? $paidAt : null;
        $donation->created_at   = $paidAt;
        $donation->updated_at   = $paidAt;
        // So a row can be traced back to the file it came from, and told apart
        // from a donation this site actually took.
        $donation->source_attribution = ['source' => 'csv_import'];
        $this->valueInBase($donation);
        $donation->save();

        return ['skip' => null, 'donation' => true, 'donor_created' => $donorCreated, 'donor_matched' => $donorMatched, 'donor_id' => (int) $donor->id];
    }

    /**
     * Match live FX rules: base at unity, foreign amounts at today’s rate, unknown rates left
     * null for later backfill.
     *
     * @since 1.0.0
     */
    private function valueInBase(Donation $donation): void
    {
        $base     = strtoupper(Money::defaultCurrency());
        $currency = strtoupper((string) $donation->currency);

        $rate = $currency === $base ? 1.0 : $this->fx->rate($currency, $base);
        if ($rate === null) {
            return;
        }

        $donation->base_currency     = $base;
        $donation->fx_rate           = sprintf('%.8F', $rate);
        $donation->base_amount_cents = (int) round((int) $donation->amount_cents * $rate);
    }

    /**
     * Only a real code is accepted. Files write "United States" as often as
     * "US", and there is no country list on the PHP side to resolve it, so a
     * name is left unset rather than stored as the "UN" its first two letters
     * would produce.
     *
     * @since 1.0.0
     */
    private function countryCode(string $raw): string
    {
        $raw = trim($raw);

        return preg_match('/^[A-Za-z]{2}$/', $raw) === 1 ? strtoupper($raw) : '';
    }

    /**
     * Stable across runs so the same file matches itself. The transaction id
     * is used when the file has one, since that is the only thing the other
     * platform guaranteed to be unique.
     *
     * @since 1.0.0
     */
    private function reference(string $email, int $cents, string $date, string $external, int $occurrence = 1): string
    {
        $seed = $external !== ''
            ? 'ext:' . $external
            : sprintf('row:%s|%d|%s#%d', $email, $cents, $date, $occurrence);

        return 'CSV-' . strtoupper(substr(hash('sha256', $seed), 0, 12));
    }

    /**
     * @return array{0:string,1:string}
     * @since 1.0.0
     */
    private function names(string $first, string $last, string $full): array
    {
        if ($first !== '' || $last !== '') {
            return [$first, $last];
        }
        if ($full === '') {
            return ['', ''];
        }

        $parts = preg_split('/\s+/', $full) ?: [];
        $firstName = array_shift($parts) ?? '';

        return [$firstName, implode(' ', $parts)];
    }

    /**
     * Money as the file wrote it. Thousands separators, currency symbols and a
     * trailing minus all appear in exports, and a value that cannot be read as
     * an amount is refused rather than guessed into a zero-value donation.
     *
     * @since 1.0.0
     */
    private function cents(string $raw, string $currency = ''): ?int
    {
        $raw = trim($raw);
        if ($raw === '') return null;

        $clean = preg_replace('/[^0-9,.\-]/', '', $raw) ?? '';
        if ($clean === '' || $clean === '-') return null;

        $decimal = $this->decimalSeparator($clean, $currency);

        foreach (['.', ','] as $char) {
            if ($char !== $decimal) {
                $clean = str_replace($char, '', $clean);
            }
        }
        if ($decimal !== null) {
            $clean = str_replace($decimal, '.', $clean);
        }

        if (! is_numeric($clean)) return null;

        // Times one hundred whatever the currency: every amount is stored as
        // the major unit scaled by 100, and the currency's own minor units are
        // applied at the gateway boundary rather than here.
        $cents = (int) round(((float) $clean) * 100);

        return $cents > 0 ? $cents : null;
    }

    /**
     * Distinguish decimal and grouping separators using currency precision. Repeated separators
     * or an unsupported three-digit fraction indicate grouping.
     *
     * @since 1.0.0
     */
    private function decimalSeparator(string $clean, string $currency): ?string
    {
        $decimals = Currency::minorUnits($currency !== '' ? $currency : Money::defaultCurrency());

        $lastDot   = strrpos($clean, '.');
        $lastComma = strrpos($clean, ',');
        if ($lastDot === false && $lastComma === false) {
            return null;
        }

        $char = $lastComma !== false && ($lastDot === false || $lastComma > $lastDot) ? ',' : '.';
        $at   = $char === ',' ? $lastComma : $lastDot;

        // Two of the same character cannot both be a decimal point, so both are
        // grouping, and so is every other separator in the string.
        if (substr_count($clean, $char) > 1) {
            return null;
        }

        $tail = strlen($clean) - $at - 1;

        // Grouping always puts exactly three digits after the separator, so
        // that is the only length the two readings compete for, and what the
        // currency has room for settles it. Any other length is a decimal
        // point: an export writing 100.00 in a currency with no minor unit is
        // writing one hundred, and reading it as grouping would multiply it by
        // a hundred, which is the same failure this exists to stop.
        if ($tail === 3) {
            return $decimals === 3 ? $char : null;
        }

        return $char;
    }

    /**
     * The cell is the org's calendar, not UTC: paid_at is stored UTC and every
     * screen, receipt and year-end statement reads it back through the site
     * timezone, so a cell read as a UTC instant dates the whole imported history
     * a day early anywhere west of UTC and files a 1 January donation on the
     * previous year's tax statement.
     *
     * A date with no time is that day at noon local, which is far enough from
     * either edge that no offset in use can slide it onto a neighbouring day.
     * A cell that carries a time is that wall clock in the org's timezone unless
     * it names its own offset.
     *
     * Null when the row does not carry a date this can read.
     *
     * @since 1.0.0
     */
    private function date(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        try {
            $parsed = new DateTimeImmutable($raw, DonationQueries::siteTimezone());
        } catch (Exception $e) {
            return null;
        }

        // Ask the parsed value, not the text. Matching a colon misses every
        // other time form a real export writes: "9pm", "2130", the ISO basic
        // "20240315T093000Z" and a bare unix stamp all carry a time this then
        // overwrote with noon, losing the recorded time of day and the order
        // donations arrived in within a day.
        $fields = date_parse($raw);
        if (($fields['hour'] ?? false) === false) {
            $parsed = $parsed->setTime(12, 0);
        }

        return $parsed->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** @since 1.0.0 */
    private function localDay(?string $utc): ?string
    {
        if ($utc === null || $utc === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))
                ->setTimezone(DonationQueries::siteTimezone())
                ->format('Y-m-d');
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * One column means one thing. Synonyms overlap across fields, so without
     * claiming a header the first time it matches, "State" lands on both the
     * region and the donation status and the admin is shown a mapping that
     * reads their address as a payment state.
     *
     * @param  list<string> $headers
     * @return array<string,string>
     * @since 1.0.0
     */
    private function guessMapping(array $headers): array
    {
        $out    = [];
        $taken  = [];

        foreach (self::GUESSES as $field => $candidates) {
            foreach ($headers as $header) {
                if (isset($taken[$header])) {
                    continue;
                }
                if (in_array(strtolower(trim($header)), $candidates, true)) {
                    $out[$field]    = $header;
                    $taken[$header] = true;
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * @return array{0:list<string>, 1:list<array<string,string>>}
     * @since 1.0.0
     */
    private function parse(string $csv): array
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://temp stream, not a filesystem path; WP_Filesystem has no streaming equivalent.
        $handle = fopen('php://temp', 'r+');
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- php://temp stream, not a filesystem path; WP_Filesystem has no streaming equivalent.
        fwrite($handle, $csv);
        rewind($handle);

        $headers = fgetcsv($handle) ?: [];
        $headers = array_map(static fn ($h): string => trim((string) $h), $headers);
        // Excel writes a byte-order mark, which otherwise becomes part of the
        // first header name and stops it matching anything.
        if (isset($headers[0])) {
            $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]) ?? $headers[0];
        }

        $rows = [];
        while (($line = fgetcsv($handle)) !== false) {
            if ($line === [null] || $line === []) continue;
            $row = [];
            foreach ($headers as $i => $header) {
                $row[$header] = (string) ($line[$i] ?? '');
            }
            $rows[] = $row;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- php://temp stream, not a filesystem path; WP_Filesystem has no streaming equivalent.
        fclose($handle);

        return [$headers, $rows];
    }
}
