<?php

declare(strict_types=1);

namespace Gratora\Campaigns\Styling;

/**
 * A font stack as a style attribute can carry it.
 *
 * WordPress before 7.0 filters a style attribute while its quotes are still
 * entities and cuts the declaration at the first one. A family is written
 * without quotes wherever CSS reads it the same, as a run of identifiers. One
 * that cannot be keeps its quotes where they survive and is left out where they
 * do not, so the rest of the stack still applies.
 *
 * @since 1.2.0
 */
final class FontStack
{
    private const IDENTIFIERS = '/^-?[A-Za-z_][A-Za-z0-9_-]*(?: -?[A-Za-z_][A-Za-z0-9_-]*)*$/';

    /** @since 1.2.0 */
    public static function forAttribute(string $stack, bool $quotesSurvive): string
    {
        $families = [];

        foreach (explode(',', $stack) as $family) {
            $name = trim((string) preg_replace('/\s+/', ' ', trim(trim($family), "\"'")));
            if ($name === '') {
                continue;
            }

            if (preg_match(self::IDENTIFIERS, $name) === 1) {
                $families[] = $name;
            } elseif ($quotesSurvive) {
                $families[] = '"' . $name . '"';
            }
        }

        return implode(', ', $families);
    }
}
