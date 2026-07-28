<?php

declare(strict_types=1);

namespace Dono\Tests\Unit;

use Dono\Gateways\Razorpay\RazorpaySignature;
use PHPUnit\Framework\TestCase;

/**
 * Razorpay's two checkout signatures concatenate their ids in opposite orders.
 * Swapping them yields a signature that never matches, which presents as every
 * subscription being rejected as forged, so the distinction is pinned here.
 */
final class RazorpaySignatureTest extends TestCase
{
    private const SECRET = 'rzp_secret_value';

    public function test_order_signature_is_order_then_payment(): void
    {
        $expected = hash_hmac('sha256', 'order_ABC|pay_XYZ', self::SECRET);

        $this->assertSame($expected, RazorpaySignature::forOrder('order_ABC', 'pay_XYZ', self::SECRET));
    }

    public function test_subscription_signature_is_payment_then_subscription(): void
    {
        $expected = hash_hmac('sha256', 'pay_XYZ|sub_ABC', self::SECRET);

        $this->assertSame($expected, RazorpaySignature::forSubscription('pay_XYZ', 'sub_ABC', self::SECRET));
    }

    /**
     * The whole point of keeping the two apart. Both helpers join their two
     * arguments identically, so the only thing that separates them is which id
     * goes first, and reading a subscription like an order (parent object, then
     * payment) is the natural mistake.
     */
    public function test_a_subscription_signature_fails_if_signed_in_order_form(): void
    {
        $real = RazorpaySignature::forSubscription('pay_1', 'sub_1', self::SECRET);

        // Parent-object-first, which is right for orders and wrong here.
        $wrong = RazorpaySignature::forOrder('sub_1', 'pay_1', self::SECRET);

        $this->assertFalse(RazorpaySignature::matches($wrong, $real));
    }

    public function test_webhook_signature_signs_the_raw_body(): void
    {
        $body = '{"event":"payment.captured","payload":{}}';

        $this->assertSame(
            hash_hmac('sha256', $body, 'whsec'),
            RazorpaySignature::forWebhook($body, 'whsec')
        );
    }

    /** A byte of difference in the body must change the signature. */
    public function test_webhook_signature_covers_every_byte(): void
    {
        $this->assertNotSame(
            RazorpaySignature::forWebhook('{"amount":100}', 'whsec'),
            RazorpaySignature::forWebhook('{"amount":900}', 'whsec'),
        );
    }

    public function test_matching_is_exact(): void
    {
        $sig = RazorpaySignature::forOrder('order_1', 'pay_1', self::SECRET);

        $this->assertTrue(RazorpaySignature::matches($sig, $sig));
        $this->assertFalse(RazorpaySignature::matches($sig, strrev($sig)));
    }

    /**
     * An unconfigured webhook secret produces an empty expected signature.
     * Comparing empty to empty must not read as a pass, or a site with no
     * secret would accept anything.
     */
    public function test_empty_values_never_match(): void
    {
        $this->assertFalse(RazorpaySignature::matches('', ''));
        $this->assertFalse(RazorpaySignature::matches('', 'anything'));
        $this->assertFalse(RazorpaySignature::matches('anything', ''));
    }
}
