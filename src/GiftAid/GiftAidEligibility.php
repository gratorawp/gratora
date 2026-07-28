<?php

declare(strict_types=1);

namespace Dono\GiftAid;

use Dono\Donations\Donation;
use Dono\Donors\Donor;

/**
 * The single answer to "can this donation be claimed for Gift Aid".
 *
 * One owner on purpose. Every surface that asks (the create path stamping the
 * donation, the admin list, the HMRC export) has to give the same answer, and
 * this is a claim to a tax authority: a rule that drifted between the screen an
 * operator reads and the file they submit would put a wrong claim in front of
 * HMRC under the charity's name.
 *
 * @version 1.0.0
 */
final class GiftAidEligibility
{
    /**
     * Gift Aid is relief on UK income tax, so the gift is in sterling. Kept as
     * a constant rather than inline so the reason travels with the rule.
     */
    public const CURRENCY = 'GBP';

    public function __construct(private GiftAidDeclarations $declarations)
    {
    }

    public function enabled(): bool
    {
        $opt = get_option('dono_gift_aid', []);

        return is_array($opt) && ! empty($opt['enabled']);
    }

    /**
     * Whether a donation being created should be stamped claimable.
     *
     * `$declaredNow` is the box the donor ticked on this form, which counts
     * even before the declaration row exists.
     */
    public function qualifies(Donation $donation, ?Donor $donor, bool $declaredNow = false): bool
    {
        if (! $this->enabled()) return false;

        // A test-mode donation is not money, so it is not a claim.
        if ($donation->is_test) return false;

        if ((int) $donation->amount_cents <= 0) return false;
        if (strtoupper((string) $donation->currency) !== self::CURRENCY) return false;

        // Only an individual can make a Gift Aid declaration: a company gets
        // corporation tax relief instead, and household is a shared record.
        if (! $donor instanceof Donor) return false;
        if ($donor->donor_type !== 'individual') return false;

        // A ticket is a purchase, not a gift.
        if ($donation->kind !== 'donation') return false;

        $declared = $declaredNow || $this->declarations->coversFutureGifts((int) $donor->id);
        if (! $declared) return false;

        return (bool) apply_filters('dono.gift_aid.qualifies', true, $donation, $donor);
    }
}
