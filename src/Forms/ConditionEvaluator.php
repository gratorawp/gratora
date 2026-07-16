<?php

declare(strict_types=1);

namespace Dono\Forms;

/**
 * Server mirror of assets/donation-form/state/conditions.js so validation and
 * the client agree on which conditional blocks are shown for a given payload.
 */
final class ConditionEvaluator
{
    /** @param array<string,mixed> $body the submitted donation payload */
    public static function passes(?array $condition, array $body): bool
    {
        if (! $condition || empty($condition['field'])) {
            return true;
        }
        $actual   = self::valueFor((string) $condition['field'], $body);
        $expected = $condition['value'] ?? '';
        switch ((string) ($condition['op'] ?? '=')) {
            case '=':        return (string) ($actual ?? '') === (string) $expected;
            case '!=':       return (string) ($actual ?? '') !== (string) $expected;
            case '>':        return (float) $actual >  (float) $expected;
            case '>=':       return (float) $actual >= (float) $expected;
            case '<':        return (float) $actual <  (float) $expected;
            case '<=':       return (float) $actual <= (float) $expected;
            case 'contains': return str_contains(strtolower((string) ($actual ?? '')), strtolower((string) $expected));
            default:         return true;
        }
    }

    /** @param array<string,mixed> $body */
    private static function valueFor(string $field, array $body): mixed
    {
        // Authored conditions use the hyphenated frequency ('one-time'); the
        // payload normalized it to 'one_time'. Compare on the authored form.
        if ($field === 'frequency') {
            return str_replace('_', '-', (string) ($body['frequency'] ?? ''));
        }
        $value = $body;
        foreach (explode('.', $field) as $part) {
            if (! is_array($value) || ! array_key_exists($part, $value)) {
                return null;
            }
            $value = $value[$part];
        }
        return $value;
    }
}
