<?php

declare(strict_types=1);

namespace Gratora\Donors;

defined('ABSPATH') || exit;

/**
 * What kind of donor a record is, in words.
 *
 * The types are written here and read on the donors list, its filter, the
 * profile and the CSV an owner hands their finance team. Each of those printed
 * the database word with a capital letter put on it by CSS, so a translated
 * site read "organization" in a column whose own filter said it in their
 * language.
 *
 * @since 1.0.0
 */
final class DonorType
{
    /** @since 1.0.0 */
    public static function label(string $type): string
    {
        return self::words()[$type] ?? str_replace('_', ' ', $type);
    }

    /**
     * The whole vocabulary, for a screen that offers them all as a filter.
     *
     * @return list<array{value:string, label:string}>
     *
     * @since 1.0.0
     */
    public static function all(): array
    {
        return array_map(
            static fn (string $type): array => ['value' => $type, 'label' => self::label($type)],
            Donor::TYPES
        );
    }

    /** @return array<string,string> */
    private static function words(): array
    {
        return [
            'individual'   => __('Individual', 'gratora-donation-platform'),
            'organization' => __('Organization', 'gratora-donation-platform'),
            'household'    => __('Household', 'gratora-donation-platform'),
        ];
    }
}
