<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Async\AsyncDispatcher;
use Dono\Foundation\Config\SystemSetting;
use Dono\Foundation\Identity\IdentityHasher;

/**
 * IdentityHasher takes an AsyncDispatcher and reads its pepper/salt from
 * dono_system_settings, so it needs the real DB. Relocated from the pure-unit
 * suite for that reason.
 */
final class IdentityHasherTest extends IntegrationTestCase
{
    private function hasher(): IdentityHasher
    {
        return new IdentityHasher(new AsyncDispatcher());
    }

    public function test_email_normalization_strips_whitespace_and_lowercases(): void
    {
        $this->assertSame('user@example.com', $this->hasher()->normalizeEmail('  User@Example.com  '));
    }

    public function test_email_hash_is_deterministic_across_calls(): void
    {
        $h = $this->hasher();
        $this->assertSame($h->emailHash('User@Example.com'), $h->emailHash('user@example.com'));
    }

    public function test_email_hash_differs_per_email(): void
    {
        $h = $this->hasher();
        $this->assertNotSame($h->emailHash('a@example.com'), $h->emailHash('b@example.com'));
    }

    public function test_email_hash_is_64_hex_chars(): void
    {
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $this->hasher()->emailHash('x@y.com'));
    }

    public function test_email_hash_is_stable_across_instances(): void
    {
        // Donor dedup relies on the email hash being identical within an install.
        $this->assertSame(
            $this->hasher()->emailHash('dedup@example.com'),
            $this->hasher()->emailHash('dedup@example.com'),
        );
    }

    public function test_ip_hash_is_stable_across_instances(): void
    {
        $this->assertSame(
            $this->hasher()->ipHash('203.0.113.7'),
            $this->hasher()->ipHash('203.0.113.7'),
        );
    }

    public function test_ip_hash_handles_null_and_empty(): void
    {
        $h = $this->hasher();
        $this->assertNull($h->ipHash(null));
        $this->assertNull($h->ipHash(''));
    }

    public function test_user_agent_hash_handles_null_and_empty(): void
    {
        $h = $this->hasher();
        $this->assertNull($h->userAgentHash(null));
        $this->assertNull($h->userAgentHash(''));
        $this->assertIsString($h->userAgentHash('Mozilla/5.0'));
    }

    public function test_regenerated_ip_salt_yields_a_different_hash(): void
    {
        // Losing the salt row simulates a fresh install: a new random salt is
        // generated, so the same IP no longer hashes to the old value.
        $hashA = $this->hasher()->ipHash('203.0.113.7');

        SystemSetting::forget('ip_salt_v1');
        $hashB = $this->hasher()->ipHash('203.0.113.7');

        $this->assertNotSame($hashA, $hashB);
    }
}
