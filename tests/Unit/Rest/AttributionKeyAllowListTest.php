<?php

declare(strict_types=1);

namespace Gratora\Tests\Unit\Rest;

use Gratora\Rest\DonationsController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * `source_attribution` is a JSON column written straight from the body of an
 * unauthenticated POST, and read back by the admin donation screen, the CSV
 * export and the erasure handler. The donation form sends seven keys. Anything
 * else arriving under that name is a stranger choosing what the column holds.
 */
final class AttributionKeyAllowListTest extends TestCase
{
    /**
     * @param  array<string,mixed>|null $attribution
     * @return array<string,string>|null
     */
    private function bound(?array $attribution): ?array
    {
        $method = new ReflectionMethod(DonationsController::class, 'boundAttribution');
        $method->setAccessible(true);

        return $method->invoke(null, $attribution);
    }

    private function valueMax(): int
    {
        return (int) (new ReflectionClass(DonationsController::class))->getConstant('ATTRIBUTION_VALUE_MAX');
    }

    public function test_every_key_the_donation_form_sends_is_kept(): void
    {
        $sent = [
            'utm_source'   => 'mailchimp',
            'utm_medium'   => 'email',
            'utm_campaign' => 'spring-appeal',
            'utm_term'     => 'food bank',
            'utm_content'  => 'ask-b',
            'referrer'     => 'https://mail.example.com/click?u=8213',
            'landing'      => 'https://charity.example/appeal/?utm_source=mailchimp',
        ];

        $this->assertSame($sent, $this->bound($sent));
    }

    public function test_a_key_the_form_never_sends_is_discarded(): void
    {
        $out = $this->bound([
            'utm_source'     => 'newsletter',
            'fundraiser_id'  => 91,
            'is_test'        => 'true',
            'source'         => 'csv_import',
            'note_to_org'    => 'anything at all',
        ]);

        $this->assertSame(['utm_source' => 'newsletter'], $out);
    }

    public function test_a_list_payload_keeps_nothing(): void
    {
        $this->assertNull($this->bound(['first', 'second']));
    }

    public function test_a_body_of_nothing_but_unknown_keys_stores_nothing(): void
    {
        $this->assertNull($this->bound(['gclid' => 'abc', 'fbclid' => 'def']));
    }

    public function test_an_allowed_key_holding_something_other_than_a_scalar_is_discarded(): void
    {
        $out = $this->bound([
            'utm_medium' => 'email',
            'landing'    => ['https://charity.example/'],
        ]);

        $this->assertSame(['utm_medium' => 'email'], $out);
    }

    public function test_an_allowed_value_is_still_bounded(): void
    {
        $out = $this->bound(['landing' => 'https://charity.example/?q=' . str_repeat('a', 4000)]);

        $this->assertSame($this->valueMax(), mb_strlen((string) $out['landing']));
    }

    public function test_junk_keys_do_not_spend_the_size_budget_of_the_real_attribution(): void
    {
        $payload = ['utm_source' => 'google', 'utm_medium' => 'cpc'];
        for ($i = 0; $i < 200; $i++) {
            $payload['pad_' . $i] = str_repeat('x', 400);
        }

        $this->assertSame(['utm_source' => 'google', 'utm_medium' => 'cpc'], $this->bound($payload));
    }
}
