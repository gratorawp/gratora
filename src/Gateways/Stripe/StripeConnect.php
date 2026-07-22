<?php

declare(strict_types=1);

namespace Dono\Gateways\Stripe;

/**
 * Connect platform constants.
 *
 * @version 1.0.0
 */
final class StripeConnect
{
    public const BROKER_URL = 'https://connect.getdono.com';

    /**
     * Hosted Connect broker base URL (no trailing slash). Production uses the
     * default; a staging/dev site can point at a test broker via the
     * DONO_CONNECT_BROKER_URL constant or the dono.stripe.broker_url filter,
     * without editing the shipped default.
     */
    public static function brokerUrl(): string
    {
        if (defined('DONO_CONNECT_BROKER_URL') && is_string(DONO_CONNECT_BROKER_URL) && DONO_CONNECT_BROKER_URL !== '') {
            return rtrim((string) DONO_CONNECT_BROKER_URL, '/');
        }
        return rtrim((string) apply_filters('dono.stripe.broker_url', self::BROKER_URL), '/');
    }
}
