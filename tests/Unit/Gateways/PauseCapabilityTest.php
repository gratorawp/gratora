<?php

declare(strict_types=1);

namespace FundKit\Tests\Unit\Gateways;

use FundKit\Gateways\SupportsSubscriptionPause;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Inspect gateway classes directly; an unconfigured registry can be empty and hide incorrect
 * capability markers.
 */
final class PauseCapabilityTest extends TestCase
{
    /** @return list<array{0:string}> */
    public static function coreSubscriptionGateways(): array
    {
        return [
            [\FundKit\Gateways\Stripe\StripeGateway::class],
            [\FundKit\Gateways\PayPal\PayPalGateway::class],
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
