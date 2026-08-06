<?php

declare(strict_types=1);

namespace Dono\Gateways;

/**
 * How a donor is to be taken through changing the card on a plan.
 *
 * The two processors do not agree on the shape of this. Stripe hands back a
 * SetupIntent the donor's browser confirms in place, so the card never touches
 * the site. PayPal will not let anyone else collect a funding source at all:
 * the subscriber has to approve the change on PayPal's own pages, so the only
 * honest answer is a link to send them to.
 *
 * @version 1.0.0
 */
final class PaymentMethodUpdate
{
    /** Confirmed in the donor's browser against the processor. */
    public const INLINE = 'inline';

    /** The donor finishes on the processor's own site. */
    public const REDIRECT = 'redirect';

    private function __construct(
        public readonly string $mode,
        public readonly ?string $clientSecret = null,
        public readonly ?string $publishableKey = null,
        public readonly ?string $redirectUrl = null,
    ) {
    }

    public static function inline(string $clientSecret, string $publishableKey): self
    {
        return new self(self::INLINE, $clientSecret, $publishableKey);
    }

    public static function redirect(string $url): self
    {
        return new self(self::REDIRECT, null, null, $url);
    }

    /** @return array<string,mixed> The portal's view of it; never carries a secret it does not need. */
    public function toArray(): array
    {
        return $this->mode === self::INLINE
            ? ['mode' => $this->mode, 'client_secret' => $this->clientSecret, 'publishable_key' => $this->publishableKey]
            : ['mode' => $this->mode, 'redirect_url' => $this->redirectUrl];
    }
}
