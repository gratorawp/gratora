<?php

declare(strict_types=1);

namespace Dono\Foundation\Transfer;

use Dono\Foundation\Crypto\Crypto;
use Dono\Settings\SecretRedactor;
use Dono\Vendor\Queryable\DB;

/**
 * Writes everything an organization owns to a file they can take elsewhere.
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
 * @since 1.0.0
 */
final class DataExporter
{
    public const FORMAT_VERSION = 1;

    /** Rows per query. Bounded so a large site does not build one huge array. */
    private const PAGE = 500;

    /**
     * Tables that carry the organization's own records.
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
     * one site, not what the organization owns.
     */
    private const SKIP = [
        'dono_system_settings',
        'dono_magic_link_tokens',
        'dono_pending_signups',
        'dono_webhooks_log',
        'dono_form_donation_stats',
        'dono_events',
    ];

    /**
     * Sealed columns, by the plaintext name they travel under.
     *
     * A column ending _encrypted leaves under its stripped name: body_encrypted
     * ships as body. The importer reads this same map to seal it again under
     * the name the schema knows, so the two sides cannot disagree about what a
     * field is called.
     *
     * True marks a column the schema declares NOT NULL, which is what tells the
     * importer to write it even when the export carried nothing for it.
     *
     * @var array<string, array<string,bool>>
     */
    private const ENCRYPTED = [
        'dono_donors' => [
            'email'   => true,
            'address' => false,
            'phone'   => false,
            'tax_id'  => false,
            'notes'   => false,
        ],
        'dono_donor_notes'    => ['body' => true],
        'dono_donation_notes' => ['body' => true],
        'dono_donations'      => ['custom_data' => false],
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

    /** @since 1.0.0 */
    public function __construct(private Crypto $crypto)
    {
    }

    /**
     * Streams the export into $out as JSON. A stream rather than a string so a
     * site with a decade of donations does not have to fit in memory at once.
     *
     * @param  resource $out
     * @return array<string,int> rows written per table
     * @since 1.0.0
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
     * @since 1.0.0
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
     * @since 1.0.0
     */
    private function shape(string $table, array $row): array
    {
        // Only what the map names. A sealed column nobody declared travels
        // sealed: stripping its suffix would hand the importer a plaintext
        // field no table has a column for, and the restore dies on that row.
        foreach (array_keys(self::encryptedColumns()[$table] ?? []) as $column) {
            $sealed = $column . '_encrypted';
            if (! array_key_exists($sealed, $row)) continue;

            $value = $row[$sealed];
            unset($row[$sealed]);

            $plain = is_string($value) && $value !== '' ? $this->crypto->decrypt($value) : null;
            // The key that sealed it is gone, or the value was never set. The
            // column is dropped rather than guessed at, and the importer treats
            // an absent field as empty.
            if ($plain !== null && $plain !== '') {
                $row[$column] = $plain;
            }
        }

        // Rebuilt from the imported address, and site-specific besides: it is
        // salted with this install's pepper, which does not travel.
        unset($row['email_hash']);

        return $row;
    }

    /**
     * @return array<string,mixed>
     * @since 1.0.0
     */
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
     * Core's own tables, plus whatever add-ons contribute.
     *
     * An add-on owns its data the same way it owns its uninstall: core does not
     * know that tributes or Gift Aid declarations exist, and an export that
     * silently left them behind would be the wrong kind of complete.
     *
     * @return list<string>
     * @since 1.0.0
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

    /**
     * The sealed columns of every exported table, importer included.
     *
     * An add-on that contributes a table through dono.export.tables declares
     * its sealed columns here, or they cross as ciphertext this site's key is
     * the only one that opens.
     *
     * @return array<string, array<string,bool>> table => plaintext name => column is NOT NULL
     * @since 1.0.0
     */
    public static function encryptedColumns(): array
    {
        $map = (array) apply_filters('dono.export.encrypted_columns', self::ENCRYPTED);

        return array_filter($map, 'is_array');
    }

    /**
     * @return list<string>
     * @since 1.0.0
     */
    public static function skipped(): array
    {
        return self::SKIP;
    }
}
