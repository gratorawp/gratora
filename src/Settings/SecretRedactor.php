<?php

declare(strict_types=1);

namespace Dono\Settings;

/**
 * The single owner of "never hand a stored secret back out".
 *
 * The Stripe webhook signing secret is the only authentication on the webhook
 * route, so handing it back over REST would let any holder of the delegatable
 * `dono_manage_settings` cap forge a paid donation with no donations capability
 * at all.
 *
 * Redacted values come back as {@see self::MASK}. The write path must call
 * {@see self::restore()} so a client that echoes a masked value back does not
 * overwrite the real secret with the mask.
 *
 * @since 1.0.0
 */
final class SecretRedactor
{
    public const MASK = '***';

    // The trailing (?![a-z]) stops a secret word from matching as the prefix of a
    // longer word: "tokens" (a brand preset's design tokens) would match "token"
    // and mask the whole object, dropping every preset's colors over REST. Real
    // secret keys (webhook_secret_test, access_token, secret_key, client_secret)
    // end the secret word at a separator or the end of the key, so they match.
    private const SECRET_KEY_PATTERN = '/(?:secret|password|token|api[_-]?key|private[_-]?key|webhook)(?![a-z])/i';

    /** @since 1.0.0 */
    public static function isSecretKey(string $key): bool
    {
        return (bool) preg_match(self::SECRET_KEY_PATTERN, $key);
    }

    /**
     * Replace every secret-shaped value with the mask, walking nested arrays to
     * any depth. A matching key masks its whole value, scalar or subtree; other
     * array values are recursed into.
     *
     * A key that holds nothing stays empty rather than becoming a mask, so the
     * UI can still tell "not configured" from "configured but hidden".
     *
     * @param array<string,mixed> $values
     * @return array<string,mixed>
     *
     * @since 1.0.0
     */
    public static function redact(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && self::isSecretKey($key)) {
                $values[$key] = self::maskValue($value);
                continue;
            }
            if (is_array($value)) {
                $values[$key] = self::redact($value);
            }
        }
        return $values;
    }

    /**
     * Put back whatever the mask is standing in for. Anything the caller sent
     * that is not the mask is a genuine change and survives untouched, so a
     * secret can still be replaced or cleared.
     *
     * @param array<string,mixed> $incoming what the client sent
     * @param array<string,mixed> $stored   what is on disk
     * @return array<string,mixed>
     *
     * @since 1.0.0
     */
    public static function restore(array $incoming, array $stored): array
    {
        foreach ($incoming as $key => $value) {
            if (! array_key_exists($key, $stored)) {
                continue;
            }
            if ($value === self::MASK) {
                $incoming[$key] = $stored[$key];
                continue;
            }
            if (is_array($value) && is_array($stored[$key])) {
                $incoming[$key] = self::restore($value, $stored[$key]);
            }
        }
        return $incoming;
    }

    /**
     * @return mixed the mask, or an untouched empty value.
     *
     * @since 1.0.0
     */
    private static function maskValue(mixed $value): mixed
    {
        if ($value === '' || $value === null) {
            return $value;
        }
        if (is_array($value)) {
            return $value === [] ? $value : self::MASK;
        }
        return self::MASK;
    }
}
