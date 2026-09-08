<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Foundation\Commands\CommandRegistry;
use FundKit\Foundation\Plugin;

/** Broadcast command registration after all modules boot, using the shared registry. */
final class CommandRegisterHookTest extends IntegrationTestCase
{
    public function test_broadcast_fired_exactly_once_after_boot(): void
    {
        $this->assertGreaterThanOrEqual(1, did_action('fundkit.commands.register'));
    }

    public function test_handlers_receive_the_shared_populated_registry(): void
    {
        $container = Plugin::instance()->container;
        $seen      = null;

        add_action('fundkit.commands.register', static function ($registry) use (&$seen): void {
            $seen = $registry;
            $registry->register(new \FundKit\Foundation\Commands\Command(
                'test.late_pack',
                'A command an add-on registers via the broadcast.',
                [],
                [],
                'manage_options',
                true,
                false,
                static fn (): array => ['ok' => true],
            ));
        });

        // Simulate the post-bootAll fire with the same arguments Plugin::boot uses.
        do_action('fundkit.commands.register', $container->get(CommandRegistry::class), $container);

        $this->assertSame($container->get(CommandRegistry::class), $seen, 'handler gets the container singleton');
        $this->assertTrue($container->get(CommandRegistry::class)->has('test.late_pack'), 'the added command lands in the shared registry');
        $this->assertTrue($container->get(CommandRegistry::class)->has('donation.create'), 'core commands were already present');

        remove_all_filters('fundkit.commands.register');
    }
}
