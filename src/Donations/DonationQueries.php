<?php

declare(strict_types=1);

namespace Gratora\Donations;

use DateTimeImmutable;
use DateTimeZone;
use Gratora\Vendor\Queryable\DB;

/**
 * Shared query scopes for donation reads.
 *
 * `live()` is the single place the "exclude test-mode" rule lives, so all
 * aggregates, totals, and reports stay consistent. Raw-SQL callers add the
 * equivalent predicate inline.
 *
 * @since 1.0.0
 */
final class DonationQueries
{
    /**
     * Exclude test-mode donations. Returns the same query for fluent chaining.
     *
     * @template T
     * @param  T $q
     * @return T
     *
     * @since 1.0.0
     */
    public static function live($q)
    {
        return $q->where('is_test', 0);
    }

    /**
     * Statuses where money has moved, or is moving.
     *
     * `pending` is a checkout nobody finished and `failed` is one the processor
     * refused: neither ever took anything, so neither is history worth
     * protecting. `processing` is a bank debit still settling, which becomes
     * money, so it counts. `disputed` and the two refund states are money that
     * moved and was taken back, which is history either way.
     *
     * @var list<string>
     */
    public const MONEY_MOVED = ['paid', 'partial_refund', 'refunded', 'disputed', 'processing'];

    /**
     * Narrow to rows whose money has moved. Returns the same query for chaining.
     *
     * @template T
     * @param  T $q
     * @return T
     *
     * @since 1.0.0
     */
    public static function moneyMoved($q)
    {
        return $q->whereIn('status', self::MONEY_MOVED);
    }

    /**
     * Rows that are donation history: real money, given rather than exchanged.
     *
     * Event ticket orders ride the same table with kind='order'. They are a
     * purchase, not a donation, so they must stay out of donor lifetime totals,
     * campaign and fund rollups, donation reporting, receipts and the year-end
     * tax statement.
     *
     * Single owner of that rule: reach for it wherever "donations" is meant,
     * rather than repeating the where().
     *
     * @template T
     * @param  T $q
     * @return T
     *
     * @since 1.0.0
     */
    public static function donationsOnly($q)
    {
        return self::live($q)->where('kind', 'donation');
    }

    /**
     * Donation history, with test rows admitted only when the operator asked.
     *
     * The kind filter stays on both branches: "show me the test data" must
     * never quietly also mean "show me ticket orders", which are a purchase
     * rather than a donation and belong out of donation reporting either way.
     *
     * @template T
     * @param  T $q
     * @return T
     *
     * @since 1.0.0
     */
    public static function donationRows($q, bool $includeTest)
    {
        return $includeTest
            ? $q->where('kind', 'donation')
            : self::donationsOnly($q);
    }

    /**
     * Find replaced attempts only while pending; count them again if they settle.
     *
     * Query pending IDs separately to avoid reading unindexed flags for every donation.
     * JSON_VALID and COALESCE handle malformed JSON, absent keys, and JSON null on both MySQL
     * and MariaDB; MariaDB cannot CAST AS JSON.
     *
     * @since 1.0.0
     */
    private static function supersededIds(?int $donorId = null): string
    {
        $donations = DB::getPrefix() . 'gratora_donations';

        // Correlating to one donor turns a set the planner builds from every
        // pending row into one bounded by that donor's own attempts, which
        // idx_donor_id_status_paid_at can serve. A donor-scoped caller reads
        // this per row otherwise, and a long timeline pays the JSON check on
        // every one of them.
        $scope = $donorId !== null ? ' AND sup.donor_id = ' . $donorId : '';

        // Tested against 'NULL' rather than for a particular type name. That
        // is the one JSON_TYPE answer both engines are known to agree on, and
        // WEBHOOK_FAILED_SQL already rests on it; matching a type name instead
        // would rest on the rest of the vocabulary agreeing too.
        return "SELECT sup.id FROM {$donations} sup WHERE sup.status = 'pending' "
            . "AND COALESCE(JSON_TYPE(JSON_EXTRACT("
            . "IF(JSON_VALID(sup.flags), sup.flags, NULL), "
            . "'\$.retried_by')), 'NULL') <> 'NULL'" . $scope;
    }

