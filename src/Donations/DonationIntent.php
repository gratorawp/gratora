<?php

declare(strict_types=1);

namespace Dono\Donations;

/**
 * Immutable input to `DonationService::createPending()`.
 *
 * Carries everything captured from a donation form or API submission, but
 * nothing derived (reference, donor_id, status, paid_at are the service's
 * job). Donor profile is part of the intent; the service does the
 * find-or-create via DonorService.
 *
 * @version 1.0.0
 *
 * @phpstan-type Profile array{
 *     first_name?: ?string,
 *     last_name?: ?string,
 *     country?: ?string,
 *     locale?: ?string,
 *     company?: ?string,
 *     donor_type?: 'individual'|'organization'|'household',
 * }
 */
final class DonationIntent
{
    public function __construct(
        public readonly string $email,
        public readonly int $amount_cents,
        public readonly string $currency,
        public readonly string $gateway,
        public readonly string $frequency = 'one_time',
        public readonly ?int $form_id = null,
        public readonly ?int $campaign_id = null,
        public readonly ?int $fund_id = null,
        /** @var Profile */
        public readonly array $profile = [],
        public readonly ?string $payment_method = null,
        /** @var array<string,mixed>|null */
        public readonly ?array $source_attribution = null,
        public readonly ?string $locale = null,
        public readonly ?string $note_to_org = null,
        public readonly bool $is_anonymous = false,
        public readonly ?string $country = null,
        /** @var array{type:string,name:string,notify_email?:?string,message?:?string}|null */
        public readonly ?array $tribute = null,
        public readonly int $fee_covered_cents = 0,
        /** @var array<string,mixed> generic handler bag, e.g. form_type, fundraiser_id */
        public readonly array $extra = [],
        /** @var array<string,mixed> donor-submitted custom form-field values */
        public readonly array $custom = [],
    ) {
    }
}
