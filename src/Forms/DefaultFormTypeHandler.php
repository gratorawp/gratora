<?php

declare(strict_types=1);

namespace Dono\Forms;

use Dono\Donations\Donation;
use Dono\Donations\DonationIntent;

/**
 * Pass-through handler for the standard donation form type.
 *
 * @since 1.0.0
 */
final class DefaultFormTypeHandler implements FormTypeHandler
{
    /** @since 1.0.0 */
    public function type(): string
    {
        return 'donation';
    }

    /** @since 1.0.0 */
    public function label(): string
    {
        return __('Donation', 'dono-fundraising-platform');
    }

    /**
     * Returns the intent unchanged.
     *
     * @since 1.0.0
     */
    public function prepareIntent(DonationIntent $intent, array $body): DonationIntent
    {
        return $intent;
    }

    /**
     * No post-creation side effects.
     *
     * @since 1.0.0
     */
    public function onDonationCreated(Donation $donation, array $body): void
    {
    }

    /**
     * No sidecar model.
     *
     * @since 1.0.0
     */
    public function sidecarModel(): ?string
    {
        return null;
    }
}
