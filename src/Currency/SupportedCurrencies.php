<?php

declare(strict_types=1);

namespace Dono\Currency;

use Dono\Foundation\Helpers\Money;
use Dono\Settings\SettingsService;

/**
 * The currencies an organization accepts.
 *
 * Every entry point goes through this gate, public route and admin
 * "record a donation" alike. A currency is not a free text field in one place
 * and a closed list in another: a code with no rate lands the donation outside
 * every total with nothing saying so.
 *
 * Gateways declare currencies() too, but that answers a different question:
 * which gateway to offer a donor. Offline and Stripe both return the wildcard,
 * so it is no help as a gate. This is the gate.
 *
 * @since 1.0.0
 */
final class SupportedCurrencies
{
    /**
     * An empty or absent list means unconfigured: accept any valid code rather
     * than reject everything. The base currency is always accepted, even when
     * nobody added it to the list explicitly.
     *
     * @since 1.0.0
     */
    public static function accepts(string $currency): bool
    {
        $currency = strtoupper(trim($currency));
        if ($currency === '') {
            return false;
        }

        $list = self::all();

        return $list === []
            || $currency === strtoupper(Money::defaultCurrency())
            || in_array($currency, $list, true);
    }

    /**
     * Through the settings service, not get_option(). The option is written
     * only when someone saves the Currency screen, and the service merges the
     * ['USD'] default for a site that never has. The raw option would read as
     * unconfigured on an untouched site, which accepts any three letters.
     *
     * @return array<int,string> uppercased, possibly empty when unconfigured
     *
     * @since 1.0.0
     */
    public static function all(): array
    {
        $cfg  = (new SettingsService())->get('currency-locale');
        $list = is_array($cfg['supported_currencies'] ?? null)
            ? $cfg['supported_currencies']
            : [];

        return array_values(array_map(
            static fn ($c): string => strtoupper((string) $c),
            $list
        ));
    }
}
