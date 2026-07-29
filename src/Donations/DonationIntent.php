<?php

declare(strict_types=1);

namespace Dono\Donations;

/**
 * Immutable input to DonationService::createPending(): everything captured from a form
 * or API submission, nothing derived (reference, donor_id, status, paid_at are the
 * service's job; donor find-or-create happens there too).
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
        public readonly bool $note_public = false,
        public readonly bool $is_anonymous = false,
        public readonly ?string $country = null,
        public readonly int $fee_covered_cents = 0,
        /** @var array<string,mixed> generic handler bag, e.g. form_type, fundraiser_id */
        public readonly array $extra = [],
        /** @var array<string,mixed> donor-submitted custom form-field values */
        public readonly array $custom = [],
        /** 'donation', or a non-donation kind (e.g. 'order') a handler stamps. */
        public readonly string $kind = 'donation',
        /**
         * Overrides the org's test-mode switch. Null means "ask TestMode",
         * which is what every donor-facing path wants. An admin recording
         * money that already arrived sets false: a real cheque is real money
         * even on a site that happens to be rehearsing, and the answer has to
         * be settled before the row is written, because listeners on
         * dono.donation.creating decide things from it that are never revisited.
         */
        public readonly ?bool $is_test = null,
        /**
         * Whether money from an erased donor un-erases them, reuniting it with
         * their giving history. A donation someone made themselves is exactly
         * the re-engagement the retention window exists for, and does. A cheque
         * an admin types in is not: someone exercised their right to erasure,
         * and a third party entering their email address is not them asking to
         * come back.
         */
        public readonly bool $reactivate_redacted_donor = true,
    ) {
    }
}
