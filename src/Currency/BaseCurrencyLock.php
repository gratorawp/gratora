<?php

declare(strict_types=1);

namespace Dono\Currency;

use Dono\Donations\Donation;
use Dono\Donations\DonationQueries;

/**
 * The base currency is the unit every stored base_amount_cents is already
 * denominated in, and nothing restates them. Changing it after money has come
 * in silently reinterprets every historical total and every report at the new
 * currency's face value, and every donation taken afterwards is stamped
 * against the new base, so the ledger mixes two units with no column saying
 * which is which.
 *
 * Refused rather than rebased: rebasing needs a rate per donation as of the day
 * it was taken, which an install that has not been reporting in the new
 * currency does not have.
 *
 * Lives beside the settings write rather than in one controller, so every
 * writer inherits it: the settings REST route, the settings.update command, the
 * CLI, and anything an add-on registers.
 *
 * @since 1.0.0
 */
final class BaseCurrencyLock
{
    /**
     * Live rows already denominated in the base. Test-mode rows are not money
     * and never lock anything.
     *
     * live() rather than donationsOnly(): a ticket order is a purchase and
     * stays out of donation reporting, but it carries a base_amount_cents in
     * this currency like anything else, and rereading it as a different one is
     * the harm being refused.
     *
     * @since 1.0.0
     */
    public static function liveDonations(): int
    {
        return (int) DonationQueries::live(Donation::query())->count();
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
