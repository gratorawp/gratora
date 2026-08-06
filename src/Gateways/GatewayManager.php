<?php

declare(strict_types=1);

namespace Dono\Gateways;

use RuntimeException;

/**
 * Registry of PaymentGateway implementations.
 *
 * @version 1.0.0
 */
final class GatewayManager
{
    /** @var array<string, PaymentGateway> */
    private array $gateways = [];

    /** Register a gateway; throws if its id is already taken. */
    public function register(PaymentGateway $gateway): void
    {
        $id = $gateway->id();
        if (isset($this->gateways[$id])) {
            throw new RuntimeException("Gateway '{$id}' is already registered.");
        }
        $this->gateways[$id] = $gateway;
    }

    /** Return the gateway for the given id, or null if not registered. */
    public function get(string $id): ?PaymentGateway
    {
        return $this->gateways[$id] ?? null;
    }

    /** Return the gateway for the given id; throws if not registered. */
    public function require(string $id): PaymentGateway
    {
        $g = $this->get($id);
        if (! $g) {
            throw new RuntimeException("Gateway '{$id}' is not registered.");
        }
        return $g;
    }

    /** @return array<string, PaymentGateway> */
    public function all(): array
    {
        return $this->gateways;
    }

    /**
     * Ordered gateway ids available for a submission context. Single source of
     * truth for the form's default gateway and server-side submit validation.
     * Empty $allowed means no form restriction; order follows $allowed when set,
     * else registration order.
     *
     * @param  list<string> $allowed form.settings.gateways.allowed
     * @return list<string>
     */
    public function optionsFor(array $allowed, ?string $country, string $currency, string $frequency = 'one_time'): array
    {
        $cfg = get_option('dono_gateway_config', []);
        $cfg = is_array($cfg) ? $cfg : [];

        $enabled = [];
        foreach ($this->availableFor($country, $currency, $frequency) as $id => $_g) {
            if (($cfg[$id]['enabled'] ?? true)) {
                $enabled[] = $id;
            }
        }

        $allowed = array_values(array_filter(array_map('strval', $allowed), static fn ($s) => $s !== ''));
        if ($allowed === []) {
            return $enabled;
        }

        return array_values(array_filter($allowed, static fn ($id) => in_array($id, $enabled, true)));
    }

    /**
     * Is this gateway on for donors?
     *
     * The single definition. Readiness used to ask its own version -- a Stripe
     * special case plus `! empty($cfg[$id]['enabled'])`, which treats an absent
     * key as off where this treats it as on -- so an admin could be told a form
     * was ready while the donor was offered nothing.
     *
     * A credentialed gateway has no enable toggle by design: connecting keys is
     * the switch, and canCharge() is what that amounts to. The `enabled` flag
     * belongs to gateways configured entirely in settings, like offline.
     */
    public function isOn(string $id): bool
    {
        $g = $this->gateways[$id] ?? null;
        if (! $g) {
            return false;
        }

        $cfg = get_option('dono_gateway_config', []);
        $cfg = is_array($cfg) ? $cfg : [];

        return ($cfg[$id]['enabled'] ?? true) && $g->canCharge();
    }

    /**
     * Full gateway metadata for a form, not context-filtered, so the donor
     * runtime can re-resolve visible options as currency or frequency changes
     * client-side without a round trip.
     *
     * @param  list<string> $allowed
     * @return list<array{id:string,label:string,description:string,currencies:list<string>,countries:list<string>,frequencies:list<string>}>
     */
    public function optionsMetaFor(array $allowed): array
    {
        $enabledIds = [];
        foreach ($this->gateways as $id => $_g) {
            if ($this->isOn($id)) {
                $enabledIds[] = $id;
            }
        }

        $allowed = array_values(array_filter(array_map('strval', $allowed), static fn ($s) => $s !== ''));
        $orderedIds = $allowed === []
            ? $enabledIds
            : array_values(array_filter($allowed, static fn ($id) => in_array($id, $enabledIds, true)));

        $out = [];
        foreach ($orderedIds as $id) {
            $g = $this->gateways[$id];
            $out[] = [
                'id'          => $id,
                'label'       => $g->label(),
                'description' => $g->description(),
                'currencies'  => array_values(array_map('strtoupper', $g->currencies())),
                'countries'   => array_values(array_map('strtoupper', $g->countries())),
                'frequencies' => array_values($g->frequencies()),
            ];
        }
        return $out;
    }

    /** @return array<string, PaymentGateway> */
    /**
     * Can this gateway take this currency?
     *
     * availableFor() applies the same rule when deciding what to offer a donor,
     * but the create path only checked that the gateway existed, so a crafted
     * payload could name one that cannot take the currency and fail later at
     * the gateway instead of here.
     *
     * A wildcard means the gateway does its own validation, which is the right
     * answer for Stripe (its list runs to well over a hundred currencies and
     * moves) and for Offline (a cheque can be written in anything). Hardcoding
     * either would be a list that goes stale and starts refusing real money.
     */
    public function acceptsCurrency(string $gatewayId, string $currency): bool
    {
        $gateway = $this->get($gatewayId);
        if ($gateway === null) {
            return false;
        }

        $currencies = array_map('strtoupper', $gateway->currencies());

        return in_array('*', $currencies, true)
            || in_array(strtoupper($currency), $currencies, true);
    }

    public function availableFor(?string $country, string $currency, string $frequency = 'one_time'): array
    {
        $currency = strtoupper($currency);
        $country  = $country !== null ? strtoupper(substr($country, 0, 2)) : null;

        // Any non-one_time frequency maps to 'recurring' for the support check.
        $bucket = $frequency === 'one_time' ? 'one_time' : 'recurring';

        $out = [];
        foreach ($this->gateways as $id => $g) {
            // A connected-but-not-yet-chargeable gateway (Stripe mid-onboarding)
            // must not be offered; the donor would only fail at createIntent.
            if (! $g->canCharge()) {
                continue;
            }

            $currencies = array_map('strtoupper', $g->currencies());
            if (! in_array('*', $currencies, true) && ! in_array($currency, $currencies, true)) {
                continue;
            }

            if ($country !== null) {
                $countries = array_map('strtoupper', $g->countries());
                if (! in_array('*', $countries, true) && ! in_array($country, $countries, true)) {
                    continue;
                }
            }

            if (! in_array($bucket, $g->frequencies(), true)) {
                continue;
            }

            $out[$id] = $g;
        }
        return $out;
    }
}
