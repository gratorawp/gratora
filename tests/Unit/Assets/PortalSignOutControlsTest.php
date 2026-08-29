<?php

declare(strict_types=1);

namespace GiveFlow\Tests\Unit\Assets;

use PHPUnit\Framework\TestCase;

/**
 * An emailed sign-in link is a bearer credential, and the only thing that
 * reaches one nobody opened is /portal/logout-everywhere. A route with no
 * control in front of it is a capability the donor does not have, which the
 * integration tests cannot see because they call the route directly.
 *
 * Signing out is one control now. Two buttons a few pixels apart asked the
 * donor to choose a session scope, and the narrower one left other devices
 * signed in, which is the answer nobody wants from a button labelled Sign out.
 *
 * @since 1.0.0
 */
final class PortalSignOutControlsTest extends TestCase
{
    private function source(): string
    {
        $path = dirname(__DIR__, 3) . '/assets/donor-portal/index.jsx';
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function test_signing_out_ends_every_session(): void
    {
        $this->assertMatchesRegularExpression(
            "/api\(\s*'logout-everywhere'/",
            $this->source(),
            'the one way out reaches every session and every unopened link'
        );
    }

    public function test_there_is_no_second_way_out_that_leaves_devices_signed_in(): void
    {
        $this->assertDoesNotMatchRegularExpression(
            "/api\(\s*'logout'\s*[,)]/",
            $this->source(),
            'the single-session route is gone; a control calling it would be reinstating the narrower answer'
        );
    }
}
