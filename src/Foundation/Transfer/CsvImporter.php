<?php

declare(strict_types=1);

namespace Dono\Foundation\Transfer;

use Dono\Donations\Donation;
use Dono\Donors\Donor;
use Dono\Donors\DonorService;
use Dono\Foundation\Helpers\Money;
use Dono\Foundation\Identity\IdentityHasher;
use Throwable;

/**
 * Brings donors and donations in from anyone else's CSV.
 *
 * The Give importer reads a database it understands. This reads a file it has
 * never seen, so the admin says which column means what, and nothing is written
 * until they have seen what that mapping would do.
 *
 * Donations carry no external id column, and reference is the unique one, so
 * that is what makes a row identifiable. A file with its own transaction ids
 * uses those; a file without gets one derived from the address, the amount and
 * the date, which is stable enough that importing the same file twice matches
 * rather than duplicates.
 *
 * Deliberately donors and donations only. Recurring plans are the tempting
 * next field and the wrong one: a plan imported without its gateway
 * subscription looks live in the admin and never bills, which is worse than
 * not having it.
 *
 * @version 1.0.0
 */
final class CsvImporter
{
    /** What a column can be mapped to. */
    public const FIELDS = [
        'email'      => 'Email',
        'first_name' => 'First name',
        'last_name'  => 'Last name',
        'full_name'  => 'Full name',
        'amount'     => 'Amount',
        'currency'   => 'Currency',
        'date'       => 'Date',
        'status'     => 'Status',
        'reference'  => 'Transaction id',
    ];

    private const REQUIRED = ['email', 'amount'];

    /** Header names that usually mean a given field, lowercased. */
    private const GUESSES = [
        'email'      => ['email', 'email address', 'donor email', 'e-mail'],
        'first_name' => ['first name', 'first', 'firstname', 'given name'],
        'last_name'  => ['last name', 'last', 'lastname', 'surname', 'family name'],
        'full_name'  => ['name', 'full name', 'donor', 'donor name'],
        'amount'     => ['amount', 'total', 'donation amount', 'gift amount', 'value'],
        'currency'   => ['currency', 'currency code'],
        'date'       => ['date', 'donation date', 'created', 'created at', 'paid at', 'timestamp'],
        'status'     => ['status', 'state'],
        'reference'  => ['transaction id', 'reference', 'id', 'donation id', 'charge id', 'payment id'],
    ];

    /** Statuses a row may claim. Anything else is imported as paid. */
    private const STATUSES = ['paid', 'pending', 'failed', 'refunded', 'cancelled'];

    public function __construct(
        private DonorService $donors,
        private IdentityHasher $hasher,
    ) {
    }

    /**
     * Headers and the first few rows, so the admin maps against what is
     * actually in their file rather than against field names alone.
     *
     * @return array{headers:list<string>, sample:list<array<string,string>>, rows:int, mapping:array<string,string>}
     */
    public function inspect(string $csv, int $sampleSize = 5): array
    {
        [$headers, $rows] = $this->parse($csv);

        return [
            'headers' => $headers,
            'sample'  => array_slice($rows, 0, $sampleSize),
            'rows'    => count($rows),
            'mapping' => $this->guessMapping($headers),
        ];
    }

