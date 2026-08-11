<?php

declare(strict_types=1);

namespace Dono\Tests\Unit\Assets;

use PHPUnit\Framework\TestCase;

/**
 * PayPal takes the first payment the moment the donor approves a subscription,
 * while the donation row stays `pending` until the opening sale webhook lands.
 * The form must not read that row as "the donor still owes us something": the
 * pending screen promises an email with instructions to complete the payment,
 * which nothing sends and which invites a donor who has already paid to pay
 * again.
 *
 * @since 1.0.0
 */
final class PayPalRecurringStatusTest extends TestCase
{
    private function paypalSource(): string
    {
        $path = dirname(__DIR__, 3) . '/assets/donation-form/components/PayPalPayment.jsx';
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /** The subscription arm of the buttons, up to where the one-time arm starts. */
    private function subscriptionBranch(): string
    {
        $src   = $this->paypalSource();
        $start = strpos($src, 'createSubscription');
        $end   = strpos($src, ': sdk.Buttons(');

        $this->assertIsInt($start, 'PayPalPayment no longer has a subscription branch.');
        $this->assertIsInt($end, 'PayPalPayment no longer has a one-time branch.');
        $this->assertGreaterThan($start, $end);

        return substr($src, (int) $start, (int) $end - (int) $start);
    }

    public function test_approved_subscription_does_not_land_on_the_pending_screen(): void
    {
        $branch = $this->subscriptionBranch();

        $this->assertStringContainsString(
            'processing: true',
            $branch,
            'An approved subscription has been charged, so the donor is finished.'
        );
    }

    public function test_a_settled_subscription_is_reported_as_paid(): void
    {
        $this->assertStringContainsString(
            'SUBMIT_SUCCESS',
            $this->subscriptionBranch(),
            'When the webhook beat the browser, the server says paid and the form must agree.'
        );
    }

    public function test_the_reducer_honors_an_already_charged_donation(): void
    {
        $path = dirname(__DIR__, 3) . '/assets/donation-form/state/store.js';
        $this->assertFileExists($path);

        $matched = preg_match(
            "/case 'SUBMIT_PENDING':(.*?)case 'SUBMIT_ERROR'/s",
            (string) file_get_contents($path),
            $m
        );
        $this->assertSame(1, $matched, 'The reducer no longer handles SUBMIT_PENDING.');

        $this->assertStringContainsString(
            'action.processing',
            (string) $m[1],
            'Only the gateway knows the donor was charged before the record settles.'
        );
    }
}
