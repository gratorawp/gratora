<?php

declare(strict_types=1);

namespace Dono\Gateways\Razorpay;

/**
 * Razorpay's HMAC-SHA256 signatures. Three of them, all keyed differently, and
 * the two checkout ones concatenate their ids in opposite orders:
 *
 *   order        order_id|payment_id        signed with the API key secret
 *   subscription payment_id|subscription_id signed with the API key secret
 *   webhook      the raw request body       signed with the webhook secret
 *
 * Getting the order backwards produces a signature that never matches, which
 * looks exactly like a forged request, so the two are separate named methods
 * rather than one call with an argument order to get wrong.
 *
 * @version 1.0.0
 */
final class RazorpaySignature
{
    /** Checkout callback for a one-time payment against an order. */
    public static function forOrder(string $orderId, string $paymentId, string $keySecret): string
    {
        return hash_hmac('sha256', $orderId . '|' . $paymentId, $keySecret);
    }

    /** Checkout callback for a subscription. Note the reversed operand order. */
    public static function forSubscription(string $paymentId, string $subscriptionId, string $keySecret): string
    {
        return hash_hmac('sha256', $paymentId . '|' . $subscriptionId, $keySecret);
    }

    /** Webhook deliveries are signed over the exact bytes received. */
    public static function forWebhook(string $rawBody, string $webhookSecret): string
    {
        return hash_hmac('sha256', $rawBody, $webhookSecret);
    }

    /** Constant-time compare; false for an empty secret or empty candidate. */
    public static function matches(string $expected, string $given): bool
    {
        if ($expected === '' || $given === '') {
            return false;
        }
        return hash_equals($expected, $given);
    }
}
