<?php

declare(strict_types=1);

namespace Dono\Forms;

use Dono\Donations\Donation;
use Dono\Donations\DonationIntent;

/**
 * Pass-through handler for the standard donation form type.
 *
 * @version 1.0.0
 */
final class DefaultFormTypeHandler implements FormTypeHandler
{
    /** Form type identifier. */
    public function type(): string
    {
        return 'donation';
    }

    /** Human-facing type name. */
    public function label(): string
    {
        return __('Donation', 'dono');
    }

    /** Returns the intent unchanged. */
    public function prepareIntent(DonationIntent $intent, array $body): DonationIntent
    {
        return $intent;
    }

    /** No post-creation side effects. */
    public function onDonationCreated(Donation $donation, array $body): void
    {
    }

    /** No sidecar model. */
    public function sidecarModel(): ?string
    {
        return null;
    }
}
