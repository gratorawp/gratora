<?php

declare(strict_types=1);

namespace Dono\Receipts;

use Dono\Campaigns\Campaign;
use Dono\Donations\Donation;
use Dono\Donors\Donor;

/**
 * Data bundle passed to a ReceiptRenderer.
 *
 * Modules extend context via `dono.receipt.context`. Keys in `extras` must
 * be namespaced by the module to avoid collisions.
 *
 * @version 1.0.0
 */
final class ReceiptContext
{
    public function __construct(
        public readonly Donation $donation,
        public readonly Donor $donor,
        /** Donor's preferred locale at receipt-issue time. */
        public readonly string $locale,
        /** Organisation profile (legal name, address, tax id, logo). */
        public readonly array $org,
        /** Decrypted donor email, populated by ReceiptIssuer for renderer use. */
        public readonly ?string $donor_email = null,
        /** Decrypted donor address, populated by ReceiptIssuer for renderer use. */
        public readonly ?string $donor_address = null,
        /**
         * Display name for this receipt: the name given for the donation,
         * falling back to the donor record. Populated by ReceiptIssuer.
         */
        public readonly ?string $donor_name = null,
        /**
         * Campaign the donation belongs to. Populated by ReceiptIssuer when
         * the donation has a campaign_id; null otherwise. Used by merge tags
         * like {campaign_title} and the receipt PDF context.
         */
        public readonly ?Campaign $campaign = null,
        /** Module-injected context. */
        public readonly array $extras = [],
    ) {
    }

    public function with(string $key, mixed $value): self
    {
        return new self(
            $this->donation,
            $this->donor,
            $this->locale,
            $this->org,
            $this->donor_email,
            $this->donor_address,
            $this->donor_name,
            $this->campaign,
            [...$this->extras, $key => $value],
        );
    }
}
