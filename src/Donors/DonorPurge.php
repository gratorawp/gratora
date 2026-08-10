<?php

declare(strict_types=1);

namespace Dono\Donors;

use Dono\Async\AsyncDispatcher;
use Dono\Foundation\Batch\BatchProcessor;
use Dono\Foundation\Time\Clock;

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
 * Note the setting reads differently from its neighbors on the privacy panel:
 * `donor_retention_years` and `event_retention_days` treat 0 as "disabled",
 * whereas here 0 means "sever at redaction time". There is deliberately no
 * "never": leaving a re-identification handle on an erased donor indefinitely
 * would undo the erasure it belongs to.
 *
 * @since 1.0.0
 */
final class DonorPurge
{
    public const HOOK = 'dono.cron.donor_purge';
    private const DAILY = 86400;
    private const BATCH = 200;

    /** @since 1.0.0 */
    public function __construct(
        private AsyncDispatcher $async,
        private Clock $clock,
    ) {
    }

    /** @since 1.0.0 */
    public function register(): void
    {
        add_action(self::HOOK, [$this, 'run']);
        add_action('init', fn () => $this->async->scheduleRecurring(self::HOOK, self::DAILY));
    }

    /**
     * What `email_hash` becomes. Unique per row (the id already is), derived
     * from nothing about the person.
     *
     * @since 1.0.0
     */
    public static function severedHash(int $donorId): string
    {
        return hash('sha256', 'dono-purged:' . $donorId);
    }

    /** @since 1.0.0 */
    public function run(): void
    {
        $cutoff = $this->cutoff();

        // purge() stamps purged_at, so handled rows drop out of this set and
        // BatchProcessor's re-query of the first N stays correct.
        $more = BatchProcessor::step(
            fn (int $n) => Donor::query()
                ->whereIsNotNull('redacted_at')
                ->where('redacted_at', $cutoff, '<=')
                ->whereIsNull('purged_at')
                ->orderBy('id')
                ->limit($n)
                ->getAll(),
            function (array $donors): void {
                foreach ($donors as $donor) {
                    $this->purge($donor);
                }
            },
            self::BATCH,
            false
        );

        if ($more) {
            $this->async->enqueue(self::HOOK);
        }
    }

    /**
     * Idempotent: a second call finds the row already stamped and changes nothing.
     *
     * @since 1.0.0
     */
    public function purge(Donor $donor): void
    {
        if ($donor->purged_at !== null) return;

        $now = $this->clock->now()->format('Y-m-d H:i:s');

        $donor->email_hash = self::severedHash((int) $donor->id);
        $donor->purged_at  = $now;
        // Donor-scoped preferences (always_anonymous) and the link to other
        // members of a household are both particular to a person; on a shell
        // that is no longer anyone they only serve to group rows back together.
        $donor->flags        = null;
        $donor->household_id = null;
        $donor->updated_at   = $now;
        $donor->save();
    }

    /**
     * True when the window is zero, so redaction severs the handle on the spot.
     *
     * @since 1.0.0
     */
    public function purgesOnRedaction(): bool
    {
        return $this->retentionDays() <= 0;
    }

    /** @since 1.0.0 */
    private function cutoff(): string
    {
        $days = $this->retentionDays();

        return $this->clock->now()
            ->modify('-' . $days . ' days')
            ->format('Y-m-d H:i:s');
    }

    /** @since 1.0.0 */
    private function retentionDays(): int
    {
        $opt    = get_option('dono_privacy', []);
        $stored = is_array($opt) ? (int) ($opt['retention_days_after_redaction'] ?? 90) : 90;

        return max(0, (int) apply_filters('dono.donor.retention_days_after_redaction', $stored));
    }
}
