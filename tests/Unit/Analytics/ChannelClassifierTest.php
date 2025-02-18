<?php

declare(strict_types=1);

namespace Dono\Tests\Unit\Analytics;

use Dono\Donations\ChannelClassifier;
use PHPUnit\Framework\TestCase;

final class ChannelClassifierTest extends TestCase
{
    /** @dataProvider mediumMapProvider */
    public function test_medium_map_takes_precedence(string $medium, string $expected): void
    {
        $this->assertSame($expected, ChannelClassifier::classify([
            'utm_source' => 'anything',
            'utm_medium' => $medium,
        ]));
    }

    /** @return array<string,array{0:string,1:string}> */
    public static function mediumMapProvider(): array
    {
        return [
            'email medium'        => ['email', 'email'],
            'newsletter medium'   => ['newsletter', 'email'],
            'social medium'       => ['social', 'social'],
            'organic-social'      => ['organic-social', 'social'],
            'paid-social'         => ['paid-social', 'paid-social'],
            'paidsocial'          => ['paidsocial', 'paid-social'],
            'organic medium'      => ['organic', 'organic'],
            'search medium'       => ['search', 'organic'],
            'cpc medium'          => ['cpc', 'cpc'],
            'ppc medium'          => ['ppc', 'cpc'],
            'paid-search'         => ['paid-search', 'cpc'],
            'referral medium'     => ['referral', 'referral'],
            'qr medium'           => ['qr', 'qr'],
            'qr-code medium'      => ['qr-code', 'qr'],
            'event medium'        => ['event', 'qr'],
            'peer medium'         => ['peer', 'peer'],
            'p2p medium'          => ['p2p', 'peer'],
            'fundraiser medium'   => ['fundraiser', 'peer'],
        ];
    }

    /** @dataProvider socialSourceProvider */
    public function test_social_source_fallback(string $source): void
    {
        $this->assertSame('social', ChannelClassifier::classify([
            'utm_source' => $source,
            'utm_medium' => '',
        ]));
    }

    /** @return array<string,array{0:string}> */
    public static function socialSourceProvider(): array
    {
        return [
            'facebook'  => ['facebook'],
            'fb'        => ['fb'],
            'instagram' => ['instagram'],
            'twitter'   => ['twitter'],
            'x'         => ['x'],
            'linkedin'  => ['linkedin'],
            'tiktok'    => ['tiktok'],
        ];
    }

    /** @dataProvider searchSourceProvider */
    public function test_search_source_maps_to_organic(string $source): void
    {
        $this->assertSame('organic', ChannelClassifier::classify([
            'utm_source' => $source,
            'utm_medium' => '',
        ]));
    }

    /** @return array<string,array{0:string}> */
    public static function searchSourceProvider(): array
    {
        return [
            'google'     => ['google'],
            'bing'       => ['bing'],
            'duckduckgo' => ['duckduckgo'],
        ];
    }

    public function test_email_keyword_in_source(): void
    {
        $this->assertSame('email', ChannelClassifier::classify([
            'utm_source' => 'mailchimp-campaign-123',
            'utm_medium' => '',
        ]));
    }

    public function test_empty_attribution_is_direct(): void
    {
        $this->assertSame('direct', ChannelClassifier::classify([]));
        $this->assertSame('direct', ChannelClassifier::classify([
            'utm_source' => '',
            'utm_medium' => '',
        ]));
    }

    public function test_unknown_source_falls_to_referral(): void
    {
        $this->assertSame('referral', ChannelClassifier::classify([
            'utm_source' => 'partner-site',
            'utm_medium' => '',
        ]));
    }

    public function test_case_insensitive(): void
    {
        $this->assertSame('email', ChannelClassifier::classify([
            'utm_source' => '',
            'utm_medium' => 'EMAIL',
        ]));
        $this->assertSame('social', ChannelClassifier::classify([
            'utm_source' => 'Facebook',
            'utm_medium' => '',
        ]));
    }

    public function test_medium_takes_precedence_over_source(): void
    {
        $this->assertSame('cpc', ChannelClassifier::classify([
            'utm_source' => 'facebook',
            'utm_medium' => 'cpc',
        ]));
    }
}
