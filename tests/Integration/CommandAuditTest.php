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

final class CommandAuditTest extends IntegrationTestCase
{
    private function registry(): CommandRegistry
    {
        return new CommandRegistry(Plugin::instance()->container->get(EventRecorder::class));
    }

    public function test_invoked_event_has_hashed_digest_and_no_raw_pii(): void
    {
        $r = $this->registry();
        $r->register(new Command(
            'test.audit_ok',
            'ok',
            ['type' => 'object', 'properties' => ['email' => ['type' => 'string']], 'additionalProperties' => true],
            [],
            'manage_options',
            true,
            false,
            fn () => ['done' => true],
        ));

        $secretEmail = 'donor-secret@example.com';
        $res = $r->dispatch('test.audit_ok', ['email' => $secretEmail], new CommandContext(1, 'rest', 'req-a'));

        $this->assertTrue($res->ok);

        $rows = Event::query()->where('type', 'command.invoked')->getAll();
        $mine = array_values(array_filter($rows, fn ($e) => ($e->payload['command_id'] ?? '') === 'test.audit_ok'));
        $this->assertCount(1, $mine);

        $payload = $mine[0]->payload;
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $payload['input_digest']);
        $this->assertArrayNotHasKey('email', $payload);

        foreach (Event::query()->where('type', 'command.invoked')->getAll() as $e) {
            $this->assertStringNotContainsString($secretEmail, (string) wp_json_encode($e->payload));
        }
    }

    public function test_denied_and_failed_event_types(): void
    {
        $r = $this->registry();
        $r->register(new Command('test.audit_denied', 'd', [], [], 'cap_admin_lacks', true, false, fn () => []));
        $r->register(new Command('test.audit_boom', 'b', [], [], 'manage_options', true, false, function (): array {
            throw new CommandError('nope');
        }));

        $r->dispatch('test.audit_denied', [], new CommandContext(1, 'rest', 'req-d'));
        $r->dispatch('test.audit_boom', [], new CommandContext(1, 'rest', 'req-f'));

        $denied = Event::query()->where('type', 'command.denied')->getAll();
        $failed = Event::query()->where('type', 'command.failed')->getAll();

        $this->assertContains('test.audit_denied', array_map(fn ($e) => $e->payload['command_id'] ?? '', $denied));
        $this->assertContains('test.audit_boom', array_map(fn ($e) => $e->payload['command_id'] ?? '', $failed));
    }
}
