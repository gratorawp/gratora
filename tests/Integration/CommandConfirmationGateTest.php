<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Analytics\Event;
use Dono\Analytics\EventRecorder;
use Dono\Foundation\Commands\Command;
use Dono\Foundation\Commands\CommandContext;
use Dono\Foundation\Commands\CommandRegistry;
use Dono\Foundation\Plugin;

final class CommandConfirmationGateTest extends IntegrationTestCase
{
    private function registry(): CommandRegistry
    {
        return new CommandRegistry(Plugin::instance()->container->get(EventRecorder::class));
    }

    private function mutating(string $id, ?callable &$calledFlag): Command
    {
        return new Command($id, $id, [], [], 'manage_options', false, true, function () use (&$calledFlag): array {
            if ($calledFlag !== null) {
                ($calledFlag)();
            }
            return ['ran' => true];
        });
    }

    private function invokedFor(string $id): array
    {
        return array_values(array_filter(
            Event::query()->where('type', 'command.invoked')->getAll(),
            fn ($e) => ($e->payload['command_id'] ?? '') === $id
        ));
    }

    public function test_dry_run_mutating_returns_canonical_and_digest_with_zero_writes(): void
    {
        $ran = false;
        $flag = function () use (&$ran) {
            $ran = true;
        };
        $r = $this->registry();
        $r->register($this->mutating('gate.dry', $flag));

        $res = $r->dispatch('gate.dry', ['a' => 1], new CommandContext(1, 'mcp', 'req-1', true));

        $this->assertTrue($res->ok);
        $this->assertSame(['a' => 1], $res->data['canonical_input']);
        $this->assertNotEmpty($res->data['confirm_digest']);
        $this->assertFalse($ran, 'dry_run must not invoke the handler');
        $this->assertCount(0, $this->invokedFor('gate.dry'));
    }

    public function test_mcp_mutating_without_token_is_confirmation_required(): void
    {
        $r = $this->registry();
        $r->register($this->mutating('gate.notoken', $n));

        $res = $r->dispatch('gate.notoken', ['x' => 9], new CommandContext(1, 'mcp', 'req-2'));

        $this->assertFalse($res->ok);
        $this->assertSame('command.confirmation_required', $res->error_code);
        $this->assertSame(['command' => 'gate.notoken', 'input' => ['x' => 9]], $res->data['preview']);
        $this->assertNotEmpty($res->data['confirm_digest']);
        $this->assertCount(0, $this->invokedFor('gate.notoken'));
    }

    public function test_mcp_mutating_token_binding_enforced_by_core(): void
    {
        add_filter('dono.commands.confirmation_verifier', fn () => new class {
            public function verify(string $token, string $session, string $commandId, string $inputDigest): bool
            {
                return $token === 'good';
            }
        });

        $r = $this->registry();
        $r->register($this->mutating('gate.tok', $n));

        $bad = $r->dispatch('gate.tok', ['v' => 1], new CommandContext(1, 'mcp', 'req-3', false, 'wrong'));
        $this->assertSame('command.confirmation_required', $bad->error_code);

        $ok = $r->dispatch('gate.tok', ['v' => 1], new CommandContext(1, 'mcp', 'req-4', false, 'good'));
        $this->assertTrue($ok->ok);
        $this->assertSame(['ran' => true], $ok->data);

        remove_all_filters('dono.commands.confirmation_verifier');
    }

    public function test_mcp_read_command_proceeds_without_token(): void
    {
        $r = $this->registry();
        $r->register(new Command('gate.read', 'r', [], [], 'manage_options', true, false, fn () => ['ok' => 1]));

        $res = $r->dispatch('gate.read', [], new CommandContext(1, 'mcp', 'req-5'));

        $this->assertTrue($res->ok);
        $this->assertSame(['ok' => 1], $res->data);
    }

    public function test_automation_mutating_ignores_confirmation(): void
    {
        $r = $this->registry();
        $r->register($this->mutating('gate.auto', $n));

        $res = $r->dispatch('gate.auto', ['z' => 2], new CommandContext(1, 'automation', 'req-6'));

        $this->assertTrue($res->ok);
        $this->assertSame(['ran' => true], $res->data);
        $this->assertCount(1, $this->invokedFor('gate.auto'));
    }
}
