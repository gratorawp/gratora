<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Donations\ChannelClassifier;
use Gratora\Donations\Donation;
use WP_REST_Request;

/**
 * manual and embed are reporting buckets the org owns, not channels a visitor
 * can claim. aggregatePaidByAttribution groups on the stored utm_medium, so a
 * donor arriving on a query string would otherwise write either one.
 */
final class ReservedChannelMediumTest extends IntegrationTestCase
{
    /** @param array<string,mixed> $attribution */
    private function submit(array $attribution): string
    {
        $req = new WP_REST_Request('POST', '/gratora/v1/donations');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode([
            'email'              => 'reserved-' . uniqid() . '@example.test',
            'amount_cents'       => 2500,
            'currency'           => 'USD',
            'gateway'            => 'offline',
            'frequency'          => 'one_time',
            'source_attribution' => $attribution,
        ]));

        $res = rest_do_request($req);
        $this->assertSame(201, $res->get_status(), (string) wp_json_encode($res->get_data()));

        return (string) ($res->get_data()['reference'] ?? '');
    }

    /** @return array<string,mixed> */
    private function storedAttribution(string $reference): array
    {
        $donation = Donation::query()->find('reference', $reference);
        $this->assertNotNull($donation);

        return (array) ($donation->source_attribution ?? []);
    }

    /** @return array<string,array{0:string}> */
    public static function reservedProvider(): array
    {
        return [
            'manual' => [ChannelClassifier::MANUAL],
            'embed'  => [ChannelClassifier::EMBED],
        ];
    }

    /** @dataProvider reservedProvider */
    public function test_a_donor_cannot_claim_a_reserved_medium(string $medium): void
    {
        $stored = $this->storedAttribution($this->submit([
            'utm_source' => 'newsletter',
            'utm_medium' => $medium,
        ]));

        $this->assertArrayNotHasKey('utm_medium', $stored, $medium . ' is the org\'s bucket, not a donor\'s');
        $this->assertSame('newsletter', $stored['utm_source'] ?? null, 'the rest of the attribution is kept');
    }

    /** @dataProvider reservedProvider */
    public function test_the_claim_is_refused_whatever_its_casing_or_padding(string $medium): void
    {
        $stored = $this->storedAttribution($this->submit([
            'utm_medium' => '  ' . strtoupper($medium) . ' ',
        ]));

        $this->assertArrayNotHasKey('utm_medium', $stored);
    }

    public function test_an_ordinary_medium_is_still_recorded(): void
    {
        $stored = $this->storedAttribution($this->submit([
            'utm_source' => 'newsletter',
            'utm_medium' => 'email',
        ]));

        $this->assertSame('email', $stored['utm_medium'] ?? null);
    }
}
