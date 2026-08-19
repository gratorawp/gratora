<?php

declare(strict_types=1);

namespace Dono\Foundation\Transfer;

use Dono\Analytics\ErrorLog;
use Dono\Donations\AggregateSyncer;
use Dono\Foundation\Crypto\Crypto;
use Dono\Foundation\Identity\IdentityHasher;
use Dono\Foundation\References\ReferenceGenerator;
use Dono\Foundation\Time\SystemClock;
use Dono\Vendor\Queryable\DB;
use Throwable;

/**
 * Restores a Dono export onto this site.
 *
 * Five things make this harder than inserting rows.
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
 * It has to be safe to run twice. Anything already here is left exactly as it
 * is rather than duplicated or overwritten, which is also what makes a
 * half-finished import safe to resume.
 *
 * Some of what it has to get right is not in the file. Reference counters are
 * per install, and the money totals are columns on rows a restore matches
 * rather than writes, so both are rebuilt once every row has landed. See
 * raiseReferenceCounters() and syncAggregates().
 *
 * And a row it cannot place has to be said out loud. Money records hang off
 * donors and off each other, so one row that does not land takes everything
 * behind it; an operator reading "restored" has to be told what did not.
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
        // No unique index backs these three, so the columns below are the only
        // thing standing between a second run and a doubled audit trail. A
        // consent is the lawful basis for having mailed someone and a note is
        // what a fundraiser wrote about them; neither may arrive twice. The
        // trade is second-resolution: two rows alike in every column named
        // here, written within the same second, restore as one.
        'dono_consents'        => ['donor_id', 'purpose', 'granted', 'occurred_at'],
        'dono_donor_notes'     => ['donor_id', 'created_at'],
        'dono_donation_notes'  => ['donation_id', 'created_at'],
    ];

    /**
     * A second lookup, for a table whose natural key cannot settle it alone.
     *
     * Receipt numbers are per site and the two counters drift, so a receipt can
     * be new by number and still be the second one for a donation this site
     * already holds. The import runs in no transaction and catches nothing, so
     * that refused insert would end the restore half done.
     *
     * A refund taken by hand or through a gateway that does not name its
     * refunds carries no gateway id at all, and the unique index that stops one
     * being recorded twice is nullable for exactly that reason. Nothing else
     * recognises it on a second run, and refunds are subtracted from the org's
     * totals as they stand. That is the only case it may answer: see
     * ALSO_UNIQUE_WHEN_EMPTY.
     */
    private const ALSO_UNIQUE = [
        'dono_receipts' => ['donation_id', 'renderer_id'],
        'dono_refunds'  => ['donation_id', 'amount_cents', 'occurred_at'],
    ];

    /**
     * Tables whose ALSO_UNIQUE lookup applies only to rows missing this column.
     *
     * Two gateway refunds of one donation can agree on amount and second: a
     * charge.refunded array is recorded in one pass and each is stamped with
     * the processing time, not the gateway's. No index separates that triple,
     * so consulting it for a refund the gateway already named merges two real
     * rows and gives the org back money it paid out. The receipts entry needs
     * no such gate, since UNIQUE(donation_id, renderer_id) means it can only
     * ever match a row the insert would be refused against anyway.
     */
    private const ALSO_UNIQUE_WHEN_EMPTY = [
        'dono_refunds' => 'gateway_refund_id',
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

    /**
     * Where a minted reference lands, by the counter scope that mints it.
     *
     * dono_refunds carries no reference column, so no scope reads from it: the
     * refund prefix in the numbering settings is configuration for a sequence
     * nothing issues yet. A renderer numbering its receipts in a scope of its
     * own prints a prefix of its own with them, so its numbers do not answer to
     * the scopes named here and its counter is the add-on's to raise.
     */
    private const REFERENCE_SCOPES = [
        'donation' => ['dono_donations', 'reference'],
        'receipt'  => ['dono_receipts', 'receipt_number'],
        // Rehearsal donations number from their own counter, for the same
        // reason receipts do, and a restore has to raise that one too.
        'test_donation' => ['dono_donations', 'reference'],
        // Rehearsal receipts number from a counter of their own so the live
        // sequence stays gap-free, and the export carries them like any other
        // row. Left out, the counter stays at zero after a restore and the next
        // rehearsal mints a number the file already brought in, which the unique
        // index refuses: the org can no longer test a form at all. Same table as
        // the live scope, which is safe because a row only counts toward a scope
        // whose own format reproduces the number printed on it.
        'test_receipt' => ['dono_receipts', 'receipt_number'],
    ];

    /** @var array<string, array<int,int>> source id => id here, per table */
    private array $map = [];

    /** @var array<string,int> */
    private array $created = [];

    /** @var array<string,int> */
    private array $existing = [];

    /** @var array<string,int> */
    private array $skipped = [];

    /** @var array<string, array<string,int>> table => why it did not land => rows */
    private array $dropped = [];

    /** @var array<int, array<string,mixed>> source donor id => a donation of theirs, as the file holds it */
    private array $shellAnchor = [];

    /** Donors whose address this site's key could not open. */
    private int $unreadable = 0;

    /**
     * What separates this file from another one, so two of them cannot name the
     * same shell. The site it came from, or failing that when it was written:
     * source ids alone start at 1 in every file.
     */
    private string $origin = '';

    /** @since 1.0.0 */
    public function __construct(
        private Crypto $crypto,
        private IdentityHasher $hasher,
    ) {
    }

    /**
     * @param  array<string,mixed> $export
     * @return array{created:array<string,int>, existing:array<string,int>, skipped:array<string,int>, dropped:array<string, array<string,int>>}
     * @since 1.0.0
     */
    public function import(array $export): array
    {
        $tables = is_array($export['tables'] ?? null) ? $export['tables'] : [];

        // The container hands out one of these, so a second file in the same
        // process would otherwise be read through the first one's state: the
        // counts come back cumulative, and the id map resolves this file's rows
        // onto rows the previous file created, because source ids start at 1 in
        // every export. A CLI or batch restore is where two land in one process.
        $this->map         = [];
        $this->created     = [];
        $this->existing    = [];
        $this->skipped     = [];
        $this->dropped     = [];
        $this->shellAnchor = [];
        $this->unreadable  = 0;

        $this->origin = trim((string) ($export['site_url'] ?? ''));
        if ($this->origin === '') {
            $this->origin = trim((string) ($export['exported_at'] ?? ''));
        }

        $this->indexShellDonors($tables);

        foreach (self::ORDER as $table) {
            foreach (($tables[$table] ?? []) as $row) {
                if (is_array($row)) $this->importRow($table, $row);
            }
        }

        $this->resolveDeferred($tables);
        $this->raiseReferenceCounters($export);
        $this->syncAggregates();
        $this->recordUnknownTables($tables);
        $this->reportDropped();
        $this->reportUnreadable();

        return [
            'created'  => $this->created,
            'existing' => $this->existing,
            'skipped'  => $this->skipped,
            'dropped'  => $this->dropped,
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
                return $this->drop($table, 'missing_' . $column);
            }

            $row[$column] = $mapped;
        }

        if ($table === 'dono_donors') {
            return $this->prepareDonor($row);
        }

        return $this->reseal($table, $row);
    }

    /**
     * @param  array<string,mixed> $row
     * @return array<string,mixed>|null
     * @since 1.0.0
     */
    private function prepareDonor(array $row): ?array
    {
        if (self::hasNoAddress($row)) {
            return $this->prepareShellDonor($row);
        }

        $email             = trim((string) ($row['email'] ?? ''));
        $row['email']      = $email;
        $row['email_hash'] = $this->hasher->emailHash($email);

        return $this->reseal('dono_donors', $row);
    }

    /**
     * A donor the file carries no address for, because there is none left to
     * carry: erasure took it, or the key that sealed it did not travel.
     *
     * Both keep the donations, refunds, receipts and consents, and every one of
     * those rows resolves through this donor. Skipping it would make the
     * restore destroy exactly the records that survived, so it lands as the
     * shell it already is: no address, no name, and an identity that names a
     * row rather than a person.
     *
     * @param  array<string,mixed> $row
     * @return array<string,mixed>|null
     * @since 1.0.0
     */
    private function prepareShellDonor(array $row): ?array
    {
        $sourceId = (int) ($row['id'] ?? 0);
        if ($sourceId <= 0) {
            // Every shell would answer to the same identity, which would
            // gather unrelated donors into one row.
            return $this->drop('dono_donors', 'no_source_id');
        }

        foreach (['email', 'first_name', 'last_name', 'company', 'address', 'phone', 'tax_id', 'notes'] as $pii) {
            unset($row[$pii]);
        }

        $row = $this->reseal('dono_donors', $row);
        $row['email_hash'] = $this->shellHash($sourceId);
        // The literal empty string is the marker redaction itself writes, and
        // what DonorService reads to mean this row has no address.
        $row['email_encrypted'] = '';

        if (! self::wasErased($row)) {
            // Nothing erased them, so their address was sealed with a key this
            // site cannot open. Marked erased because that is what the row now
            // is: unidentifiable and unreachable. Without the mark it would
            // read as a live supporter on every mailing surface for the rest of
            // its life, and with it the money behind it is still kept.
            $row['redacted_at'] = gmdate('Y-m-d H:i:s');
            $this->unreadable++;
        }

        return $row;
    }

    /**
     * @param  array<string,mixed> $row
     * @since 1.0.0
     */
    private static function hasNoAddress(array $row): bool
    {
        return trim((string) ($row['email'] ?? '')) === '';
    }

    /**
     * Erasure had already taken the address before the export ran, which is
     * what separates it from a key this site cannot open.
     *
     * @param  array<string,mixed> $row
     * @since 1.0.0
     */
    private static function wasErased(array $row): bool
    {
        return trim((string) ($row['redacted_at'] ?? '')) !== '';
    }

    /**
     * What a shell is matched on here.
     *
     * A donation of theirs is the strongest handle available: it survives
     * erasure, and finding it means the file is being restored onto a site that
     * already holds part of it, so the shell belongs to a row that is already
     * here rather than beside it. That is what makes a resumed or repeated run
     * leave one anonymous donor instead of one per run.
     *
     * Failing that, a value derived from the file's origin and the row it held
     * there: unique per source row, identical on every run of the same file,
     * and derived from nothing about a person. It cannot be the address hash,
     * which is peppered per install and deliberately does not travel.
     *
     * @since 1.0.0
     */
    private function shellHash(int $sourceId): string
    {
        $anchor = $this->shellAnchor[$sourceId] ?? null;
        if ($anchor !== null) {
            $hash = $this->hashOfDonorBehind($anchor);
            if ($hash !== '') return $hash;
        }

        return hash('sha256', 'dono-restored-shell:' . $this->origin . ':' . $sourceId);
    }

    /**
     * The address hash of whoever owns this donation here, or an empty string
     * when this site does not have it.
     *
     * A reference only identifies a donation within one site. The counter
     * behind it starts at one on every install and the default prefix is the
     * same everywhere, so DONO-2026-00001 exists on most of them and belongs to
     * a different person on each. Matching on the reference alone would resolve
     * a nameless erased donor onto whichever live supporter happens to hold
     * that number here, and hand them the erased person's consents, receipts
     * and recurring plans. So the donation itself has to be the same donation,
     * not merely the same number.
     *
     * @param  array<string,mixed> $anchor the donation as the file holds it
     * @since 1.0.0
     */
    private function hashOfDonorBehind(array $anchor): string
    {
        $reference = trim((string) ($anchor['reference'] ?? ''));
        if ($reference === '') return '';

        $donation = DB::table('dono_donations')->where('reference', $reference)->get();
        if (! self::sameDonation($anchor, $donation)) return '';

        $donorId = (int) self::field($donation, 'donor_id');
        if ($donorId <= 0) return '';

        return (string) self::field(DB::table('dono_donors')->where('id', $donorId)->get(), 'email_hash');
    }

    /**
     * Whether the donation standing here is the one the file is describing.
     *
     * What was charged, in what currency, at what second. None of the three is
     * rewritten by anything that happens to a donation afterwards, and two
     * unrelated sites agreeing on all three as well as the reference is not a
     * case worth trading a donor merge for.
     *
     * @param  array<string,mixed> $fromFile
     * @since 1.0.0
     */
    private static function sameDonation(array $fromFile, mixed $here): bool
    {
        if ($here === null) return false;

        foreach (['amount_cents', 'currency', 'created_at'] as $column) {
            $there = trim((string) ($fromFile[$column] ?? ''));
            if ($there === '' || $there !== trim((string) self::field($here, $column))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Shells land before their donations do, so the donation that identifies
     * them has to be found before the walk starts. Only the rows that need one
     * are indexed: a decade of donations is not retained to place a handful of
     * shells.
     *
     * @param array<string,mixed> $tables
     * @since 1.0.0
     */
    private function indexShellDonors(array $tables): void
    {
        $wanted = [];
        foreach (($tables['dono_donors'] ?? []) as $row) {
            if (! is_array($row) || ! self::hasNoAddress($row)) continue;

            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) $wanted[$id] = true;
        }

        if ($wanted === []) return;

        foreach (($tables['dono_donations'] ?? []) as $row) {
            if (! is_array($row)) continue;

            $donorId   = (int) ($row['donor_id'] ?? 0);
            $reference = trim((string) ($row['reference'] ?? ''));

            if ($reference === '' || ! isset($wanted[$donorId])) continue;
            if (isset($this->shellAnchor[$donorId])) continue;

            $this->shellAnchor[$donorId] = $row;
        }
    }

    /**
     * Seals the plaintext the export carries under this site's key.
     *
     * Every table with PII needs this, not only donors: a staff note and a
     * donation's custom field answers arrive as body and custom_data, and no
     * table has a column by either name.
     *
     * @param  array<string,mixed> $row
     * @return array<string,mixed>
     * @since 1.0.0
     */
    private function reseal(string $table, array $row): array
    {
        foreach (DataExporter::encryptedColumns()[$table] ?? [] as $column => $required) {
            $plain = $row[$column] ?? null;
            $value = is_scalar($plain) ? (string) $plain : '';
            unset($row[$column]);

            // A NOT NULL column is written even when the export carried
            // nothing, or the row it belongs to cannot land at all.
            if ($value !== '' || $required) {
                $row[$column . '_encrypted'] = $this->crypto->encrypt($value);
            }
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
            // Every table in ORDER declares one. Reachable only if the two
            // lists drift apart, and then the table duplicates on a second run.
            return 0;
        }

        $id = self::lookup($table, $row, $key);
        if ($id > 0) return $id;

        $alsoUnique = self::ALSO_UNIQUE[$table] ?? null;
        if ($alsoUnique === null) return 0;

        $onlyWhenEmpty = self::ALSO_UNIQUE_WHEN_EMPTY[$table] ?? null;
        if ($onlyWhenEmpty !== null && (string) ($row[$onlyWhenEmpty] ?? '') !== '') return 0;

        return self::lookup($table, $row, $alsoUnique);
    }

    /**
     * @param  array<string,mixed> $row
     * @param  list<string>        $key
     * @since 1.0.0
     */
    private static function lookup(string $table, array $row, array $key): int
    {
        $query = DB::table($table);
        foreach ($key as $column) {
            $value = $row[$column] ?? null;
            if ($value === null || $value === '') return 0;
            $query->where($column, $value);
        }

        return (int) self::field($query->get(), 'id');
    }

    /**
     * DB::table()->get() hands back an array while DB::raw() hands back
     * objects, and reading only one of them silently finds nothing.
     *
     * @since 1.0.0
     */
    private static function field(mixed $row, string $column): mixed
    {
        if (is_array($row))  return $row[$column] ?? null;
        if (is_object($row)) return $row->$column ?? null;

        return null;
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
     * Rebuilds every total the restored donations belong to.
     *
     * Nothing else will. Rows are inserted straight, firing none of the
     * donation hooks that keep these current, and each total is read from a
     * stored column rather than summed on demand: the funds screen, the
     * campaign progress bar on the public page and a donor's lifetime giving
     * all show what the column says.
     *
     * A fund, campaign or donor already on this site is matched rather than
     * written, and keeps the figure it held before the restore, so matching one
     * is precisely the case that has to be rebuilt rather than trusted. The
     * default fund makes that the ordinary case, not the exotic one: activation
     * creates 'general' on every install and it is where donations land when no
     * fund is chosen.
     *
     * Bounded by what the file carried, and each call is one aggregate query.
     *
     * @since 1.0.0
     */
    private function syncAggregates(): void
    {
        $syncer = new AggregateSyncer();

        foreach ($this->map['dono_funds'] ?? [] as $id) {
            $syncer->syncFund((int) $id);
        }

        foreach ($this->map['dono_campaigns'] ?? [] as $id) {
            $syncer->syncCampaign((int) $id);
        }

        foreach ($this->map['dono_donors'] ?? [] as $id) {
            $syncer->syncDonor((int) $id);
        }

        foreach ($this->map['dono_forms'] ?? [] as $id) {
            $syncer->syncForm((int) $id);
        }
    }

    /**
     * Raises each reference counter past the numbers the file brought in.
     *
     * The counters are per install and no export carries them, so a restore
     * onto a fresh site leaves them at zero while the donations that just
     * landed already hold DONO-2026-00001. next() then mints a reference
     * UNIQUE(reference) refuses, and because it runs inside the donation's own
     * transaction the increment rolls back with the failed insert, so every
     * later donor mints the same colliding number and no donation can be taken
     * again. Receipts are numbered from the same counters under
     * UNIQUE(renderer_id, receipt_number) and stop issuing the same way.
     *
     * A candidate is accepted only when the generator's own format() reproduces
     * the stored string exactly, which is the only way one can collide.
     * format() is injective, so the trailing digits are the only counter value
     * that can produce a given reference, and a reference from another year,
     * another prefix or another org's numbering settings is left alone instead
     * of dragging the counter up behind it. Reading the printed form is
     * otherwise not something to reverse-engineer: prefix, separator, padding
     * and whether the year appears at all are configurable.
     *
     * Which numbering is in force therefore decides which references count, and
     * a file carries its org's numbering with it, so a restore writes the
     * settings before the rows and this reads the file's references in the
     * numbering the next one will be minted under. A counter already past the
     * file's high-water mark is left where it is.
     *
     * @param array<string,mixed> $export
     * @since 1.0.0
     */
    private function raiseReferenceCounters(array $export): void
    {
        $tables     = is_array($export['tables'] ?? null) ? $export['tables'] : [];
        $clock      = new SystemClock();
        $references = new ReferenceGenerator($clock);
        $year       = (int) $clock->now()->format('Y');

        foreach (self::REFERENCE_SCOPES as $scope => [$table, $column]) {
            $high = 0;

            foreach (($tables[$table] ?? []) as $row) {
                if (! is_array($row)) continue;

                $printed = trim((string) ($row[$column] ?? ''));
                if ($printed === '' || ! preg_match('/(\d+)$/', $printed, $match)) continue;

                $counter = (int) $match[1];
                if ($counter <= $high) continue;
                if ($references->format($scope, $year, $counter) !== $printed) continue;

                $high = $counter;
            }

            if ($high === 0) {
                continue;
            }

            // Reading the counter and raising it are one step behind one guard,
            // because they fail the same way: both go to the same option on the
            // same connection. Every row is in by now, so anything that leaves
            // here costs the caller the report of what landed and the erasure
            // deferral behind it, whichever of the two calls threw.
            try {
                // peekNext() is the last used value plus one, so the counter
                // has cleared $high only when peekNext() is greater than $high.
                if ($high < $references->peekNext($scope)) {
                    continue;
                }

                // The site keeps taking donations while this runs, and one
                // taken between that read and this raise moves the counter
                // itself, which nextNumber() then refuses to walk backwards.
                $references->nextNumber($scope, $high + 1);
            } catch (Throwable $e) {
                if ($this->counterClears($references, $scope, $high)) continue;

                ErrorLog::record(
                    'transfer.import',
                    sprintf(
                        'Restore could not raise the %s reference counter past %d, so the next one minted can repeat a number the file brought in and the unique index will refuse it. Set the next number on the numbering screen before another donation is taken. The raise failed with: %s',
                        $scope,
                        $high,
                        $e->getMessage()
                    ),
                    ['scope' => $scope, 'high_water_mark' => $high]
                );
            }
        }
    }

    /**
     * Whether the counter is past what the file printed, however it got there.
     *
     * Behind its own guard because whatever refused the raise can refuse this
     * read too, and the question here is only whether to report a failure.
     *
     * @since 1.0.0
     */
    private function counterClears(ReferenceGenerator $references, string $scope, int $high): bool
    {
        try {
            return $references->peekNext($scope) > $high;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Tables the file carries that this importer has no contract for, which is
     * how an add-on's tables arrive: dono.export.tables puts them in the file,
     * and restoring one needs a natural key, a reference map and a deferred
     * map it has not declared. Without a natural key a second run would
     * duplicate every row of it, so they are named to the operator rather than
     * written wrongly or passed over in silence.
     *
     * @param array<string,mixed> $tables
     * @since 1.0.0
     */
    private function recordUnknownTables(array $tables): void
    {
        foreach ($tables as $table => $rows) {
            $table = (string) $table;
            if (in_array($table, self::ORDER, true) || ! is_array($rows) || $rows === []) {
                continue;
            }

            $count = count($rows);
            $this->dropped[$table]['unsupported_table'] = $count;
            $this->skipped[$table] = ($this->skipped[$table] ?? 0) + $count;
        }
    }

    /**
     * A restore that quietly loses a donation is worse than one that fails, so
     * whatever did not land is written where the site owner reads failures,
     * not only handed back to the screen that started it.
     *
     * @since 1.0.0
     */
    private function reportDropped(): void
    {
        if ($this->dropped === []) return;

        $total = 0;
        $parts = [];
        foreach ($this->dropped as $table => $reasons) {
            foreach ($reasons as $why => $count) {
                $total  += $count;
                $parts[] = sprintf('%s %s (%s)', $count, $table, $why);
            }
        }

        ErrorLog::record(
            'transfer.import',
            sprintf(
                'Restore could not place %d rows: %s. They are still in the file, and re-running it once the cause is fixed brings them in.',
                $total,
                implode(', ', $parts)
            ),
            ['dropped' => $this->dropped]
        );
    }

    /**
     * A donor arriving with no address is normally an erasure, which needs no
     * telling. One arriving because the key stayed behind is a different thing
     * and the operator has to hear it: the money is intact but the person
     * behind it is gone for good, and no later import brings them back.
     *
     * @since 1.0.0
     */
    private function reportUnreadable(): void
    {
        if ($this->unreadable === 0) return;

        ErrorLog::record(
            'transfer.import',
            sprintf(
                'Restore kept %d donors whose address this site cannot read, and marked them erased so nothing mails them. Their donations, refunds, receipts and consents came with them; their names and addresses did not, and re-running the file will not recover them.',
                $this->unreadable
            ),
            ['unreadable_donors' => $this->unreadable]
        );
    }

    /**
     * @param array<string,int> $bucket
     * @since 1.0.0
     */
    private function bump(array &$bucket, string $table): void
    {
        $bucket[$table] = ($bucket[$table] ?? 0) + 1;
    }

    /**
     * Records why a row is not landing, and hands back the null its callers
     * return.
     *
     * @return array<string,mixed>|null
     * @since 1.0.0
     */
    private function drop(string $table, string $why): ?array
    {
        $this->dropped[$table][$why] = ($this->dropped[$table][$why] ?? 0) + 1;

        return null;
    }
}
