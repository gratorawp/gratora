<?php

declare(strict_types=1);

namespace FundKit\Gateways;

/**
 * A gateway whose recurring plan can be paused and resumed.
 *
 * Separate from SubscriptionAware for the reason SupportsPaymentMethodUpdate
 * gives about itself: handling subscriptions says nothing about being able to
 * suspend one. Two shipped gateways declare SubscriptionAware and refuse both
 * pause and skip outright, because their rails have no such operation.
 * GoCardless is a Direct Debit mandate, and stopping it means cancelling and
 * asking the donor to sign a new one, which is not what they asked for.
 *
 * Core read SubscriptionAware as "this plan can be paused", so the portal
 * offered Pause and Skip next charge on every plan and a Direct Debit donor
 * who pressed either got a raw 422. The sharpest version is the cancel
 * deflection sheet, which offers exactly those two as the alternatives to
 * cancelling: a donor trying not to cancel was handed two buttons that both
 * failed, and then cancelled.
 *
 * A marker, like its siblings: the methods themselves live on SubscriptionAware
 * because a gateway that cannot pause still has to answer the call, and does so
 * by refusing.
 *
 * @since 1.0.0
 */
interface SupportsSubscriptionPause
{
}
