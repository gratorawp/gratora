<?php

declare(strict_types=1);

namespace Dono\Exports;

use Dono\Donations\DonationQueries;
use Dono\Donors\Donor;
use Dono\Donors\DonorRepository;
use Dono\Donors\DonorService;
use Dono\Foundation\Helpers\Csv;
use Dono\Vendor\Queryable\DB;

/**
 * Streams the donor list as CSV. Columns are opt-in because most of them are
 * PII decrypted one row at a time, and a list mailed to a fulfilment house
 * should carry only what that house needs.
 */
final class DonorExporter
{
    /** Column keys, in the order they are written to the file. */
    public const COLUMNS = [
        'first_name',
        'last_name',
        'email',
        'phone',
        'address',
        'company',
        'country',
        'donor_type',
        'donations_count',
        'total_donated',
        'first_donation',
        'last_donation',
        'created_at',
        'donor_id',
    ];

    private const FALLBACK = ['first_name', 'last_name', 'email', 'donations_count', 'total_donated'];

    private const CHUNK = 500;

    public function __construct(private DonorService $donors)
    {
    }

    /**
     * Dates match when the donor record was created, which is the date the
     * screen offers and the only one the donor table answers on its own.
     *
     * @param array{columns?:list<string>,from?:?string,to?:?string,campaign_id?:?int} $args
     */
    public function toCsv(array $args = []): string
    {
        $columns    = $this->columns($args['columns'] ?? []);
        $from       = $this->day($args['from'] ?? null);
        $to         = $this->day($args['to'] ?? null);
        $campaignId = (int) ($args['campaign_id'] ?? 0);

        $out = fopen('php://temp', 'r+');
        if ($out === false) {
            return '';
        }

        // UTF-8 BOM so Excel reads accented names as text rather than mojibake.
        fwrite($out, "\xEF\xBB\xBF");
        $labels = self::labels();
        Csv::writeRow($out, array_map(
            static fn (string $key): string => $labels[$key] ?? $key,
            $columns
        ));

        // A campaign filter is a question about donations, so it resolves to a
        // donor id set first. Without one the donor table answers on its own,
        // since an org with a million donors would not fit an id list in memory.
        $ids = null;
        if ($campaignId > 0) {
            $ids = $this->donorIdsForCampaign($campaignId);
            if ($ids === []) {
                rewind($out);
                return (string) stream_get_contents($out);
            }
        }

        $afterId = 0;
        while (true) {
            $q = Donor::query()
                ->whereRaw($this->livePredicate())
                ->where('id', $afterId, '>');

            if ($ids !== null) {
                $q = $q->whereIn('id', $ids);
            }
            if ($from !== null) $q = $q->where('created_at', $from . ' 00:00:00', '>=');
            if ($to   !== null) $q = $q->where('created_at', $to   . ' 23:59:59', '<=');

            $batch = $q->orderBy('id', 'ASC')->limit(self::CHUNK)->getAll();
            if ($batch === []) {
                break;
            }

            foreach ($batch as $donor) {
                $afterId = (int) $donor->id;
                Csv::writeRow($out, $this->row($donor, $columns));
            }
        }

        rewind($out);

        return (string) stream_get_contents($out);
    }

    /**
     * Also served to the UI so the checkbox labels and the file headers cannot
     * drift apart. Spelled out rather than built from a variable, or none of it
     * reaches a .pot file.
     */
    public static function labels(): array
    {
        return [
            'first_name'      => __('First name', 'dono'),
            'last_name'       => __('Last name', 'dono'),
            'email'           => __('Email', 'dono'),
            'phone'           => __('Phone', 'dono'),
            'address'         => __('Address', 'dono'),
            'company'         => __('Company', 'dono'),
            'country'         => __('Country', 'dono'),
            'donor_type'      => __('Type', 'dono'),
            'donations_count' => __('Donations', 'dono'),
            'total_donated'   => __('Total donated', 'dono'),
            'first_donation'  => __('First donation', 'dono'),
            'last_donation'   => __('Last donation', 'dono'),
            'created_at'      => __('Donor since', 'dono'),
            'donor_id'        => __('Donor ID', 'dono'),
        ];
    }

    public static function filename(): string
    {
        return 'donors-' . gmdate('Y-m-d-His') . '.csv';
    }

    private function columns(array $requested): array
    {
        $valid = array_values(array_filter(
            array_map('strval', $requested),
            static fn (string $c): bool => in_array($c, self::COLUMNS, true)
        ));

        // An entirely bogus selection would otherwise write a file of blank
        // lines, which reads as "the export is broken".
        if ($valid === []) {
            return self::FALLBACK;
        }

        // Keep COLUMNS order regardless of the order they arrived in.
        return array_values(array_filter(
            self::COLUMNS,
            static fn (string $c): bool => in_array($c, $valid, true)
        ));
    }

    private function row(Donor $donor, array $columns): array
    {
        $out = [];
        foreach ($columns as $key) {
            $out[] = match ($key) {
                'first_name'      => (string) ($donor->first_name ?? ''),
                'last_name'       => (string) ($donor->last_name ?? ''),
                'email'           => (string) ($this->donors->decryptEmail($donor) ?? ''),
                'phone'           => (string) ($this->donors->decryptPhone($donor) ?? ''),
                'address'         => (string) ($this->donors->decryptAddress($donor) ?? ''),
                'company'         => (string) ($donor->company ?? ''),
                'country'         => (string) ($donor->country ?? ''),
                'donor_type'      => (string) $donor->donor_type,
                'donations_count' => (string) (int) $donor->donations_count,
                // The raw major-unit number, not a formatted one: a currency
                // symbol makes the column text in every spreadsheet.
                'total_donated'   => number_format((int) $donor->total_donated_cents / 100, 2, '.', ''),
                'first_donation'  => (string) ($donor->first_donation_at ?? ''),
                'last_donation'   => (string) ($donor->last_donation_at ?? ''),
                'created_at'      => (string) $donor->created_at,
                'donor_id'        => (string) (int) $donor->id,
                default           => '',
            };
        }
        return $out;
    }

    private function donorIdsForCampaign(int $campaignId): array
    {
        $q = DonationQueries::donationsOnly(DB::table('dono_donations'))
            ->select('donor_id')
            ->distinct()
            ->whereNotNull('donor_id')
            ->where('campaign_id', $campaignId);

        return array_values(array_unique(array_filter(array_map(
            static fn ($r): int => (int) ($r['donor_id'] ?? 0),
            $q->getAll()
        ))));
    }

    /**
     * The screen's own definition rather than a restatement of it, so the file
     * cannot disagree with the count it was started from.
     */
    private function livePredicate(): string
    {
        return 'redacted_at IS NULL AND ' . DonorRepository::visibleDonorPredicate();
    }

    private function day(?string $value): ?string
    {
        $value = trim((string) $value);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }
}
