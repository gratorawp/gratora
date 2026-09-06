<?php

declare(strict_types=1);

namespace FundKit\Gateways;

/**
 * A gateway holding separate credentials per mode.
 *
 * The mode a donation runs in is its form's, not the site's: a form can opt
 * into test mode while the org switch is off. A picker that asks the site
 * offers a gateway whose keys are for the other mode, and the donor finds out
 * at the payment step, on every donation, with nothing on any screen saying so.
 *
 * Answered per mode rather than by putting the gateway into one: the picker is
 * asking a question, and a question must not leave the account pointing
 * somewhere the next caller did not choose.
 *
 * @since 1.0.0
 */
interface ModeCredentialed
{
    /** Whether this gateway can take a donation running in $test. */
    public function chargesInMode(bool $test): bool;

    /**
     * The frequencies it supports in $test. Recurring can need more than keys:
     * PayPal needs a webhook registered for that mode, and without one the
     * opening sale event never arrives and the donation never leaves pending.
     *
     * @return list<string>
     */
    public function frequenciesInMode(bool $test): array;
}
