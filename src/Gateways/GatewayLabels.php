<?php

declare(strict_types=1);

namespace Gratora\Gateways;

/**
 * What a gateway is called on an admin screen.
 *
 * A slug is not a name. Capitalising one gives "Paypal" to somebody who has
 * only ever seen PayPal, and "Authorize_net" to somebody who has only ever
 * seen Authorize.Net, so every surface that shows a gateway asks here instead
 * of spelling it itself.
 *
 * @since 1.0.0
 */
final class GatewayLabels
{
    /**
     * Core names what it ships plus the slugs the Give importer writes;
     * add-ons name their own through the filter; an unnamed slug still gets
     * something readable rather than being dropped.
     *
     * @since 1.0.0
     */
    public static function for(string $slug): string
    {
        $slug = trim($slug);
        if ($slug === '') {
            return '';
        }

        $known = [
            'stripe'  => __('Stripe', 'gratora-donation-platform'),
            'paypal'  => __('PayPal', 'gratora-donation-platform'),
            'offline' => __('Offline', 'gratora-donation-platform'),
            'sandbox' => __('Test donation', 'gratora-donation-platform'),
            'manual'  => __('Manually entered', 'gratora-donation-platform'),
        ];

        if (isset($known[$slug])) {
            return $known[$slug];
        }

        /**
         * Admin-facing names for gateways core does not ship.
         *
         * @param array<string,string> $labels Keyed by gateway slug.
         *
         * @since 1.0.0
         */
        $added = (array) apply_filters('gratora.gateway_admin_labels', []);
        $label = $added[$slug] ?? null;

        return is_string($label) && $label !== ''
            ? $label
            : ucwords(str_replace(['-', '_'], ' ', $slug));
    }
}
