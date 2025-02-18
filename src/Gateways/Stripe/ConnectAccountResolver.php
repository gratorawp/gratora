<?php

declare(strict_types=1);

namespace Dono\Gateways\Stripe;

/**
 * Resolves which connected account a donation settles to. The default
 * implementation returns the single connected account; swap via the
 * dono.stripe.account_resolver filter for multi-account routing.
 *
 * @version 1.0.0
 */
interface ConnectAccountResolver
{
    /** Resolve the connected account id for a given campaign/form context. */
    public function resolve(?int $campaignId, ?int $formId): ?string;
}
