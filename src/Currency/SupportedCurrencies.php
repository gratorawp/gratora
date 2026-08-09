<?php

declare(strict_types=1);

namespace Dono\Currency;

use Dono\Foundation\Helpers\Money;
use Dono\Settings\SettingsService;

/**
 * The currencies an organization accepts.
 *
 * The public donation route has always checked this. The admin's own
 * "record a donation" form did not: it validated the code as three letters and
 * took whatever it was given, so an offline gift could be recorded in a
 * currency the site has no rate for, which lands it outside every total with
 * nothing saying so. A currency is not a free text field in one place and a
 * closed list in another.
 *
 * Gateways declare currencies() too, but that answers a different question:
 * which gateway to offer a donor. Offline and Stripe both return the wildcard,
 * so it is no help as a gate. This is the gate.
 *
 * @version 1.0.0
 */
final class SupportedCurrencies
{
    /**
     * Is the currency in the org's accepted list?
     *
     * An empty or absent list means unconfigured: accept any valid code rather
     * than reject everything. The base currency is always accepted, even when
     * nobody added it to the list explicitly.
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
     * @return array<int,string> uppercased, possibly empty when unconfigured
     *
     * Through the settings service, not get_option(). The option is written
     * only when someone saves the Currency screen, and the service merges the
     * ['USD'] default for a site that never has. Reading the raw option turned
     * an untouched site from USD-only into one accepting any three letters,
     * which is exactly the unreportable row this class exists to refuse.
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
