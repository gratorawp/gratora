<?php

declare(strict_types=1);

namespace Dono\Gateways;

/**
 * Identifies the account a cached remote object was created under.
 *
 * Stripe Products and PayPal Products and Plans live inside one merchant
 * account and mean nothing in another. Folding the account into the cache key
 * means a rotation simply misses and provisions fresh objects: rotating back
 * finds the originals again, and entries belonging to an account no longer in
 * use are inert rather than wrong.
 *
 * Rotating only the secret on an otherwise unchanged account also misses,
 * orphaning one unused remote object. That is the cheap direction of the
 * trade: an unused Product costs nothing, a stale one costs every recurring
 * donation the org has.
 *
 * @version 1.0.0
 */
final class AccountFingerprint
{
    /**
     * Short and non-reversible: this ends up inside an option key, which is not
     * a place to put a credential.
     */
    public static function of(string $credential): string
    {
        $credential = trim($credential);

        return $credential === '' ? 'none' : substr(hash('sha256', $credential), 0, 12);
    }
}
