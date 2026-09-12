<?php

declare(strict_types=1);

namespace Gratora\Gateways;

use Gratora\Donations\Donation;

defined('ABSPATH') || exit;

/**
 * A gateway that can be asked to close the payment behind an unsettled row.
 *
 * Deliberately separate from SettlesOutOfBand, which is the other way a row
 * becomes eligible: that marker says there was never anything open to close,
 * while this says there was and the gateway has been asked about it. A gateway
 * implementing neither leaves its rows on the age fallback.
 *
 * The question is asked before a row is trashed or deleted, so the answer
 * decides whether an admin may take a spam attempt off their list. Answering
 * optimistically is the expensive mistake: a row closed on a guess is a card
 * that can still be charged against a donation nobody is watching.
 *
 * @since 1.0.0
 */
interface ClosesUnsettledPayment
{
    /**
     * Close whatever the gateway is still holding open for this donation.
     *
     * Implementations do not write donation state. The caller owns the row and
     * writes the stop record inside its own locked transaction; a gateway that
     * wrote here would do so outside that lock and outside its rollback.
     *
     * Never throws for a gateway outcome: an unreachable host and a refusal are
     * different answers the caller has to tell apart, and an exception collapses
     * them back into one.
     *
     * @since 1.0.0
     */
    public function closeUnsettled(Donation $donation): CloseUnsettledResult;
}
