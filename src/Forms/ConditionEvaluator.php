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
        // The client evaluates amount conditions against the chosen amount;
        // the payload's amount_cents additionally folds the covered fee in.
        // Compare on the net so both sides show/require the same fields.
        if ($field === 'amount_cents') {
            $gross = (int) ($body['amount_cents'] ?? 0);
            $fee   = min($gross, max(0, (int) ($body['fee_covered_cents'] ?? 0)));
            return $gross - $fee;
        }
        $value = $body;
        foreach (explode('.', $field) as $part) {
            if (! is_array($value) || ! array_key_exists($part, $value)) {
                return null;
            }
            $value = $value[$part];
        }
        // Mirror JS String(): the client compares String(true) = 'true' and
        // String(['a','b']) = 'a,b'; PHP would cast to '1'/'' and 'Array'.
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_array($value)) {
            return implode(',', array_map('strval', $value));
        }
        return $value;
    }
}
