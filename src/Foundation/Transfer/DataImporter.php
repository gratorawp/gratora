<?php

declare(strict_types=1);

namespace Dono\Foundation\Transfer;

use Dono\Foundation\Crypto\Crypto;
use Dono\Foundation\Identity\IdentityHasher;
use Dono\Vendor\Queryable\DB;

/**
 * Restores a Dono export onto this site.
 *
 * Three things make this harder than inserting rows.
 *
 * Ids do not survive. Donor 12 on the source is not donor 12 here, so every row
 * is matched on something real (a slug, a reference, an address) and the source
 * id is remembered only long enough to rewrite what pointed at it.
 *
 * Some of those pointers run backwards. A campaign names its default form while
 * a form names its campaign; a fund can name a parent fund it has not reached
 * yet. Those columns are left null on the way in and filled once every id is
 * known. See DEFERRED.
 *
 * And it has to be safe to run twice. Anything already here is left exactly as
 * it is rather than duplicated or overwritten, which is also what makes a
 * half-finished import safe to resume.
 *
 * @since 1.0.0
 */
final class DataImporter
{
    /**
     * Insert order. Rearranged from the export's so a row's references already
     * exist when it lands: funds before the campaigns that name one, plans
     * before the donations that belong to them.
     */
    private const ORDER = [
        'dono_funds',
        'dono_campaigns',
        'dono_forms',
        'dono_donors',
        'dono_recurring_plans',
        'dono_donations',
        'dono_consents',
        'dono_donor_notes',
        'dono_donation_notes',
        'dono_refunds',
        'dono_receipts',
    ];

    /**
     * What identifies a row as the same row. Without one, re-running would
     * duplicate everything and a resumed import would double what it had done.
     */
    private const NATURAL_KEY = [
        'dono_funds'           => ['code'],
        'dono_campaigns'       => ['slug'],
        'dono_forms'           => ['slug'],
        'dono_donors'          => ['email_hash'],
        'dono_donations'       => ['reference'],
        'dono_recurring_plans' => ['gateway', 'gateway_subscription_id'],
        'dono_refunds'         => ['gateway_refund_id'],
        'dono_receipts'        => ['renderer_id', 'receipt_number'],
    ];

    /** Columns holding an id from another exported table, by the table it points at. */
    private const REFERENCES = [
        'donor_id'           => 'dono_donors',
        'household_id'       => 'dono_donors',
        'campaign_id'        => 'dono_campaigns',
        'form_id'            => 'dono_forms',
        'source_form_id'     => 'dono_forms',
        'default_form_id'    => 'dono_forms',
        'fund_id'            => 'dono_funds',
        'default_fund_id'    => 'dono_funds',
        'parent_fund_id'     => 'dono_funds',
        'donation_id'        => 'dono_donations',
        'source_donation_id' => 'dono_donations',
        'recurring_plan_id'  => 'dono_recurring_plans',
    ];

    /** Filled on a second pass, once the table they point at has been walked. */
    private const DEFERRED = [
        'dono_campaigns' => ['default_form_id'],
        'dono_funds'     => ['parent_fund_id'],
        'dono_donors'    => ['household_id'],
    ];

    /**
     * Ids belonging to things this export does not carry: a page on the source
     * site, a WordPress user who does not exist here, a peer-to-peer fundraiser
     * from an add-on. Cleared rather than kept, because a number pointing at
     * the wrong record is worse than no number.
     */
    private const FOREIGN = [
        'page_id',
        'author_id',
        'author_user_id',
        'initiated_user_id',
        'fundraiser_id',
        'fundraiser_team_id',
    ];

    /** @var array<string, array<int,int>> source id => id here, per table */
    private array $map = [];

    /** @var array<string,int> */
    private array $created = [];

    /** @var array<string,int> */
    private array $existing = [];

    /** @var array<string,int> */
    private array $skipped = [];

    /** @since 1.0.0 */
    public function __construct(
        private Crypto $crypto,
        private IdentityHasher $hasher,
    ) {
    }

