<?php

declare(strict_types=1);

namespace Dono\Tests\Unit\Assets;

use PHPUnit\Framework\TestCase;

/**
 * An emailed sign-in link is a bearer credential, and the only thing that
 * reaches one nobody opened is /portal/logout-everywhere. A route with no
 * control in front of it is a capability the donor does not have, which the
 * integration tests cannot see because they call the route directly.
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

    public function test_the_donor_can_reach_both_ways_out(): void
    {
        $src = $this->source();

        $this->assertMatchesRegularExpression(
            "/api\(\s*'logout'/",
            $src,
            'ending this session is a control'
        );
        $this->assertMatchesRegularExpression(
            "/api\(\s*'logout-everywhere'/",
            $src,
            'and so is ending every session and every unopened link'
        );
    }
}
