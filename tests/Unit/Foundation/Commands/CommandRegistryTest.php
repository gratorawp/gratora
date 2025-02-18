<?php

declare(strict_types=1);

namespace Dono\Tests\Unit\Foundation\Commands;

use Dono\Foundation\Commands\Command;
use Dono\Foundation\Commands\CommandRegistry;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CommandRegistryTest extends TestCase
{
    private function command(string $id): Command
    {
        return new Command($id, 'Summary of ' . $id, ['type' => 'object'], [], 'manage_options', true, false, fn () => []);
    }

    public function test_register_has_get_all(): void
    {
        $r = new CommandRegistry();
        $r->register($this->command('donation.get'));

        $this->assertTrue($r->has('donation.get'));
        $this->assertFalse($r->has('donation.missing'));
        $this->assertSame('donation.get', $r->get('donation.get')?->id);
        $this->assertNull($r->get('donation.missing'));
        $this->assertSame(['donation.get'], array_keys($r->all()));
    }

    public function test_duplicate_id_throws(): void
    {
        $r = new CommandRegistry();
        $r->register($this->command('donation.get'));

        $this->expectException(RuntimeException::class);
        $r->register($this->command('donation.get'));
    }

    public function test_manifest_shape_and_omits_handler(): void
    {
        $r = new CommandRegistry();
        $r->register($this->command('donation.get'));

        $manifest = $r->manifest();
        $this->assertCount(1, $manifest);

        $entry = $manifest[0];
        $this->assertSame(
            ['id', 'summary', 'inputSchema', 'outputSchema', 'capability', 'idempotent', 'mutating', 'meta'],
            array_keys($entry)
        );
        $this->assertArrayNotHasKey('handler', $entry);
        $this->assertSame('donation.get', $entry['id']);
        $this->assertSame(['type' => 'object'], $entry['inputSchema']);
    }
}
