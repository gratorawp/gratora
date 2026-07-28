<?php

declare(strict_types=1);

namespace Dono\Donors;

use Dono\Async\AsyncDispatcher;
use Dono\Foundation\Batch\BatchProcessor;
use Dono\Foundation\Time\Clock;
use Dono\Vendor\Queryable\DB;

/**
 * Severs the last handle on an already-redacted donor, `retention_days_after_
 * redaction` days later.
 *
 * Redaction clears the PII but deliberately keeps `email_hash`, and that hash
 * is not leftover debris: `DonorService::findOrCreate($email, ...,
 * reactivateIfRedacted: true)` matches on it, so a donor who gives again is
 * un-redacted and reunited with their giving history. That is the whole point
 * of the setting being a *window* rather than a switch. Inside it, someone who
 * comes back is the same supporter again. Once it closes, the hash is replaced
 * and they are a new person, while their old donations stay counted against the
 * anonymous shell.
 *
 * The hash is replaced rather than emptied because `email_hash` is UNIQUE:
 * blanking it would collide on the second donor purged. The replacement is
 * derived from the row id alone, so it stays unique and says nothing about
 * anyone.
 *
 * Note the setting reads differently from its neighbours on the privacy panel:
 * `donor_retention_years` and `event_retention_days` treat 0 as "disabled",
 * whereas here 0 means "sever at redaction time". There is deliberately no
 * "never": leaving a re-identification handle on an erased donor indefinitely
 * would undo the erasure it belongs to.
 *
 * @version 1.0.0
 */
final class DonorPurge
{
    public const HOOK = 'dono.cron.donor_purge';
    private const DAILY = 86400;
    private const BATCH = 200;

    public function __construct(
        private AsyncDispatcher $async,
        private Clock $clock,
    ) {
    }

    public function register(): void
    {
        add_action(self::HOOK, [$this, 'run']);
        add_action('init', fn () => $this->async->scheduleRecurring(self::HOOK, self::DAILY));
    }

    /**
     * What `email_hash` becomes. Unique per row (the id already is), derived
     * from nothing about the person.
     */
    public static function severedHash(int $donorId): string
    {
        return hash('sha256', 'dono-purged:' . $donorId);
    }

    public function run(): void
    {
        $prefix = DB::getPrefix();
        $cutoff = $this->cutoff();

        $more = BatchProcessor::step(
            fn (int $n) => array_map(
                static fn ($r) => (int) ($r->id ?? 0),
                DB::raw(
                    "SELECT id FROM {$prefix}dono_donors
                     WHERE redacted_at IS NOT NULL
                       AND redacted_at <= %s
                       AND email_hash <> SHA2(CONCAT('dono-purged:', id), 256)
                     ORDER BY id ASC
                     LIMIT %d",
                    [$cutoff, $n]
                )['rows'] ?? []
            ),
            function (array $ids): void {
                foreach ($ids as $id) {
                    $donor = Donor::query()->where('id', $id)->get();
                    if ($donor) $this->purge($donor);
                }
            },
            self::BATCH,
            false
        );

        if ($more) {
            $this->async->enqueue(self::HOOK);
        }
    }

    /** Idempotent: a second call finds the hash already severed and changes nothing. */
    public function purge(Donor $donor): void
    {
        $severed = self::severedHash((int) $donor->id);
        if ($donor->email_hash === $severed) return;

        $donor->email_hash = $severed;
        // Donor-scoped preferences (always_anonymous) and the link to other
        // members of a household are both particular to a person; on a shell
        // that is no longer anyone they only serve to group rows back together.
        $donor->flags        = null;
        $donor->household_id = null;
        $donor->updated_at   = $this->clock->now()->format('Y-m-d H:i:s');
        $donor->save();
    }

    /** True when the window is zero, so redaction severs the handle on the spot. */
    public function purgesOnRedaction(): bool
    {
        return $this->retentionDays() <= 0;
    }

    private function cutoff(): string
    {
        $days = $this->retentionDays();

        return $this->clock->now()
            ->modify('-' . $days . ' days')
            ->format('Y-m-d H:i:s');
    }

    private function retentionDays(): int
    {
        $opt    = get_option('dono_privacy', []);
        $stored = is_array($opt) ? (int) ($opt['retention_days_after_redaction'] ?? 90) : 90;

        return max(0, (int) apply_filters('dono.donor.retention_days_after_redaction', $stored));
    }
}
