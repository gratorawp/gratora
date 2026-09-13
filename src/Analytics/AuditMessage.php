<?php

declare(strict_types=1);

namespace Gratora\Analytics;

defined('ABSPATH') || exit;

/**
 * What an audit row says was done.
 *
 * The type is a database key, so on the log screen it is the actor's name that
 * carries the row and the deed that goes missing. The emitters store what they
 * did in the payload; this turns it back into a sentence.
 *
 * Phrased at read time so it follows the reader's language rather than the
 * language in force when the act was performed, which is the same reason the
 * `gratora.audit.message` filter exists for an add-on's own types.
 *
 * @since 1.0.0
 */
final class AuditMessage
{
    /**
     * A sentence naming the act, or an empty string for a type this does not
     * know, which leaves the caller's own fallback in charge.
     *
     * @param array<string,mixed> $payload
     *
     * @since 1.0.0
     */
    public static function for(string $type, array $payload): string
    {
        switch ($type) {
            case 'donation.trashed':
                return self::aboutDonation(
                    /* translators: %s: a donation reference, e.g. DON-2026-000123 */
                    __('Donation %s was moved to trash.', 'gratora-donation-platform'),
                    $payload
                );

            case 'donation.restored':
                return self::aboutDonation(
                    /* translators: %s: a donation reference, e.g. DON-2026-000123 */
                    __('Donation %s was restored from trash.', 'gratora-donation-platform'),
                    $payload
                );

            case 'donation.deleted':
                return self::aboutDonation(
                    /* translators: %s: a donation reference, e.g. DON-2026-000123 */
                    __('Donation %s was deleted for good.', 'gratora-donation-platform'),
                    $payload
                );

            case 'donation.untrashed_by_settlement':
                return self::aboutDonation(
                    /* translators: %s: a donation reference, e.g. DON-2026-000123 */
                    __('Donation %s came back from trash because its payment settled.', 'gratora-donation-platform'),
                    $payload
                );

            case 'donor.deleted':
                return self::donorDeleted($payload);

            case 'donor.redacted':
                return self::donorRedacted($payload);

            case 'donor.portal_link_issued':
                return __('A portal sign-in link was issued for this donor.', 'gratora-donation-platform');

            case 'donor.orphans_cleared':
                return self::orphansCleared($payload);
        }

        return '';
    }

    /**
     * @param array<string,mixed> $payload
     *
     * @since 1.0.0
     */
    private static function aboutDonation(string $sentence, array $payload): string
    {
        $reference = trim((string) ($payload['reference'] ?? ''));

        return sprintf(
            $sentence,
            $reference !== '' ? $reference : __('with no reference', 'gratora-donation-platform')
        );
    }

    /**
     * @param array<string,mixed> $payload
     *
     * @since 1.0.0
     */
    private static function donorDeleted(array $payload): string
    {
        $said = ! empty($payload['was_redacted'])
            ? __('An already erased donor record was deleted.', 'gratora-donation-platform')
            : __('A donor record was deleted.', 'gratora-donation-platform');

        $donations = (int) ($payload['donations_deleted'] ?? 0);
        $plans     = (int) ($payload['plans_stopped'] ?? 0);

        $took = [];

        // Two counts, each pluralised on its own: one sentence pluralised on
        // the first reads "20 donations and 1 subscriptions".
        if ($donations > 0) {
            $took[] = sprintf(
                /* translators: %d: number of donations */
                _n('%d donation', '%d donations', $donations, 'gratora-donation-platform'),
                $donations
            );
        }

        if ($plans > 0) {
            $took[] = sprintf(
                /* translators: %d: number of recurring plans */
                _n('%d subscription', '%d subscriptions', $plans, 'gratora-donation-platform'),
                $plans
            );
        }

        if ($took === []) {
            return $said;
        }

        return $said . ' ' . sprintf(
            /* translators: %s: a list such as "19 donations and 1 subscription" */
            __('It took %s with it.', 'gratora-donation-platform'),
            wp_sprintf('%l', $took)
        );
    }

    /**
     * @param array<string,mixed> $payload
     *
     * @since 1.0.0
     */
    private static function donorRedacted(array $payload): string
    {
        $said = __('A donor was erased.', 'gratora-donation-platform');
        $kept = (int) ($payload['donations_retained'] ?? 0);

        if ($kept <= 0) {
            return $said;
        }

        return $said . ' ' . sprintf(
            /* translators: %d: number of donations kept against the donor's erased record */
            _n(
                '%d donation was kept.',
                '%d donations were kept.',
                $kept,
                'gratora-donation-platform'
            ),
            $kept
        );
    }

    /**
     * @param array<string,mixed> $payload
     *
     * @since 1.0.0
     */
    private static function orphansCleared(array $payload): string
    {
        $removed = is_array($payload['removed'] ?? null) ? $payload['removed'] : [];

        $total = 0;
        foreach ($removed as $count) {
            $total += (int) $count;
        }

        if ($total <= 0) {
            return __('Stranded records were cleared.', 'gratora-donation-platform');
        }

        return sprintf(
            /* translators: %d: number of stranded rows removed */
            _n(
                '%d stranded record was cleared.',
                '%d stranded records were cleared.',
                $total,
                'gratora-donation-platform'
            ),
            $total
        );
    }
}
