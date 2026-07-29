<?php

declare(strict_types=1);

namespace Dono\Donors\Erasure;

use Dono\Donations\Donation;
use Dono\Donations\DonationNote;
use Dono\Donors\DonorNote;
use Dono\Donors\MagicLinkToken;
use Dono\Recurring\RecurringPlan;
use Dono\Donations\Refund;

/**
 * Core's own share of an erasure: everything hanging off the donor by foreign
 * key. Financial records survive; the PII on them does not.
 *
 * Core goes through the registry like any add-on rather than keeping its own
 * inline copy, so there is one place erasure happens and one order it happens
 * in.
 *
 * @version 1.0.0
 */
final class CoreDonorDataHandler implements ErasureHandler
{
    public function key(): string
    {
        return 'dono.core';
    }

    public function erase(ErasureRequest $request): void
    {
        foreach (Donation::query()->where('donor_id', $request->donorId)->getAll() as $donation) {
            // Every field cleared below must be listed here, or the skip
            // silently protects it. Adding gateway_metadata without adding
            // it to this condition left it untouched on exactly the rows
            // that had nothing else to clear.
            if ($donation->custom_data_encrypted === null
                && $donation->donor_first_name === null
                && $donation->donor_last_name === null
                && ($donation->note_to_org ?? '') === ''
                && $donation->gateway_metadata === null
            ) {
                continue;
            }
            $donation->custom_data_encrypted = null;
            $donation->donor_first_name      = null;
            $donation->donor_last_name       = null;
            // Donor-authored message can carry PII; clear it on erasure.
            $donation->note_to_org           = null;
            // The gateway's own record of the payer: PayPal payer_email,
            // Stripe billing_details, card last-4. Cleartext, and the QA
            // sweep found it surviving erasure.
            $donation->gateway_metadata      = null;
            $donation->updated_at            = $request->at;
            $donation->save();
        }

        if ($request->donationIds !== []) {
            // Staff notes written against a donation, as opposed to against
            // the donor, are the same free-text PII and were being missed.
            DonationNote::query()->whereIn('donation_id', $request->donationIds)->delete();

            // A refund reason is admin free text and its metadata carries
            // the gateway's payer details.
            Refund::query()
                ->whereIn('donation_id', $request->donationIds)
                ->update(['reason' => null, 'metadata' => null]);
        }

        // The gateway's customer id is a stable handle back to the donor on
        // the processor's side, so it is re-identifying data.
        RecurringPlan::query()
            ->where('donor_id', $request->donorId)
            ->update(['gateway_customer_id' => null]);

        // Revoke outstanding magic-link tokens so a previously-emailed
        // portal link can no longer open a session for the redacted donor.
        MagicLinkToken::query()->where('donor_id', $request->donorId)->delete();

        // Staff notes about the donor are free-text PII and in DSAR export
        // scope (DonorMetricsService::exportData), so erasure removes them.
        DonorNote::query()->where('donor_id', $request->donorId)->delete();
    }
}
