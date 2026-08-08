<?php

declare(strict_types=1);

namespace Dono\Foundation\Transfer;

use Dono\Foundation\Crypto\Crypto;
use Dono\Foundation\Helpers\Csv;
use Dono\Settings\SecretRedactor;
use Dono\Vendor\Queryable\DB;

/**
 * Writes everything an organisation owns to a file they can take elsewhere.
 *
 * Two things shape this. It has to restore on a different site, and it has to
 * be safe to hand to somebody.
 *
 * Restoring elsewhere is why donor PII leaves decrypted. Every column ending
 * _encrypted is sealed with this install's encryption_key_v1, and another site
 * has a different one, so shipping the ciphertext would produce a file that
 * only the site it came from could ever read. That is not an export.
 *
 * Safe to hand over is why several tables never appear. See SKIP.
 *
 * @version 1.0.0
 */
final class DataExporter
{
    public const FORMAT_VERSION = 1;

    /** Rows per query. Bounded so a large site does not build one huge array. */
    private const PAGE = 500;

    /**
     * Tables that carry the organisation's own records.
     *
     * Order matters on the way back in: a donation needs its donor, a receipt
     * needs its donation. The importer walks this list as written.
     */
    private const TABLES = [
        'dono_campaigns',
        'dono_funds',
        'dono_forms',
        'dono_donors',
        'dono_consents',
        'dono_donor_notes',
        'dono_donations',
        'dono_donation_notes',
        'dono_refunds',
        'dono_recurring_plans',
        'dono_receipts',
    ];

    /**
     * Deliberately absent, and each for its own reason.
     *
     * dono_system_settings holds encryption_key_v1, email_pepper_v1,
     * form_signing_secret_v1, ip_salt_v1 and gateway credentials. An export is
     * a file people email to support and commit to repositories; the keys to
     * every encrypted column in the database cannot travel that way.
     *
     * dono_magic_link_tokens are live credentials. Anyone holding the file
     * could sign in as any donor until they expired.
     *
     * dono_pending_signups are half-finished donations nobody has confirmed.
     *
     * dono_webhooks_log are raw gateway payloads: other people's card metadata
     * and, in the failure cases worth keeping, secrets.
     *
     * dono_form_donation_stats and dono_events are derived or observational.
     * The stats are recomputed on import; the log describes what happened on
     * one site, not what the organisation owns.
     */
    private const SKIP = [
        'dono_system_settings',
        'dono_magic_link_tokens',
        'dono_pending_signups',
        'dono_webhooks_log',
        'dono_form_donation_stats',
        'dono_events',
    ];

    private const SETTINGS_OPTIONS = [
        'dono_org_profile',
        'dono_currency_locale',
        'dono_org_brand',
        'dono_gateway_config',
        'dono_privacy',
        'dono_roles',
        'dono_consents',
        'dono_receipt_settings',
        'dono_email_settings',
        'dono_reference_settings',
    ];

    public function __construct(private Crypto $crypto)
    {
    }

    /**
     * Streams the export into $out as JSON. A stream rather than a string so a
     * site with a decade of donations does not have to fit in memory at once.
     *
     * @param  resource $out
     * @return array<string,int> rows written per table
     */
    public function writeJson($out): array
    {
        $counts = [];

        fwrite($out, '{');
        fwrite($out, '"format":' . self::FORMAT_VERSION);
        fwrite($out, ',"exported_at":' . wp_json_encode(gmdate('c')));
        fwrite($out, ',"site_url":' . wp_json_encode(site_url()));
        fwrite($out, ',"version":' . wp_json_encode(defined('DONO_VERSION') ? DONO_VERSION : 'unknown'));
        fwrite($out, ',"settings":' . wp_json_encode($this->settings()));
        fwrite($out, ',"tables":{');

        $firstTable = true;
        foreach (self::tables() as $table) {
            if (! $firstTable) fwrite($out, ',');
            $firstTable = false;

            fwrite($out, wp_json_encode($table) . ':[');
            $counts[$table] = $this->writeTable($out, $table);
            fwrite($out, ']');
        }

        fwrite($out, '}}');

        return $counts;
    }

    /**
     * @param  resource $out
     * @return int rows written
     */
    private function writeTable($out, string $table): int
    {
        $prefix = DB::getPrefix();
        $lastId = 0;
        $total  = 0;
        $first  = true;

        // Keyed off the last id rather than an offset: an offset re-reads rows
        // it has already passed and drifts if anything is written mid-export.
        while (true) {
            $rows = DB::raw(
                "SELECT * FROM {$prefix}{$table} WHERE id > %d ORDER BY id ASC LIMIT %d",
                [$lastId, self::PAGE]
            )['rows'] ?? [];

            if (! $rows) break;

            foreach ($rows as $row) {
                $row    = $this->shape($table, (array) $row);
                $lastId = (int) ($row['id'] ?? $lastId);

                if (! $first) fwrite($out, ',');
                $first = false;

                fwrite($out, (string) wp_json_encode($row));
                $total++;
            }

            if (count($rows) < self::PAGE) break;
        }

        return $total;
    }

