<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Analytics\Event;
use Dono\Analytics\EventRecorder;
use Dono\Foundation\Commands\Command;
use Dono\Foundation\Commands\CommandContext;
use Dono\Foundation\Commands\CommandRegistry;
use Dono\Foundation\Plugin;

final class CommandRateLimitTest extends IntegrationTestCase
{
    private function registry(): CommandRegistry
    {
        return new CommandRegistry(Plugin::instance()->container->get(EventRecorder::class));
    }

    private function command(string $id): Command
    {
        return new Command(
            $id,
            $id,
            [],
            [],
            'manage_options',
            true,
            false,
            fn () => ['ok' => 1],
            ['rate_limit' => 2],
        );
    }

    public function test_non_interactive_source_is_throttled_after_the_limit(): void
    {
        $r = $this->registry();
        $r->register($this->command('rl.auto'));

        $a = $r->dispatch('rl.auto', [], new CommandContext(1, 'automation', 'r1'));
        $b = $r->dispatch('rl.auto', [], new CommandContext(1, 'automation', 'r2'));
        $c = $r->dispatch('rl.auto', [], new CommandContext(1, 'automation', 'r3'));

        $this->assertTrue($a->ok);
        $this->assertTrue($b->ok);
        $this->assertFalse($c->ok);
        $this->assertSame('command.rate_limited', $c->error_code);

        $limited = array_filter(
            Event::query()->where('type', 'command.rate_limited')->getAll(),
            fn ($e) => ($e->payload['command_id'] ?? '') === 'rl.auto'
        );
        $this->assertNotEmpty($limited);
    }

    public function test_interactive_admin_is_not_throttled(): void
    {
        $r = $this->registry();
        $r->register($this->command('rl.rest'));

        for ($i = 0; $i < 5; $i++) {
            $res = $r->dispatch('rl.rest', [], new CommandContext(1, 'rest', "h{$i}"));
            $this->assertTrue($res->ok, "interactive dispatch {$i} must not be throttled");
        }
    }
}
