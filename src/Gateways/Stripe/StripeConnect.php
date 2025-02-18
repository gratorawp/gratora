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

    /** Returns the hosted Connect broker URL. */
    public static function brokerUrl(): string
    {
        return self::BROKER_URL;
    }
}
