<?php

declare(strict_types=1);

namespace Dono\Tests\Unit\Foundation\Commands;

use Dono\Foundation\Commands\Command;
use Dono\Foundation\Commands\CommandContext;
use Dono\Foundation\Commands\CommandError;
use Dono\Foundation\Commands\CommandResult;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CommandValueObjectsTest extends TestCase
{
    public function test_command_rejects_empty_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Command('', 's', [], [], 'manage_options', true, false, fn () => []);
    }

    public function test_command_is_immutable(): void
    {
        $c = new Command('donation.get', 's', [], [], 'manage_options', true, false, fn () => []);
        $this->expectException(\Error::class);
        /** @phpstan-ignore-next-line intentional readonly violation */
        $c->id = 'mutated';
    }

    public function test_context_is_immutable(): void
    {
        $ctx = new CommandContext(1, 'rest', 'req-1');
        $this->assertFalse($ctx->dry_run);
        $this->assertNull($ctx->confirmation);
        $this->expectException(\Error::class);
        /** @phpstan-ignore-next-line intentional readonly violation */
        $ctx->source = 'mcp';
    }

    public function test_result_ok_and_error_factories(): void
    {
        $ok = CommandResult::ok(['x' => 1]);
        $this->assertTrue($ok->ok);
        $this->assertSame(['x' => 1], $ok->data);
        $this->assertNull($ok->error_code);

        $err = CommandResult::error('command.denied', 'nope');
        $this->assertFalse($err->ok);
        $this->assertSame('command.denied', $err->error_code);
        $this->assertSame('nope', $err->error);
    }

    public function test_confirmation_required_factory_shape(): void
    {
        $r = CommandResult::confirmationRequired(['amount' => 500], 'abc123');
        $this->assertFalse($r->ok);
        $this->assertSame('command.confirmation_required', $r->error_code);
        $this->assertSame(['amount' => 500], $r->data['preview']);
        $this->assertSame('abc123', $r->data['confirm_digest']);
    }

    public function test_dry_run_factory_shape(): void
    {
        $r = CommandResult::dryRun(['amount' => 500], 'abc123');
        $this->assertTrue($r->ok);
        $this->assertSame(['amount' => 500], $r->data['canonical_input']);
        $this->assertSame('abc123', $r->data['confirm_digest']);
        $this->assertNull($r->error_code);
    }

    public function test_command_error_is_runtime_exception(): void
    {
        $this->assertInstanceOf(\RuntimeException::class, new CommandError('boom'));
    }
}
