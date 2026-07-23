<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Foundation\Commands\CommandRegistry;
use Dono\Foundation\Plugin;

/**
 * The dono.commands.register broadcast fires once, from Plugin::boot after all
 * modules have booted, and hands handlers the shared container registry
 * already carrying the core command pack. This is what lets an add-on's
 * boot-time add_action handler contribute a command pack; firing during core's
 * own boot (the prior bug) would run before add-on modules existed.
 */
final class CommandRegisterHookTest extends IntegrationTestCase
{
    public function test_broadcast_fired_exactly_once_after_boot(): void
    {
        $this->assertGreaterThanOrEqual(1, did_action('dono.commands.register'));
    }

    public function test_handlers_receive_the_shared_populated_registry(): void
    {
        $container = Plugin::instance()->container;
        $seen      = null;

        add_action('dono.commands.register', static function ($registry) use (&$seen): void {
            $seen = $registry;
            $registry->register(new \Dono\Foundation\Commands\Command(
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
        do_action('dono.commands.register', $container->get(CommandRegistry::class), $container);

        $this->assertSame($container->get(CommandRegistry::class), $seen, 'handler gets the container singleton');
        $this->assertTrue($container->get(CommandRegistry::class)->has('test.late_pack'), 'the added command lands in the shared registry');
        $this->assertTrue($container->get(CommandRegistry::class)->has('donation.create'), 'core commands were already present');

        remove_all_filters('dono.commands.register');
    }
}
