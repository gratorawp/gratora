<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Foundation\Http\ClientIp;
use Gratora\Settings\SettingsService;
use Gratora\Foundation\Plugin;
use InvalidArgumentException;

/**
 * The panel counts what is stored, so one misspelt entry turned the "you look
 * proxied" warning off. The resolver keeps only what it can parse, so it kept
 * reading the proxy's own address and every visitor shared one spam bucket.
 * Nothing said the value had not been understood.
 */
final class TrustedProxyIsUnderstoodTest extends IntegrationTestCase
{
    private function settings(): SettingsService
    {
        return Plugin::instance()->container->get(SettingsService::class);
    }

    public function test_a_misspelt_provider_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->settings()->update('privacy', ['trusted_proxies' => ['cloudfare']]);
    }

    public function test_several_ranges_pasted_on_one_line_are_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->settings()->update('privacy', ['trusted_proxies' => ['10.0.0.0/8, 192.168.0.0/16']]);
    }

    public function test_what_the_resolver_keeps_is_what_the_panel_stores(): void
    {
        $this->settings()->update('privacy', ['trusted_proxies' => ['cloudflare', '10.0.0.0/8']]);

        $stored = (array) (get_option('gratora_privacy', [])['trusted_proxies'] ?? []);

        foreach ($stored as $entry) {
            $this->assertTrue(ClientIp::understands((string) $entry), $entry . ' was stored but is not understood');
        }
        $this->assertNotSame([], $stored);
    }

    /** An empty line is how the field is cleared, not a value to refuse. */
    public function test_blank_entries_are_not_refused(): void
    {
        $this->settings()->update('privacy', ['trusted_proxies' => ['', '   ']]);

        $this->assertTrue(true);
    }
}