    /**
     * @param  array<string,string> $mapping field => header
     * @return array{imported:int, donors_created:int, skipped:array<string,int>, errors:list<string>, dry_run:bool}
     */
    public function import(string $csv, array $mapping, bool $dryRun = true): array
    {
        [$headers, $rows] = $this->parse($csv);

        $missing = array_values(array_diff(self::REQUIRED, array_keys(array_filter($mapping))));
        if ($missing !== []) {
            return [
                'imported' => 0, 'donors_created' => 0, 'skipped' => [], 'dry_run' => $dryRun,
                'errors' => [sprintf(
                    /* translators: %s: comma-separated field names. */
                    __('Map a column to %s before importing.', 'dono'),
                    implode(', ', array_map(static fn (string $f): string => self::FIELDS[$f] ?? $f, $missing))
                )],
            ];
        }

        $imported = 0;
        $created  = 0;
        $skipped  = [];
        $errors   = [];

        // A dry run counts what a real one would do, which means it has to see
        // the same duplicates. Held here rather than read back from the
        // database, which a dry run never writes to.
        $seen = [];

        foreach ($rows as $i => $row) {
            try {
                $outcome = $this->row($row, $mapping, $dryRun, $seen);
            } catch (Throwable $e) {
                $errors[] = sprintf(
                    /* translators: 1: row number, 2: error message. */
                    __('Row %1$d: %2$s', 'dono'),
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

            $imported++;
            if ($outcome['donor_created']) $created++;
        }

        return [
            'imported'       => $imported,
            'donors_created' => $created,
            'skipped'        => $skipped,
            'errors'         => array_slice($errors, 0, 20),
            'dry_run'        => $dryRun,
        ];
    }

    /**
     * @param  array<string,string> $row
     * @param  array<string,string> $mapping
     * @param  array<string,bool>   $seen
     * @return array{skip:?string, donor_created:bool}
     */
    private function row(array $row, array $mapping, bool $dryRun, array &$seen): array
    {
        $get = static fn (string $field): string => trim((string) ($row[$mapping[$field] ?? ''] ?? ''));

        $email = strtolower($get('email'));
        if ($email === '') {
            return ['skip' => 'no_email', 'donor_created' => false];
        }
        if (! is_email($email)) {
            return ['skip' => 'invalid_email', 'donor_created' => false];
        }

        $amountCents = $this->cents($get('amount'));
        if ($amountCents === null) {
            return ['skip' => 'invalid_amount', 'donor_created' => false];
        }

        $reference = $this->reference($email, $amountCents, $get('date'), $get('reference'));

        if (isset($seen[$reference])) {
            return ['skip' => 'duplicate_in_file', 'donor_created' => false];
        }
        $seen[$reference] = true;

        if (Donation::query()->where('reference', $reference)->get() !== null) {
            return ['skip' => 'already_imported', 'donor_created' => false];
        }

        // Someone erased here asked to be forgotten. A spreadsheet from before
        // that is not consent to bring them back.
        $existing = Donor::query()->where('email_hash', $this->hasher->emailHash($email))->get();
        if (is_array($existing) ? ($existing['redacted_at'] ?? null) : ($existing->redacted_at ?? null)) {
            return ['skip' => 'donor_erased', 'donor_created' => false];
        }

        $donorCreated = $existing === null;

        if ($dryRun) {
            return ['skip' => null, 'donor_created' => $donorCreated];
        }

        [$first, $last] = $this->names($get('first_name'), $get('last_name'), $get('full_name'));
        $donor = $this->donors->findOrCreate($email, array_filter([
            'first_name' => $first,
            'last_name'  => $last,
        ]));

        $paidAt   = $this->date($get('date'));
        $currency = strtoupper($get('currency')) ?: Money::defaultCurrency();
        $status   = strtolower($get('status'));
        if (! in_array($status, self::STATUSES, true)) $status = 'paid';

        $donation = Donation::make();
        $donation->reference    = $reference;
        $donation->donor_id     = (int) $donor->id;
        $donation->amount_cents = $amountCents;
        $donation->net_cents    = $amountCents;
        $donation->currency     = $currency;
        $donation->status       = $status;
        $donation->gateway      = 'imported';
        $donation->frequency    = 'one_time';
        $donation->paid_at      = $status === 'paid' ? $paidAt : null;
        $donation->created_at   = $paidAt;
        $donation->updated_at   = $paidAt;
        // So a row can be traced back to the file it came from, and told apart
        // from a donation this site actually took.
        $donation->source_attribution = ['source' => 'csv_import'];
        $donation->save();

        return ['skip' => null, 'donor_created' => $donorCreated];
    }

    /**
     * Stable across runs so the same file matches itself. The transaction id
     * is used when the file has one, since that is the only thing the other
     * platform guaranteed to be unique.
     */
    private function reference(string $email, int $cents, string $date, string $external): string
    {
        $seed = $external !== ''
            ? 'ext:' . $external
            : sprintf('row:%s|%d|%s', $email, $cents, $this->date($date));

        return 'CSV-' . strtoupper(substr(hash('sha256', $seed), 0, 12));
    }

    /** @return array{0:string,1:string} */
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
     */
    private function cents(string $raw): ?int
    {
        $raw = trim($raw);
        if ($raw === '') return null;

        $clean = preg_replace('/[^0-9,.\-]/', '', $raw) ?? '';
        if ($clean === '' || $clean === '-') return null;

        // 1.234,56 and 1,234.56 both occur; the last separator is the decimal.
        $lastDot   = strrpos($clean, '.');
        $lastComma = strrpos($clean, ',');
        if ($lastComma !== false && ($lastDot === false || $lastComma > $lastDot)) {
            $clean = str_replace('.', '', $clean);
            $clean = str_replace(',', '.', $clean);
        } else {
            $clean = str_replace(',', '', $clean);
        }

        if (! is_numeric($clean)) return null;

        $cents = (int) round(((float) $clean) * 100);

        return $cents > 0 ? $cents : null;
    }

    private function date(string $raw): string
    {
        $raw = trim($raw);
        $ts  = $raw !== '' ? strtotime($raw) : false;

        return gmdate('Y-m-d H:i:s', $ts !== false ? $ts : time());
    }

    /** @param list<string> $headers @return array<string,string> */
    private function guessMapping(array $headers): array
    {
        $out = [];
        foreach (self::GUESSES as $field => $candidates) {
            foreach ($headers as $header) {
                if (in_array(strtolower(trim($header)), $candidates, true)) {
                    $out[$field] = $header;
                    break;
                }
            }
        }

        return $out;
    }

    /** @return array{0:list<string>, 1:list<array<string,string>>} */
    private function parse(string $csv): array
    {
        $handle = fopen('php://temp', 'r+');
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
        fclose($handle);

        return [$headers, $rows];
    }
}
