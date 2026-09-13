<?php

declare(strict_types=1);

namespace Gratora\Recurring;

defined('ABSPATH') || exit;

/**
 * What a recurring plan's status means, in words.
 *
 * The statuses are written here and read on four screens, a donor portal, a
 * GDPR export and a CSV. Each of those kept its own list of the same words, so
 * a status added on this side reached them as the database slug: a PayPal plan
 * waiting on activation showed the donor "pending" in English however their
 * site was translated, and no filter could find it. The words belong with the
 * code that writes the statuses.
 *
 * @since 1.0.0
 */
final class PlanStatus
{
    /**
     * Every status the table holds, in the order a plan passes through them.
     *
     * @var list<string>
     */
    public const LIFECYCLE = ['active', 'pending', 'past_due', 'paused', 'cancelled', 'expired'];

    /**
     * A plan in one of these accepts no further changes.
     *
     * @var list<string>
     */
    public const TERMINAL = ['cancelled', 'expired'];

    /** @since 1.0.0 */
    public static function label(string $status): string
    {
        return self::words()[$status] ?? str_replace('_', ' ', $status);
    }

    /**
     * The pill colour, as the admin's shared badge names them.
     *
     * @since 1.0.0
     */
    public static function variant(string $status): string
    {
        return [
            'active'    => 'green',
            'pending'   => 'amber',
            'past_due'  => 'amber',
            'paused'    => 'gray',
            'cancelled' => 'gray',
            'expired'   => 'gray',
        ][$status] ?? 'gray';
    }

    /** @since 1.0.0 */
    public static function isTerminal(string $status): bool
    {
        return in_array($status, self::TERMINAL, true);
    }

    /**
     * The whole vocabulary, for a screen that offers them all as a filter.
     *
     * @return list<array{value:string, label:string, variant:string}>
     *
     * @since 1.0.0
     */
    public static function all(): array
    {
        return array_map(
            static fn (string $status): array => [
                'value'   => $status,
                'label'   => self::label($status),
                'variant' => self::variant($status),
            ],
            self::LIFECYCLE
        );
    }

    /** @return array<string,string> */
    private static function words(): array
    {
        return [
            'active'    => __('Active', 'gratora-donation-platform'),
            'pending'   => __('Pending', 'gratora-donation-platform'),
            'past_due'  => __('Past due', 'gratora-donation-platform'),
            'paused'    => __('Paused', 'gratora-donation-platform'),
            'cancelled' => __('Cancelled', 'gratora-donation-platform'),
            'expired'   => __('Expired', 'gratora-donation-platform'),
        ];
    }
}
