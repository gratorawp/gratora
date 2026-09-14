<?php

declare(strict_types=1);

namespace Gratora\Foundation\Helpers;

/**
 * Merge tags a template may spell more than one way.
 *
 * The admin says organization throughout, in the labels beside the very fields
 * these tags go in, while the tag itself is organisation. The panels insert the
 * canonical spelling on a click, so the trap is the hand-typed one: on a
 * receipt it reached the donor as literal braces, and on a tax statement the
 * sweep that clears unexpanded tags deleted it outright.
 *
 * Both spellings resolve to the same value. The canonical one stays canonical:
 * stored templates carry it, and the chips keep inserting it.
 *
 * @since 1.0.0
 */
final class TemplateTokens
{
    /** Canonical tag name => the other spelling a person is likely to type. */
    private const SPELLINGS = [
        'organisation_name' => 'organization_name',
    ];

    /**
     * @param array<string,string> $map '{tag}' => value
     * @return array<string,string>
     *
     * @since 1.0.0
     */
    public static function withSpellings(array $map): array
    {
        foreach (self::SPELLINGS as $canonical => $alias) {
            $from = '{' . $canonical . '}';
            $to   = '{' . $alias . '}';

            if (array_key_exists($from, $map) && ! array_key_exists($to, $map)) {
                $map[$to] = $map[$from];
            }
        }

        return $map;
    }

    /**
     * The same, for callers holding tag names without their braces.
     *
     * @param array<string,string> $tokens tag => value
     * @return array<string,string>
     *
     * @since 1.0.0
     */
    public static function withSpellingsUnwrapped(array $tokens): array
    {
        foreach (self::SPELLINGS as $canonical => $alias) {
            if (array_key_exists($canonical, $tokens) && ! array_key_exists($alias, $tokens)) {
                $tokens[$alias] = $tokens[$canonical];
            }
        }

        return $tokens;
    }
}
