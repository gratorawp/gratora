<?php

declare(strict_types=1);

namespace Dono\Forms;

use Dono\Donations\Donation;
use Dono\Donations\DonationIntent;

/**
 * Behaviour a non-default form_type plugs in.
 *
 * prepareIntent must return a new DonationIntent (it is readonly), not mutate
 * the argument.
 *
 * @since 1.0.0
 */
interface FormTypeHandler
{
    /** @since 1.0.0 */
    public function type(): string;

    /** @since 1.0.0 */
    public function label(): string;

    /**
     * @param array<string,mixed> $body the intent's extra bag
     *
     * @since 1.0.0
     */
    public function prepareIntent(DonationIntent $intent, array $body): DonationIntent;

    /**
     * @param array<string,mixed> $body the intent's extra bag
     *
     * @since 1.0.0
     */
    public function onDonationCreated(Donation $donation, array $body): void;

    /**
     * @return class-string<\Dono\Vendor\Queryable\Model>|null sidecar PK = parent id, or null
     *
     * @since 1.0.0
     */
    public function sidecarModel(): ?string;
}
