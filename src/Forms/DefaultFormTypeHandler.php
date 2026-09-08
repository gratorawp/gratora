<?php

declare(strict_types=1);

namespace FundKit\Forms;

use FundKit\Donations\Donation;
use FundKit\Donations\DonationIntent;

/** @since 1.0.0 */
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
        return __('Donation', 'fundraising-toolkit');
    }

    /** @since 1.0.0 */
    public function prepareIntent(DonationIntent $intent, array $body): DonationIntent
    {
        return $intent;
    }

    /** @since 1.0.0 */
    public function onDonationCreated(Donation $donation, array $body): void
    {
    }

    /** @since 1.0.0 */
    public function sidecarModel(): ?string
    {
        return null;
    }
}