    /**
     * Decrypts what this site sealed, so another site can read it.
     *
     * @param  array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function shape(string $table, array $row): array
    {
        foreach ($row as $column => $value) {
            if (! str_ends_with((string) $column, '_encrypted')) {
                continue;
            }

            unset($row[$column]);
            $plain = is_string($value) && $value !== '' ? $this->crypto->decrypt($value) : null;
            // The key that sealed it is gone, or the value was never set. The
            // column is dropped rather than guessed at, and the importer treats
            // an absent field as empty.
            if ($plain !== null && $plain !== '') {
                $row[substr((string) $column, 0, -strlen('_encrypted'))] = $plain;
            }
        }

        // Rebuilt from the imported address, and site-specific besides: it is
        // salted with this install's pepper, which does not travel.
        unset($row['email_hash']);

        return $row;
    }

    /** @return array<string,mixed> */
    private function settings(): array
    {
        $out = [];
        foreach (self::SETTINGS_OPTIONS as $opt) {
            $value = get_option($opt, null);
            if ($value === null) continue;

            // Same reason the system settings table is absent: the Stripe
            // webhook signing secret is the only authentication on that route.
            $out[$opt] = is_array($value) ? SecretRedactor::redact($value) : $value;
        }

        return $out;
    }

    /**
     * Donors, one row each, for a spreadsheet or another platform.
     *
     * Erased donors are listed by id with the personal columns blank rather
     * than dropped: their donations are still in the donations export and a row
     * that referenced nothing would read as corruption.
     *
     * @param  resource $out
     */
    public function writeDonorsCsv($out): int
    {
        Csv::writeRow($out, [
            'id', 'email', 'first_name', 'last_name', 'company', 'country',
            'donor_type', 'donations_count', 'total_donated', 'first_donation_at',
            'last_donation_at', 'created_at', 'erased',
        ]);

        return $this->eachRow('dono_donors', function (array $r) use ($out): void {
            Csv::writeRow($out, [
                $r['id'] ?? '',
                $r['email'] ?? '',
                $r['first_name'] ?? '',
                $r['last_name'] ?? '',
                $r['company'] ?? '',
                $r['country'] ?? '',
                $r['donor_type'] ?? '',
                $r['donations_count'] ?? 0,
                number_format(((int) ($r['total_donated_cents'] ?? 0)) / 100, 2, '.', ''),
                $r['first_donation_at'] ?? '',
                $r['last_donation_at'] ?? '',
                $r['created_at'] ?? '',
                ($r['redacted_at'] ?? null) ? 'yes' : 'no',
            ]);
        });
    }

    /**
     * Donations with the donor's address on each row, because a spreadsheet has
     * no joins and the file is useless to the next platform without it.
     *
     * @param  resource $out
     */
    public function writeDonationsCsv($out): int
    {
        Csv::writeRow($out, [
            'id', 'reference', 'donor_id', 'donor_email', 'donor_name', 'amount',
            'currency', 'status', 'is_recurring', 'is_anonymous', 'is_test',
            'campaign_id', 'form_id', 'fund_id', 'gateway', 'paid_at', 'created_at',
        ]);

        $donors = $this->donorLookup();

        return $this->eachRow('dono_donations', function (array $r) use ($out, $donors): void {
            $donor = $donors[(int) ($r['donor_id'] ?? 0)] ?? ['email' => '', 'name' => ''];

            Csv::writeRow($out, [
                $r['id'] ?? '',
                $r['reference'] ?? '',
                $r['donor_id'] ?? '',
                $donor['email'],
                $donor['name'],
                number_format(((int) ($r['amount_cents'] ?? 0)) / 100, 2, '.', ''),
                $r['currency'] ?? '',
                $r['status'] ?? '',
                ($r['recurring_plan_id'] ?? null) ? 'yes' : 'no',
                ($r['is_anonymous'] ?? 0) ? 'yes' : 'no',
                ($r['is_test'] ?? 0) ? 'yes' : 'no',
                $r['campaign_id'] ?? '',
                $r['form_id'] ?? '',
                $r['fund_id'] ?? '',
                $r['gateway'] ?? '',
                $r['paid_at'] ?? '',
                $r['created_at'] ?? '',
            ]);
        });
    }

    /**
     * Address and name per donor id, decrypted once. A join would re-decrypt
     * the same donor for every donation they ever made.
     *
     * @return array<int, array{email:string,name:string}>
     */
    private function donorLookup(): array
    {
        $out = [];
        $this->eachRow('dono_donors', function (array $r) use (&$out): void {
            $out[(int) ($r['id'] ?? 0)] = [
                'email' => (string) ($r['email'] ?? ''),
                'name'  => trim(((string) ($r['first_name'] ?? '')) . ' ' . ((string) ($r['last_name'] ?? ''))),
            ];
        });

        return $out;
    }

    /**
     * Walks a table a page at a time, shaped the same way the JSON export
     * shapes it, so both formats agree about what a row says.
     */
    private function eachRow(string $table, callable $fn): int
    {
        $prefix = DB::getPrefix();
        $lastId = 0;
        $total  = 0;

        while (true) {
            $rows = DB::raw(
                "SELECT * FROM {$prefix}{$table} WHERE id > %d ORDER BY id ASC LIMIT %d",
                [$lastId, self::PAGE]
            )['rows'] ?? [];

            if (! $rows) break;

            foreach ($rows as $row) {
                $shaped = $this->shape($table, (array) $row);
                $lastId = (int) ($shaped['id'] ?? $lastId);
                $fn($shaped);
                $total++;
            }

            if (count($rows) < self::PAGE) break;
        }

        return $total;
    }

    /**
     * Core's own tables, plus whatever add-ons contribute.
     *
     * An add-on owns its data the same way it owns its uninstall: core does not
     * know that tributes or Gift Aid declarations exist, and an export that
     * silently left them behind would be the wrong kind of complete.
     *
     * @return list<string>
     */
    public static function tables(): array
    {
        $tables = (array) apply_filters('dono.export.tables', self::TABLES);

        // Nothing an add-on adds can reopen what SKIP closed.
        return array_values(array_diff(
            array_unique(array_map('strval', $tables)),
            self::SKIP
        ));
    }

    /** @return list<string> */
    public static function skipped(): array
    {
        return self::SKIP;
    }
}
