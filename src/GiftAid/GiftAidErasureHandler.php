<?php

declare(strict_types=1);

namespace Dono\GiftAid;

use Dono\Donations\Donation;
use Dono\Donors\Erasure\ErasureHandler;
use Dono\Donors\Erasure\ErasureRequest;

/**
 * Donor erasure, against a record the charity is legally required to keep.
 *
 * Every other handler in the registry clears what it holds. This one mostly
 * does not, and that is the correct answer rather than an oversight: a Gift Aid
 * claim is evidence for a reclaim of public money, and HMRC can ask to see it
 * for six years after the accounting period it falls in. Erasing it would leave
 * the charity unable to substantiate a claim it has already been paid for.
 *
 * The GDPR right to erasure does not override a legal obligation to retain, so
 * the snapshot survives its retention period and is cleared the moment that
 * period ends. What goes immediately is everything that is not evidence: the
 * declaration's own network fingerprints.
 *
 * The narrowness matters. This handler keeps a name, a house number and a
 * postcode against specific claimed gifts, and nothing else about the donor.
 *
 * @version 1.0.0
 */
final class GiftAidErasureHandler implements ErasureHandler
{
    public function __construct(private GiftAidClaims $claims)
    {
    }

    public function key(): string
    {
        return 'dono.gift_aid';
    }

    public function erase(ErasureRequest $request): void
    {
        // The IP and user-agent hashes prove nothing to HMRC: the declaration
        // itself, its wording and its date are the evidence. They go now.
        GiftAidDeclaration::query()
            ->where('donor_id', $request->donorId)
            ->update(['ip_hash' => null, 'user_agent_hash' => null]);

        if ($request->donationIds === []) return;

        $donations = Donation::query()
            ->whereIn('id', $request->donationIds)
            ->whereIsNotNull('gift_aid_claim_encrypted')
            ->getAll();

        foreach ($donations as $donation) {
            if (! $this->claims->retentionExpired($donation, $request->at)) {
                // Still inside the window HMRC can ask about.
                continue;
            }
            $donation->gift_aid_claim_encrypted = null;
            $donation->updated_at               = $request->at;
            $donation->save();
        }
    }
}
