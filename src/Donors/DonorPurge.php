<?php

declare(strict_types=1);

namespace Gratora\Donors;

use Gratora\Async\AsyncDispatcher;
use Gratora\Foundation\Batch\BatchProcessor;
use Gratora\Foundation\Time\Clock;

/**
 * Replace email_hash after the redaction retention window, ending donor re-identification. Use
 * a unique row-derived hash because the column is UNIQUE. Applies to all redactions regardless
 * of automatic-erasure settings; zero means immediate purge.
 *
 * @since 1.0.0
 */
final class DonorPurge
{
    public const HOOK = 'gratora.cron.donor_purge';
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
        return hash('sha256', 'gratora-purged:' . $donorId);
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

        $donor->updateColumns([
            'email_hash'   => $donor->email_hash,
            'purged_at'    => $donor->purged_at,
            'flags'        => $donor->flags,
            'household_id' => $donor->household_id,
            'updated_at'   => $donor->updated_at,
        ]);
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
        $opt    = get_option('gratora_privacy', []);
        $stored = is_array($opt) ? (int) ($opt['retention_days_after_redaction'] ?? 90) : 90;

        return max(0, (int) apply_filters('gratora.donor.retention_days_after_redaction', $stored));
    }
}
