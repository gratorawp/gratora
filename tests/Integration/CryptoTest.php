<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Foundation\Crypto\Crypto;

/**
 * Crypto reads its key from dono_system_settings, so it needs the real DB.
 * Relocated from the pure-unit suite for that reason.
 */
final class CryptoTest extends IntegrationTestCase
{
    public function test_roundtrip_recovers_plaintext(): void
    {
        $c = new Crypto();
        $cipher = $c->encrypt('hello@example.com');

        $this->assertNotSame('hello@example.com', $cipher);
        $this->assertSame('hello@example.com', $c->decrypt($cipher));
    }

    public function test_each_encrypt_call_produces_a_different_ciphertext(): void
    {
        // GCM IV is random per call, so the same plaintext yields different ciphertext.
        $c = new Crypto();
        $a = $c->encrypt('same input');
        $b = $c->encrypt('same input');

        $this->assertNotSame($a, $b);
        $this->assertSame('same input', $c->decrypt($a));
        $this->assertSame('same input', $c->decrypt($b));
    }

    public function test_tampered_ciphertext_returns_null(): void
    {
        $c = new Crypto();
        $cipher = $c->encrypt('private');
        $raw = base64_decode($cipher, true);
        $raw[20] = chr((ord($raw[20]) + 1) & 0xff);

        $this->assertNull($c->decrypt(base64_encode($raw)));
    }

    public function test_garbage_payload_returns_null(): void
    {
        $c = new Crypto();
        $this->assertNull($c->decrypt('not-actually-base64!!!'));
        $this->assertNull($c->decrypt(''));
        $this->assertNull($c->decrypt(base64_encode('too short')));
    }

    public function test_unicode_payload_roundtrips(): void
    {
        $c = new Crypto();
        $plain = 'Sarah Müller · €50 · 寄付 🍽';
        $this->assertSame($plain, $c->decrypt($c->encrypt($plain)));
    }

    public function test_persisted_key_is_stable_across_instances(): void
    {
        $a = new Crypto();
        $b = new Crypto();

        $this->assertSame('keep me', $b->decrypt($a->encrypt('keep me')));
    }
}
