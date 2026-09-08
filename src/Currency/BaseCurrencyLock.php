<?php

declare(strict_types=1);

namespace FundKit\Currency;

use FundKit\Donations\Donation;
use FundKit\Donations\DonationQueries;

/**
 * Lock the base currency after money is recorded: stored base amounts cannot be reinterpreted
 * safely without historical conversion rates. Enforce at the settings write so all callers
 * share the guard.
 *
 * @since 1.0.0
 */
final class BaseCurrencyLock
{
    /**
     * Lock on live rows where money moved, including ticket orders. Pending and failed attempts
     * do not lock the base currency.
     *
     * @since 1.0.0
     */
    public static function liveDonations(): int
    {
        return (int) DonationQueries::moneyMoved(
            DonationQueries::live(Donation::query())
        )->count();
    }

    /** @since 1.0.0 */
    public static function isLocked(): bool
    {
        return self::liveDonations() > 0;
    }

    /**
     * @param array<string,mixed> $input   the partial group payload being written
     * @param array<string,mixed> $current the group as it stands
     *
     * @throws BaseCurrencyLocked
     *
     * @since 1.0.0
     */
    public static function assert(array $input, array $current): void
    {
        if (! array_key_exists('default_currency', $input)) {
            return;
        }

        $incoming = self::code($input['default_currency']);
        $existing = self::code($current['default_currency'] ?? '');

        // An empty value is not a currency, and resending the same one is not a
        // change: a screen that saves the whole group must stay able to save it.
        if ($incoming === '' || $incoming === $existing) {
            return;
        }

        $taken = self::liveDonations();
        if ($taken === 0) {
            return;
        }

        throw new BaseCurrencyLocked(esc_html($existing), esc_html($incoming), (int) $taken);
    }

    /**
     * A currency code, or '' for anything that is not one. SettingsService
     * rejects a non-scalar against a string default, so it never reaches the
     * store; casting one here to compare it would only warn and then compare
     * the word "Array".
     *
     * @since 1.0.0
     */
    private static function code(mixed $value): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        return strtoupper(trim((string) $value));
    }
}
