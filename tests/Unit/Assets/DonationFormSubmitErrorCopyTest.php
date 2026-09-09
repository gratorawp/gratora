<?php

declare(strict_types=1);

namespace Gratora\Tests\Unit\Assets;

use PHPUnit\Framework\TestCase;

/**
 * When a submit fails in transit the donor sees whatever the banner is given.
 * A JavaScript engine's own wording ("Failed to fetch", or a parse error
 * quoting the first bytes of a proxy's HTML block page) is untranslated and
 * says nothing a donor can act on, at the one moment they are trying to give
 * money. Only the server's own message, or the form's curated copy, belongs
 * there.
 *
 * @since 1.0.0
 */
final class DonationFormSubmitErrorCopyTest extends TestCase
{
    private function source(string $file): string
    {
        $path = dirname(__DIR__, 3) . '/assets/donation-form/' . $file;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function test_the_submit_banner_never_shows_an_exception_message(): void
    {
        $src = $this->source('runtime.jsx');

        $this->assertMatchesRegularExpression(
            '/SUBMIT_ERROR/',
            $src,
            'the submit path still reports failures through this action.'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/message:\s*err\??\.?\??\.message/',
            $src,
            'an engine exception message is not donor-facing copy.'
        );
    }

    /**
     * The response is read before the status is checked, so a proxy answering
     * with HTML would throw past every curated message on the way down.
     */
    public function test_an_unparsable_response_body_does_not_throw(): void
    {
        $src = $this->source('runtime.jsx');

        $this->assertMatchesRegularExpression(
            '/res\.json\(\)\.catch\(/',
            $src,
            'reading the body has to tolerate a response that is not JSON.'
        );
    }

    /**
     * PayPal's helper throws the server's curated message on a refusal, which
     * is right to show. A dropped connection rejects before that, with the
     * engine's own wording, and the same banner renders it.
     *
     * Counted rather than matched once: the helper sends twice, retrying
     * without a stale nonce, and a rejection on either send reaches the same
     * banner. Pinning one occurrence let the second arrive uncurated.
     */
    public function test_every_paypal_send_curates_a_transport_failure(): void
    {
        $src = $this->source('components/PayPalPayment.jsx');

        $sends   = preg_match_all('/await send\(/', $src);
        $curated = preg_match_all(
            '/\.catch\(\s*\(\)\s*=>\s*\{\s*throw new Error\(\s*i18n\.error\s*\)/',
            $src
        );

        $this->assertGreaterThan(0, $sends, 'the helper has to make a request at all');
        $this->assertSame(
            $sends,
            $curated,
            'a failed fetch must become the curated string before any caller reads .message.'
        );
    }
}
