<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\AntiSpamGuard;
use Dono\Donors\Portal\PortalShortcode;
use Dono\Foundation\Plugin;
use ReflectionMethod;

/**
 * Which host the portal client talks to. rest_url() answers with home_url's
 * host, so on an install that serves the portal page on both the apex and www,
 * the fetch from the other one is cross-origin: the browser drops the session
 * cookie the exchange sets, and the donor's single-use link is spent on a
 * session that never holds. Nothing at the REST layer can see that, because
 * rest_do_request has no cookie jar.
 */
final class PortalRestBaseTest extends IntegrationTestCase
{
    private function shortcode(): PortalShortcode
    {
        return new PortalShortcode(Plugin::instance()->container->get(AntiSpamGuard::class));
    }

    private function base(string $restUrl, string $servedFrom): string
    {
        $previous = $_SERVER['HTTP_HOST'] ?? null;
        $_SERVER['HTTP_HOST'] = $servedFrom;

        try {
            $method = new ReflectionMethod(PortalShortcode::class, 'restBase');
            $method->setAccessible(true);

            return (string) $method->invoke($this->shortcode(), $restUrl);
        } finally {
            if ($previous === null) {
                unset($_SERVER['HTTP_HOST']);
            } else {
                $_SERVER['HTTP_HOST'] = $previous;
            }
        }
    }

    /** The install the www/apex pairing exists for, in both directions. */
    public function test_the_client_talks_to_the_host_the_page_was_served_from(): void
    {
        $this->assertSame(
            'https://www.example.org/wp-json/dono/v1/portal/',
            $this->base('https://example.org/wp-json/dono/v1/portal/', 'www.example.org'),
            'a page served on www does not fetch from the apex'
        );

        $this->assertSame(
            'https://example.org/wp-json/dono/v1/portal/',
            $this->base('https://www.example.org/wp-json/dono/v1/portal/', 'example.org'),
            'and an install canonical on www does not fetch www from the apex'
        );

        $this->assertSame(
            'https://example.org:8443/?rest_route=/dono/v1/portal/',
            $this->base('https://example.org:8443/?rest_route=/dono/v1/portal/', 'example.org:8443'),
            'the port and the plain-permalink query survive'
        );
    }

    /**
     * Host is a header the caller writes and this page is cached, so one
     * poisoned request must not be able to leave a REST base of the attacker's
     * choosing in the cache for everybody after it.
     */
    public function test_a_host_header_cannot_point_the_client_anywhere_else(): void
    {
        foreach (['evil.test', 'example.org.evil.test', 'blog.example.org', 'www.blog.example.org'] as $host) {
            $this->assertSame(
                'https://example.org/wp-json/dono/v1/portal/',
                $this->base('https://example.org/wp-json/dono/v1/portal/', $host),
                $host . ' is not this site'
            );
        }
    }

    /** And the page hands the client that base rather than rest_url's. */
    public function test_the_page_hands_the_client_that_base(): void
    {
        if (! file_exists(DONO_DIR . 'build/donor-portal/index/index.asset.php')) {
            $this->markTestSkipped('the portal bundle is not built in this checkout');
        }

        $previous = $_SERVER['HTTP_HOST'] ?? null;
        $_SERVER['HTTP_HOST'] = 'www.' . (string) wp_parse_url(home_url(), PHP_URL_HOST);
        $GLOBALS['wp_scripts'] = null;

        try {
            $this->shortcode()->render();
            $data = (string) wp_scripts()->get_data('dono-donor-portal', 'data');
        } finally {
            $GLOBALS['wp_scripts'] = null;
            if ($previous === null) {
                unset($_SERVER['HTTP_HOST']);
            } else {
                $_SERVER['HTTP_HOST'] = $previous;
            }
        }

        // Permalink shape is the install's business; the host is this test's.
        $this->assertMatchesRegularExpression(
            '#"rest":"https?://www\.' . preg_quote((string) wp_parse_url(home_url(), PHP_URL_HOST), '#') . '/#',
            $data,
            'the localized REST base names the host the page was served from'
        );
    }
}
