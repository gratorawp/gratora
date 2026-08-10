<?php

declare(strict_types=1);

namespace Dono\Donors\Erasure;

use Dono\Analytics\Event;

/**
 * The analytics event log. Rows survive so campaign and form totals do not move
 * under an erasure; what the donor did stops being attributable to them.
 *
 * `payload` is in DSAR export scope already (DonorMetricsService::exportData
 * hands the donor their own event payloads), which settles the question of
 * whether it is their personal data. The hashes go with it: a session, IP or
 * user-agent hash is exactly what re-links an anonymized row back to a person.
 *
 * @since 1.0.0
 */
final class AnalyticsEventHandler implements ErasureHandler
{
    private const CLEARED = [
        'payload'         => null,
        'session_hash'    => null,
        'ip_hash'         => null,
        'user_agent_hash' => null,
        'country'         => null,
    ];

    /** @since 1.0.0 */
    public function key(): string
    {
        return 'dono.analytics_events';
    }

    /** @since 1.0.0 */
    public function erase(ErasureRequest $request): void
    {
        Event::query()->where('donor_id', $request->donorId)->update(self::CLEARED);

        if ($request->donationIds !== []) {
            // A donation-scoped event may predate the donor being resolved, so
            // donor_id alone misses it.
            Event::query()->whereIn('donation_id', $request->donationIds)->update(self::CLEARED);
        }

        // Backstop for events attached to neither: an abandoned checkout keeps
        // the email in its payload with no donor and no donation to key on.
        // One grouped scan, not one per needle; there are a dozen or more of
        // them and each pass is a full LIKE over the log.
        if ($request->hasNoNeedles()) return;

        $patterns = $request->likePatterns();
        Event::query()
            ->where(static function ($q) use ($patterns): void {
                $first = array_shift($patterns);
                $q->whereLike('payload', $first);
                foreach ($patterns as $pattern) {
                    $q->orWhereLike('payload', $pattern);
                }
            })
            ->update(self::CLEARED);
    }
}
