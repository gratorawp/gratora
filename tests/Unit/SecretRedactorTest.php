<?php

declare(strict_types=1);

namespace Dono\Tests\Unit;

use Dono\Settings\SecretRedactor;
use PHPUnit\Framework\TestCase;

/**
 * The redactor masks stored secrets on the way out over REST. It keys off the
 * setting's name, so the pattern has to tell a secret from a word that merely
 * contains one: "tokens" (a brand preset's design tokens) is not a secret, and
 * masking it turned every preset the same colour.
 */
final class SecretRedactorTest extends TestCase
{
    /** @return array<string, array{0:string}> */
    public static function secretKeys(): array
    {
        return [
            'bare token'          => ['token'],
            'access token'        => ['access_token'],
            'api token'           => ['api_token'],
            'webhook secret'      => ['webhook_secret_live'],
            'stripe secret key'   => ['secret_key'],
            'client secret'       => ['client_secret'],
            'a password'          => ['password'],
            'api key'             => ['api_key'],
        ];
    }

    /** @dataProvider secretKeys */
    public function test_a_real_secret_is_masked(string $key): void
    {
        $out = SecretRedactor::redact([$key => 'sensitive-value']);
        $this->assertSame(SecretRedactor::MASK, $out[$key], "$key should be masked");
    }

    /** @return array<string, array{0:string}> */
    public static function benignKeys(): array
    {
        return [
            'style tokens'  => ['tokens'],
            'tokenize flag' => ['tokenize'],
            'secretary'     => ['secretary'],
        ];
    }

    /** @dataProvider benignKeys */
    public function test_a_word_that_merely_contains_a_secret_word_is_not_masked(string $key): void
    {
        $value = ['dono-accent' => '#0F3D5C'];
        $out   = SecretRedactor::redact([$key => $value]);
        $this->assertSame($value, $out[$key], "$key must not be masked");
    }

    public function test_the_brand_tokens_object_survives_redaction_intact(): void
    {
        $brand = [
            'presets' => [
                ['id' => 'bold', 'tokens' => ['dono-accent' => '#0F3D5C']],
                ['id' => 'quiet', 'tokens' => ['dono-accent' => '#111827']],
            ],
            'default_id' => 'bold',
        ];

        $out = SecretRedactor::redact($brand);

        $this->assertSame('#0F3D5C', $out['presets'][0]['tokens']['dono-accent']);
        $this->assertSame('#111827', $out['presets'][1]['tokens']['dono-accent']);
    }
}