    /**
     * Rows that are a replaced attempt. Pass a column to test something that
     * points at a donation rather than being one.
     *
     * @since 1.0.0
     */
    public static function supersededPredicate(?string $donationIdColumn = null): string
    {
        $column = $donationIdColumn ?? DB::getPrefix() . 'gratora_donations.id';

        return "{$column} IN (" . self::supersededIds() . ')';
    }

    /**
     * The complement, and not simply NOT of the above: `NULL NOT IN (...)`
     * evaluates to NULL rather than true, so an unguarded negation drops every
     * row whose column is NULL. gratora_events.donation_id is nullable and carries
     * the donor's magic links, consents and portal sign-ins, so the guard is
     * what keeps their timeline from emptying itself.
     *
     * @since 1.0.0
     */
    public static function notSupersededPredicate(?string $donationIdColumn = null, ?int $donorId = null): string
    {
        $column = $donationIdColumn ?? DB::getPrefix() . 'gratora_donations.id';

        return "({$column} IS NULL OR {$column} NOT IN (" . self::supersededIds($donorId) . '))';
    }

    /**
     * Keep replaced attempts out of a query on the donations table.
     *
     * Grouped because whereRaw carries no logical connector, so a bare raw
     * predicate has to be the first condition on its query. Inside a group of
     * its own it always is, and the group itself joins with AND, which makes
     * this safe to reach for at any point in a chain.
     *
     * @template T
     * @param  T $q
     * @return T
     *
     * @since 1.0.0
     */
    public static function notSuperseded($q)
    {
        return $q->where(static function ($g): void {
            $g->whereRaw(self::notSupersededPredicate());
        });
    }

    /**
     * The same rule for a table that points at a donation rather than being
     * one, gratora_events.donation_id among them. Pass the column qualified with
     * its table. A row pointing at nothing is left alone.
     *
     * @template T
     * @param  T $q
     * @return T
     *
     * @since 1.0.0
     */
    public static function notSupersededDonation($q, string $donationIdColumn, ?int $donorId = null)
    {
        return $q->where(static function ($g) use ($donationIdColumn, $donorId): void {
            $g->whereRaw(self::notSupersededPredicate($donationIdColumn, $donorId));
        });
    }

    /**
     * supersededPredicate() read off a hydrated row, for a screen that has to
     * label one rather than filter on it. Kept beside the SQL so the two
     * cannot come to disagree about what "replaced" means.
     *
     * @since 1.0.0
     */
    public static function isSuperseded(Donation $donation): bool
    {
        $flags = is_array($donation->flags) ? $donation->flags : [];

        // Present and not null, the same test the SQL makes. A narrower one
        // here would report a row live that every count has already hidden,
        // leaving nowhere that explains where it went.
        return (string) $donation->status === 'pending'
            && array_key_exists('retried_by', $flags)
            && $flags['retried_by'] !== null;
    }

    /** Ids of the rows an admin has taken off the working list. */
    private static function trashedIds(): string
    {
        $donations = DB::getPrefix() . 'gratora_donations';

        return "SELECT tr.id FROM {$donations} tr WHERE tr.trashed_at IS NOT NULL";
    }

    /**
     * Guarded the same way notSupersededPredicate is, and for the same reason:
     * `NULL NOT IN (...)` evaluates to NULL rather than true, so an unguarded
     * negation drops every row whose column is NULL.
     *
     * @since 1.0.0
     */
    public static function notTrashedPredicate(?string $donationIdColumn = null): string
    {
        $column = $donationIdColumn ?? DB::getPrefix() . 'gratora_donations.id';

        return "({$column} IS NULL OR {$column} NOT IN (" . self::trashedIds() . '))';
    }

    /**
     * Keep trashed rows out of a query on the donations table.
     *
     * A plain column test rather than the predicate above, because on this
     * table the column is present and idx_trashed_at_created_at serves it.
     *
     * @template T
     * @param  T $q
     * @return T
     *
     * @since 1.0.0
     */
    public static function notTrashed($q)
    {
        return $q->whereIsNull('trashed_at');
    }

