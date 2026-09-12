<?php

declare(strict_types=1);

namespace Gratora\Foundation\Upgrade;

/**
 * Take the gateway config out of the autoloaded set.
 *
 * The Stripe webhook provisioner was the first writer of the option on an install
 * where the admin never opened the gateways settings group, and it wrote without
 * an autoload flag. The plaintext signing secret then sat in the alloptions blob:
 * unserialised on every unauthenticated front-end request, cached under the
 * shared key, and readable by anything that calls wp_load_alloptions. It is the
 * only authentication on the public webhook route.
 *
 * @since 1.0.0
 */
final class UnautoloadGatewayConfig implements UpgradeRoutine
{
    private const OPTION = 'gratora_gateway_config';

    /** @since 1.0.0 */
    public function id(): string
    {
        return '2026-09-08-unautoload-gateway-config';
    }

    /** @since 1.0.0 */
    public function description(): string
    {
        return __('Taking the payment gateway credentials out of the options loaded on every page view.', 'gratora-donation-platform');
    }

    /** @since 1.0.0 */
    public function step(): bool
    {
        if (get_option(self::OPTION, null) === null) {
            return true;
        }

        // Not update_option: it short-circuits on an unchanged value, and the
        // value is not what is wrong here.
        wp_set_option_autoload(self::OPTION, false);

        return true;
    }
}
