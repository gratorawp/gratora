<?php

declare(strict_types=1);

namespace Dono\Foundation\Helpers;

/**
 * CSV helpers with formula-injection protection.
 *
 * Spreadsheet apps treat cells starting with =, +, -, @, TAB, or CR as formulas;
 * a crafted donor name could exfiltrate data on export. safe() prefixes such
 * cells with an apostrophe (rendered as text) and writeRow() applies it to all cells.
 *
 * @since 1.0.0
 */
final class Csv
{
    /**
     * @param resource $stream
     * @since 1.0.0
     */
    public static function writeRow($stream, array $row): void
    {
        fputcsv($stream, array_map([self::class, 'safe'], $row));
    }

    /** @since 1.0.0 */
    public static function safe(mixed $value): string
    {
        $str = is_scalar($value) || $value === null ? (string) $value : '';
        if ($str === '') return '';

        $first = $str[0];
        if ($first === '=' || $first === '+' || $first === '-' || $first === '@'
            || $first === "\t" || $first === "\r"
        ) {
            return "'" . $str;
        }
        return $str;
    }
}
