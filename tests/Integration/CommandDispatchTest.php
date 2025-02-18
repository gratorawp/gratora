<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Analytics\Event;
use Dono\Analytics\EventRecorder;
use Dono\Foundation\Commands\Command;
use Dono\Foundation\Commands\CommandContext;
use Dono\Foundation\Commands\CommandError;
use Dono\Foundation\Commands\CommandRegistry;
use Dono\Foundation\Plugin;

final class CommandDispatchTest extends IntegrationTestCase
{
    private function registry(): CommandRegistry
    {
        return new CommandRegistry(Plugin::instance()->container->get(EventRecorder::class));
    }

    private function ctx(string $source = 'rest', ?int $userId = 1): CommandContext
    {
        return new CommandContext($userId, $source, 'req-' . uniqid());
    }

    public function test_unknown_command_returns_not_found(): void
    {
        $r = $this->registry();
        $res = $r->dispatch('nope.missing', [], $this->ctx());

        $this->assertFalse($res->ok);
        $this->assertSame('command.not_found', $res->error_code);
    }

    public function test_permission_denied_before_handler_and_writes_denied_event(): void
    {
        $called = false;
        $r = $this->registry();
        $r->register(new Command(
            'test.denied',
            'denied',
            [],
            [],
            'a_cap_admin_does_not_have',
            true,
            false,
            function () use (&$called) {
                $called = true;
                return [];
            },
        ));

        $res = $r->dispatch('test.denied', [], $this->ctx());

        $this->assertFalse($called, 'handler must not run when denied');
        $this->assertSame('command.denied', $res->error_code);
        $rows = Event::query()->where('type', 'command.denied')->getAll();
        $this->assertNotEmpty($rows);
        $this->assertSame('test.denied', $rows[count($rows) - 1]->payload['command_id']);
    }

    public function test_invalid_input_rejected_before_handler(): void
    {
        $called = false;
        $r = $this->registry();
        $r->register(new Command(
            'test.validated',
            'validated',
            [
                'type'                 => 'object',
                'properties'           => ['n' => ['type' => 'integer']],
                'required'             => ['n'],
                'additionalProperties' => false,
            ],
            [],
            'manage_options',
            true,
            false,
            function () use (&$called) {
                $called = true;
                return [];
            },
        ));

        $res = $r->dispatch('test.validated', ['n' => 'not-an-int'], $this->ctx());

        $this->assertFalse($called);
        $this->assertSame('command.invalid_input', $res->error_code);
    }

    public function test_valid_read_command_returns_ok(): void
    {
        $r = $this->registry();
        $r->register(new Command(
            'test.ping',
            'ping',
            [],
            [],
            'manage_options',
            true,
            false,
            fn () => ['pong' => true],
        ));

        $res = $r->dispatch('test.ping', [], $this->ctx());

        $this->assertTrue($res->ok);
        $this->assertSame(['pong' => true], $res->data);
    }

    public function test_handler_command_error_maps_to_failed(): void
    {
        $r = $this->registry();
        $r->register(new Command(
            'test.boom',
            'boom',
            [],
            [],
            'manage_options',
            true,
            false,
            function (): array {
                throw new CommandError('kaboom');
            },
        ));

        $res = $r->dispatch('test.boom', [], $this->ctx());

        $this->assertFalse($res->ok);
        $this->assertSame('command.failed', $res->error_code);
        $this->assertSame('kaboom', $res->error);
    }
}
