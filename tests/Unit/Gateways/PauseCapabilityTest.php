<?php

declare(strict_types=1);

namespace Dono\Tests\Unit\Gateways;

use Dono\Gateways\SupportsSubscriptionPause;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * A capability declared by an empty marker is invisible when it is wrong: no
 * error, no failing test, and core silently takes the wrong branch.
 *
 * Core read SubscriptionAware as "this plan can be paused". Two shipped
 * gateways declare it and refuse both pause and skip, so the portal offered
 * both buttons on every plan and a Direct Debit donor got a raw 422 from
 * either. The cancel deflection sheet offers exactly those two as the
 * alternatives to cancelling, so a donor trying not to cancel was handed two
 * dead ends and then cancelled.
 *
 * Asserted over the classes rather than the registry: a gateway registers only
 * once it is configured, so a registry walk would pass on a bare install by
 * finding nothing to check.
 */
final class PauseCapabilityTest extends TestCase
{
    /** @return list<array{0:string}> */
    public static function coreSubscriptionGateways(): array
    {
        return [
            [\Dono\Gateways\Stripe\StripeGateway::class],
            [\Dono\Gateways\PayPal\PayPalGateway::class],
        ];
    }

    /**
     * @dataProvider coreSubscriptionGateways
     */
    public function test_a_gateway_that_can_pause_declares_it(string $class): void
    {
        $this->assertTrue(
            (new ReflectionClass($class))->implementsInterface(SupportsSubscriptionPause::class),
            $class . ' can pause, so the portal must be able to see that'
        );
    }

    /**
     * The pairing that matters: declaring the marker and then throwing is the
     * failure this interface exists to make impossible.
     *
     * @dataProvider coreSubscriptionGateways
     */
    public function test_a_gateway_that_declares_pause_does_not_refuse_it(string $class): void
    {
        $reflection = new ReflectionClass($class);
        $source     = (string) file_get_contents((string) $reflection->getFileName());
        $body       = self::methodBody($source, 'pauseSubscription');

        $this->assertNotSame('', $body, $class . ' implements SubscriptionAware, so it has this method');
        $this->assertStringNotContainsString(
            'throw new \RuntimeException',
            $body,
            $class . ' declares pause support and then refuses the call'
        );
    }

    /** The text between this signature and the next public one. */
    private static function methodBody(string $source, string $method): string
    {
        $start = strpos($source, 'function ' . $method . '(');
        if ($start === false) {
            return '';
        }

        $next = strpos($source, "\n    public function ", $start + 1);

        return substr($source, $start, $next === false ? 800 : $next - $start);
    }
}
