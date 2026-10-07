<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use WP_UnitTestCase;

/**
 * A suite that reaches the network tests somebody else's server. A request no
 * test has stubbed is answered by the rig, with an error.
 */
final class NoTestReachesAnOutsideHostTest extends WP_UnitTestCase
{
    // The discard port on this machine: closed, so a rig that let the request through would still reach nobody.
    private const NOWHERE = 'http://127.0.0.1:9/';

    public function test_a_request_nothing_has_stubbed_is_answered_by_the_rig(): void
    {
        $answer = wp_remote_get(self::NOWHERE);

        $this->assertWPError($answer);
        $this->assertSame('gratora_tests_offline', $answer->get_error_code());
    }

    public function test_a_request_a_test_has_stubbed_still_gets_its_stub(): void
    {
        add_filter('pre_http_request', static fn () => [
            'headers'  => [],
            'body'     => 'stubbed',
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies'  => [],
            'filename' => null,
        ]);

        $this->assertSame('stubbed', wp_remote_retrieve_body(wp_remote_get(self::NOWHERE)));
    }
}
