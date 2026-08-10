<?php

declare(strict_types=1);

namespace Dono\Foundation\Commands;

/**
 * HMAC-based confirmation digest and verification for mutating MCP commands.
 *
 * @since 1.0.0
 */
final class ConfirmationGate
{
    /** @since 1.0.0 */
    public static function digest(string $commandId, array $canonicalInput): string
    {
        return hash('sha256', $commandId . '|' . self::canonicalJson($canonicalInput));
    }

    /**
     * @param array<string,mixed> $input
     * @since 1.0.0
     */
    public static function inputDigest(array $input): string
    {
        return hash('sha256', self::canonicalJson($input));
    }

    /**
     * Fail-closed: an mcp + mutating dispatch may proceed only when an MCP
     * token store is registered via the dono.commands.confirmation_verifier
     * filter AND it accepts the token for this binding. No store registered
     * means every mcp-mutating call is rejected.
     *
     * @since 1.0.0
     */
    public static function verify(?string $token, string $session, string $commandId, string $inputDigest): bool
    {
        if ($token === null || $token === '') {
            return false;
        }
        $verifier = apply_filters('dono.commands.confirmation_verifier', null);
        if (! is_object($verifier) || ! method_exists($verifier, 'verify')) {
            return false;
        }
        return (bool) $verifier->verify($token, $session, $commandId, $inputDigest);
    }

    /**
     * @param array<string,mixed> $value
     * @since 1.0.0
     */
    private static function canonicalJson(array $value): string
    {
        self::ksortRecursive($value);
        return (string) wp_json_encode($value);
    }

    /**
     * @param array<string,mixed> $value
     * @since 1.0.0
     */
    private static function ksortRecursive(array &$value): void
    {
        ksort($value);
        foreach ($value as &$v) {
            if (is_array($v)) {
                self::ksortRecursive($v);
            }
        }
        unset($v);
    }
}
