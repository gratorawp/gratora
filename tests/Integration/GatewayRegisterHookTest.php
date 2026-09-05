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
 */
final class GatewayRegisterHookTest extends IntegrationTestCase
{
    private const CORE   = __DIR__ . '/../../src/Core/CoreModule.php';
    private const PLUGIN = __DIR__ . '/../../src/Foundation/Plugin.php';

    public function test_the_broadcast_does_not_fire_from_inside_core_boot(): void
    {
        $core = (string) file_get_contents(self::CORE);

        $this->assertStringNotContainsString(
            "do_action('fundkit.gateways.register'",
            $core,
            'fired from core boot, every add-on listener is attached one step too late'
        );
    }

    public function test_it_fires_after_every_module_has_booted(): void
    {
        $plugin = (string) file_get_contents(self::PLUGIN);

        $bootAll   = strpos($plugin, '$self->modules->bootAll();');
        $broadcast = strpos($plugin, "do_action(\n                'fundkit.gateways.register'");

        $this->assertNotFalse($bootAll, 'bootAll is where module listeners become attached');
        $this->assertNotFalse($broadcast, 'the broadcast belongs in Plugin::boot');
        $this->assertGreaterThan($bootAll, $broadcast, 'broadcasting before bootAll reaches nobody');
    }

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
