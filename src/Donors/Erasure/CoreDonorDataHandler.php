<?php

declare(strict_types=1);

namespace Dono\Donors\Erasure;

use Dono\Donations\Donation;
use Dono\Donations\DonationNote;
use Dono\Donors\Consent;
use Dono\Donors\DonorNote;
use Dono\Donors\MagicLinkToken;
use Dono\Donors\PendingSignup;
use Dono\Donors\PendingSignupRepository;
use Dono\Recurring\RecurringPlan;
use Dono\Donations\Refund;

/**
 * Core's own share of an erasure: everything hanging off the donor by foreign
 * key. Financial records survive; the PII on them does not. Core goes through
 * the registry like any add-on, so there is one place erasure happens.
 *
 * @since 1.0.0
 */
final class CoreDonorDataHandler implements ErasureHandler
{
    /** @since 1.0.0 */
    public function key(): string
    {
        return 'dono.core';
    }

    /** @since 1.0.0 */
    public function erase(ErasureRequest $request): void
    {
        foreach (Donation::query()->where('donor_id', $request->donorId)->getAll() as $donation) {
            $attribution = self::channelOnly($donation->source_attribution);

            // Every field cleared below must be listed here, or the skip
            // silently protects it.
            if ($donation->custom_data_encrypted === null
                && $donation->donor_first_name === null
                && $donation->donor_last_name === null
                && ($donation->note_to_org ?? '') === ''
                && $donation->gateway_metadata === null
                && $attribution === $donation->source_attribution
                && $donation->failure_reason === null
            ) {
                continue;
            }
            $donation->custom_data_encrypted = null;
            $donation->donor_first_name      = null;
            $donation->donor_last_name       = null;
            // Donor-authored message can carry PII.
            $donation->note_to_org           = null;
            // The gateway's own record of the payer: PayPal payer_email,
            // Stripe billing_details, card last-4, all cleartext.
            $donation->gateway_metadata      = null;
            $donation->source_attribution    = $attribution;
            // Raw gateway error text, which quotes back what was submitted.
            $donation->failure_reason        = null;
            $donation->updated_at            = $request->at;
            $donation->save();
        }

        if ($request->donationIds !== []) {
            // Staff notes on a donation are the same free-text PII as notes on
            // the donor.
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

        // An unproven signup has no donor id to be found by, so it is reached
        // by hash or not at all. Left behind, its link stays live and would
        // rebuild the donor on redemption, and the link carries the name the
        // signup typed.
        if ($request->emailHash !== '') {
            foreach (PendingSignup::query()->where('email_hash', $request->emailHash)->getAll() as $claim) {
                PendingSignupRepository::deleteSignupTokensFor((int) $claim->id);
            }
            PendingSignup::query()->where('email_hash', $request->emailHash)->delete();
        }

        // Staff notes about the donor are free-text PII and in DSAR export
        // scope.
        DonorNote::query()->where('donor_id', $request->donorId)->delete();

        // The consent fact and its timestamp are the lawful-basis evidence for
        // everything sent before the erasure, so the row stays. The hashes are
        // not: ip_hash is a salted digest over a space small enough to
        // enumerate, user_agent_hash is unsalted and so stable across
        // installs, and both re-link an erased row to the person.
        Consent::query()
            ->where('donor_id', $request->donorId)
            ->update(['ip_hash' => null, 'user_agent_hash' => null]);
    }

    /**
     * What the channel rollups actually read is utm_source and utm_medium, so
     * the donation keeps the channel it is counted under. `landing` is the URL
     * the donor arrived on verbatim, and a link mailed through an ESP carries
     * a per-recipient identifier in it that names the person; `referrer` is the
     * page that sent them.
     *
     * @param  array<string,mixed>|null $attribution
     * @return array<string,mixed>|null
     *
     * @since 1.0.0
     */
    private static function channelOnly(?array $attribution): ?array
    {
        if ($attribution === null) {
            return null;
        }

        $kept = [];
        foreach ($attribution as $key => $value) {
            if (str_starts_with((string) $key, 'utm_')) {
                $kept[(string) $key] = $value;
            }
        }

        return $kept === [] ? null : $kept;
    }
}