    /**
     * The same rule for a table that points at a donation rather than being
     * one, gratora_events.donation_id among them. Pass the column qualified
     * with its table. A row pointing at nothing is left alone.
     *
     * @template T
     * @param  T $q
     * @return T
     *
     * @since 1.0.0
     */
    public static function notTrashedDonation($q, string $donationIdColumn)
    {
        return $q->where(static function ($g) use ($donationIdColumn): void {
            $g->whereRaw(self::notTrashedPredicate($donationIdColumn));
        });
    }

    /**
     * Whether an admin has taken this row off the working list.
     *
     * Read off a hydrated row for the gates that have one, so a donor-facing
     * charge route can treat a trashed donation exactly as it treats a settled
     * one. The charge lock only closes the site's own in-flight window, so the
     * durable stop has to be a predicate on the row.
     *
     * @since 1.0.0
     */
    public static function isTrashed(Donation $donation): bool
    {
        return ($donation->trashed_at ?? null) !== null;
    }

    /**
     * How many real-looking donations the live figures are leaving out.
     *
     * Campaign and fund rollups are synced through donationsOnly(), so there is
     * no test-inclusive version of raised_cents to offer. What a screen can do
     * is say how much it is not counting, which is the difference between a
     * figure that reads zero and a screen that looks broken.
     *
     * @since 1.0.0
     */
    public static function hiddenTestCount(): int
    {
        return (int) DB::table('gratora_donations')
            ->where('is_test', 1)
            ->where('kind', 'donation')
            ->whereIn('status', ['paid', 'partial_refund'])
            ->count();
    }

    /**
     * Rows whose base-currency value is unknown, so a total built on
     * netBaseExpr() is missing them.
     *
     * base_amount_cents is NULL when the donation's currency had no FX rate at
     * the time it was taken: money is never gated on reporting being
     * configured, so the donation is accepted and contributes 0. Counting them
     * lets a screen explain a campaign showing 22 donations raising what 21
     * raised.
     *
     * @since 1.0.0
     */
    public static function unconvertedExpr(): string
    {
        return 'SUM(CASE WHEN base_amount_cents IS NULL THEN 1 ELSE 0 END)';
    }

