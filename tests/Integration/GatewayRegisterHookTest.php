<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Foundation\Plugin;
use FundKit\Gateways\GatewayManager;
use FundKit\Gateways\Sandbox\SandboxGateway;

/**
 * An add-on contributes gateways by attaching to fundkit.gateways.register in
 * its own module boot(). ModuleManager::bootAll() runs core first, so a
 * broadcast fired inside CoreModule::boot() happens before any add-on listener
 * exists and reaches nobody.
 *
 * That is not hypothetical: the five gateways in fundkit-payment-gateways were
 * never registered. Their settings tabs still rendered and still saved keys,
 * because those ride request-time hooks, so an org saw a connected processor
 * that could never be offered to a donor and whose webhooks threw.
 *
 * The commands seam learned this already; see CommandRegisterHookTest.
 *
 * What is below is the behaviour the ordering enables: a handler is given the
 * shared manager, and what it registers is reachable. The ordering itself has
 * no test, because by the time any test runs, boot is long past and a late
 * broadcast is indistinguishable from an early one.
 */
final class GatewayRegisterHookTest extends IntegrationTestCase
{
    public function test_it_actually_fired_this_request(): void
    {
        $this->assertGreaterThanOrEqual(1, did_action('fundkit.gateways.register'));
    }

    public function test_a_handler_receives_the_shared_manager_and_its_gateway_lands(): void
    {
        $container = Plugin::instance()->container;
        $manager   = $container->get(GatewayManager::class);
        $seen      = null;

        add_action('fundkit.gateways.register', static function ($gateways, $c) use (&$seen): void {
            $seen = $gateways;
            if (! $gateways->get('sandbox')) {
                $gateways->register(new SandboxGateway(
                    $c->get(\FundKit\Foundation\Time\Clock::class),
                    $c->get(\FundKit\Recurring\RecurringPlanRepository::class)
                ));
            }
        }, 10, 2);

        // The same arguments Plugin::boot passes after bootAll.
        do_action('fundkit.gateways.register', $manager, $container);

        $this->assertSame($manager, $seen, 'the handler gets the container singleton, not a copy');
        $this->assertNotNull(
            $manager->get('sandbox'),
            'a gateway registered through the seam has to be reachable through the manager'
        );

        remove_all_filters('fundkit.gateways.register');
    }
}