    /**
     * @param  array<string,mixed> $export
     * @return array{created:array<string,int>, existing:array<string,int>, skipped:array<string,int>}
     * @since 1.0.0
     */
    public function import(array $export): array
    {
        $tables = is_array($export['tables'] ?? null) ? $export['tables'] : [];

        foreach (self::ORDER as $table) {
            foreach (($tables[$table] ?? []) as $row) {
                if (is_array($row)) $this->importRow($table, $row);
            }
        }

        $this->resolveDeferred($tables);

        return [
            'created'  => $this->created,
            'existing' => $this->existing,
            'skipped'  => $this->skipped,
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @since 1.0.0
     */
    private function importRow(string $table, array $row): void
    {
        $sourceId = (int) ($row['id'] ?? 0);
        $row      = $this->prepare($table, $row);

        if ($row === null) {
            $this->bump($this->skipped, $table);
            return;
        }

        $existingId = $this->findExisting($table, $row);
        if ($existingId > 0) {
            // Left exactly as it is. An import that overwrote would make
            // running it twice destructive, and this is the shape that lets a
            // half-finished run be started again.
            if ($sourceId > 0) $this->map[$table][$sourceId] = $existingId;
            $this->bump($this->existing, $table);
            return;
        }

        unset($row['id']);
        foreach (self::DEFERRED[$table] ?? [] as $column) {
            unset($row[$column]);
        }

        // DB::table applies the prefix itself, unlike the raw reads the
        // exporter uses.
        $result = DB::table($table)->insert($row);
        $newId  = (int) $result->insertId;

        if ($sourceId > 0 && $newId > 0) $this->map[$table][$sourceId] = $newId;
        $this->bump($this->created, $table);
    }

    /**
     * Re-seals PII with this site's key, rebuilds the address hash against this
     * site's pepper, and rewrites every id that pointed somewhere else.
     *
     * @param  array<string,mixed> $row
     * @return array<string,mixed>|null null when the row cannot be placed here
     * @since 1.0.0
     */
    private function prepare(string $table, array $row): ?array
    {
        foreach (self::FOREIGN as $column) {
            if (array_key_exists($column, $row)) $row[$column] = null;
        }

        foreach (self::REFERENCES as $column => $points) {
            if (! array_key_exists($column, $row)) continue;

            $source = (int) $row[$column];
            if ($source <= 0) {
                $row[$column] = null;
                continue;
            }

            $mapped = $this->map[$points][$source] ?? null;
            if ($mapped === null) {
                // Its parent never arrived: the export was partial, or the row
                // it needed was skipped. Dropped rather than pointed at
                // whatever happens to hold that id here.
                if (in_array($column, self::DEFERRED[$table] ?? [], true)) {
                    $row[$column] = null;
                    continue;
                }
                return null;
            }

            $row[$column] = $mapped;
        }

        if ($table === 'dono_donors') {
            return $this->prepareDonor($row);
        }

        return $row;
    }

    /**
     * @param  array<string,mixed> $row
     * @return array<string,mixed>|null
     * @since 1.0.0
     */
    private function prepareDonor(array $row): ?array
    {
        $email = trim((string) ($row['email'] ?? ''));
        unset($row['email']);

        if ($email === '') {
            // Nothing to match them on here or later, and no way to reach them.
            return null;
        }

        $row['email_hash']      = $this->hasher->emailHash($email);
        $row['email_encrypted'] = $this->crypto->encrypt($email);

        foreach (['address', 'phone', 'tax_id', 'notes'] as $field) {
            if (! array_key_exists($field, $row)) continue;
            $value = (string) $row[$field];
            unset($row[$field]);
            if ($value !== '') $row[$field . '_encrypted'] = $this->crypto->encrypt($value);
        }

        return $row;
    }

    /**
     * @param array<string,mixed> $row
     * @since 1.0.0
     */
    private function findExisting(string $table, array $row): int
    {
        $key = self::NATURAL_KEY[$table] ?? null;
        if ($key === null) {
            // Consents and notes have none. They belong to a parent, and a
            // parent that already existed keeps the entries it already has.
            return 0;
        }

        $query = DB::table($table);
        foreach ($key as $column) {
            $value = $row[$column] ?? null;
            if ($value === null || $value === '') return 0;
            $query->where($column, $value);
        }

        // DB::table()->get() hands back an array while DB::raw() hands back
        // objects, and reading only one of them silently finds nothing.
        $found = $query->get();
        if (is_array($found))  return (int) ($found['id'] ?? 0);
        if (is_object($found)) return (int) ($found->id ?? 0);

        return 0;
    }

    /**
     * The columns that pointed at a table not yet walked when their row landed.
     *
     * @param array<string,mixed> $tables
     * @since 1.0.0
     */
    private function resolveDeferred(array $tables): void
    {
        foreach (self::DEFERRED as $table => $columns) {
            foreach (($tables[$table] ?? []) as $row) {
                if (! is_array($row)) continue;

                $here = $this->map[$table][(int) ($row['id'] ?? 0)] ?? null;
                if ($here === null) continue;

                $patch = [];
                foreach ($columns as $column) {
                    $source = (int) ($row[$column] ?? 0);
                    if ($source <= 0) continue;

                    $points = self::REFERENCES[$column] ?? null;
                    $mapped = $points ? ($this->map[$points][$source] ?? null) : null;
                    if ($mapped !== null) $patch[$column] = $mapped;
                }

                if ($patch) {
                    DB::table($table)->where('id', $here)->update($patch);
                }
            }
        }
    }

    /**
     * @param array<string,int> $bucket
     * @since 1.0.0
     */
    private function bump(array &$bucket, string $table): void
    {
        $bucket[$table] = ($bucket[$table] ?? 0) + 1;
    }
}