    /**
     * Correlated subquery: total succeeded refunds for the current
     * gratora_donations row, expressed in the org/base currency. Refunds are
     * stored in the donation currency, so each is scaled by the donation's
     * fx_rate (base per donation unit; NULL when the donation already is base).
     * Use only where gratora_donations is the main/correlated table.
     *
     * @since 1.0.0
     */
    public static function refundedBaseExpr(): string
    {
        $prefix    = DB::getPrefix();
        $refunds   = $prefix . 'gratora_refunds';
        $donations = $prefix . 'gratora_donations';
        // fx_rate is NULL only for a foreign donation we could not convert to
        // base (no rate available); such a row contributes nothing to base
        // totals, so its refunds must net to 0 too - scale by 0, not 1.
        //
        // Summed before rounding, not after. base_amount_cents is rounded once
        // from the whole amount, so rounding each refund separately and adding
        // them up can exceed the correctly-rounded value of the same total: at
        // 0.5107, two refunds of 50.00 give 2554 + 2554 = 5108 where the 100.00
        // they add up to is worth 5107. One rounding at the end, on one product.
        return "COALESCE((
            SELECT ROUND(SUM(amount_cents) * COALESCE({$donations}.fx_rate, 0))
            FROM {$refunds}
            WHERE donation_id = {$donations}.id AND status = 'succeeded'
        ), 0)";
    }

    /**
     * A donation's net contribution in the org/base currency: base amount minus
     * refunds (both in base). The canonical money expression every aggregate
     * should SUM, so cross-currency donations stay coherent and agree with the
     * campaign raised counter.
     *
     * @since 1.0.0
     */
    public static function netBaseExpr(): string
    {
        // base_amount_cents is NULL only for a foreign donation with no FX rate;
        // such a row has no known base value, so it must contribute 0 to base
        // sums rather than fold its raw foreign cents in (COALESCE to amount_cents
        // would corrupt every base-currency total). Base and converted rows
        // always have base_amount_cents set, so they are unaffected.
        return '(COALESCE(base_amount_cents, 0) - ' . self::refundedBaseExpr() . ')';
    }

    /**
     * A calendar year in the org's timezone, expressed as the UTC window to
     * compare paid_at against.
     *
     * paid_at is stored UTC, but every date printed on a statement or receipt
     * goes through wp_date() into the site's timezone. Filtering on a bare
     * "{year}-01-01 00:00:00" compares a local year against UTC timestamps, so
     * on any site west of UTC a late-December donation lands on the following
     * year's statement while its own line prints the December date, and the
     * donor's records disagree with the one the tax office sees.
     *
     * @return array{0:string,1:string} inclusive UTC start and end
     *
     * @since 1.0.0
     */
    public static function yearBoundsUtc(int $year): array
    {
        [$start, $end] = self::dayBoundsUtc(
            sprintf('%04d-01-01', $year),
            sprintf('%04d-12-31', $year)
        );

        return [(string) $start, (string) $end];
    }

    /**
     * A range of calendar days in the org's timezone, expressed as the UTC
     * window to compare paid_at against. Null passes through as "unbounded".
     *
     * Every reporting period is bounded through here so the revenue report,
     * its CSV, the dashboard ranges and the year-end statement all cut the
     * year in the same place. A period bounded in UTC instead puts a donation
     * taken at 23:30 on 31 December in one year on the org's books and in the
     * other on the donor's tax statement.
     *
     * @return array{0:?string,1:?string} inclusive UTC start and end
     *
     * @since 1.0.0
     */
    public static function dayBoundsUtc(?string $from, ?string $to): array
    {
        return [
            $from === null ? null : self::boundUtc($from, false),
            $to   === null ? null : self::boundUtc($to, true),
        ];
    }

    /**
     * SQL reading a UTC datetime column as the calendar date the org gave it
     * on, for the window between two UTC datetimes.
     *
     * @since 1.0.0
     */
    public static function localDateExpr(string $column, string $fromUtc, string $toUtc): string
    {
        return 'DATE(' . self::localStampExpr($column, $fromUtc, $toUtc) . ')';
    }

    /**
     * Convert UTC columns to local time using PHP-resolved offsets and DST branches. Both
     * window bounds are required; this avoids relying on installed MySQL timezone tables.
     *
     * @since 1.0.0
     */
    public static function localStampExpr(string $column, string $fromUtc, string $toUtc): string
    {
        $start = strtotime($fromUtc . ' UTC');
        $end   = strtotime($toUtc . ' UTC');
        if (! is_int($start) || ! is_int($end) || $end < $start) {
            return $column;
        }

        $transitions = self::siteTimezone()->getTransitions($start, $end);
        if ($transitions === false || $transitions === []) {
            return $column;
        }

        $offset = (int) $transitions[0]['offset'];
        if (count($transitions) === 1) {
            return $offset === 0 ? $column : "DATE_ADD({$column}, INTERVAL {$offset} SECOND)";
        }

        $case = '(CASE';
        foreach (array_slice($transitions, 1) as $t) {
            $case  .= sprintf(" WHEN {$column} < '%s' THEN %d", gmdate('Y-m-d H:i:s', (int) $t['ts']), $offset);
            $offset = (int) $t['offset'];
        }
        $case .= sprintf(' ELSE %d END)', $offset);

        return "DATE_ADD({$column}, INTERVAL {$case} SECOND)";
    }

    /** @since 1.0.0 */
    public static function siteTimezone(): DateTimeZone
    {
        return function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
    }

    /**
     * One end of a local calendar day as UTC. Anything that is not a plain
     * date is left for the database to reject rather than throwing part-way
     * through a report.
     *
     * @since 1.0.0
     */
    private static function boundUtc(string $value, bool $endOfDay): string
    {
        $value = trim($value);
        $day   = substr($value, 0, 10);
        $time  = $endOfDay ? ' 23:59:59' : ' 00:00:00';

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1) {
            return $value . $time;
        }

        return (new DateTimeImmutable($day . $time, self::siteTimezone()))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    }
}
