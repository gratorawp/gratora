<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donors\DonorService;
use Dono\Foundation\Plugin;
use WP_REST_Request;

/**
 * Erasure revokes the donor's magic-link tokens so no emailed link can open a
 * session. A cookie minted before the erasure has to stop working too, or the
 * revocation is only half done.
 */
final class PortalSessionAfterErasureTest extends IntegrationTestCase
{
    /** Endpoints that read the session and return the donor's own data. */
    private const AUTHENTICATED = [
        '/dono/v1/portal/donations',
        '/dono/v1/portal/recurring',
        '/dono/v1/portal/receipts',
        '/dono/v1/portal/preferences',
        '/dono/v1/portal/me',
        '/dono/v1/portal/profile',
    ];

    protected function tearDown(): void
    {
        unset($_COOKIE['dono_donor_session']);
        parent::tearDown();
    }

    private function status(string $route): int
    {
        return rest_do_request(new WP_REST_Request('GET', $route))->get_status();
    }

    /**
     * Put a live session in place.
     *
     * PortalSession::open() sets its cookie with setcookie(), which is a no-op
     * once headers are sent, so the session it creates is unreachable from a
     * test. Same transient shape, and $_COOKIE set directly.
     */
    private function signedInDonor(): object
    {
        $donor = Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate('portal-' . uniqid() . '@example.test', ['first_name' => 'Sam']);

        $sid = bin2hex(random_bytes(32));
        set_transient(
            'dono_portal_' . hash('sha256', $sid),
            ['donor_id' => (int) $donor->id, 'csrf' => bin2hex(random_bytes(8))],
            3600
        );
        $_COOKIE['dono_donor_session'] = $sid;

        return $donor;
    }

    public function test_a_live_session_reaches_every_endpoint(): void
    {
        $this->signedInDonor();

        foreach (self::AUTHENTICATED as $route) {
            $this->assertNotContains(
                $this->status($route),
                [401, 403],
                "{$route} should be reachable with a live session"
            );
        }
    }

    public function test_erasure_closes_the_session_everywhere(): void
    {
        $donor = $this->signedInDonor();

        Plugin::instance()->container->get(DonorService::class)->redact($donor);

        foreach (self::AUTHENTICATED as $route) {
            $this->assertSame(
                401,
                $this->status($route),
                "{$route} still answered a session belonging to an erased donor"
            );
        }
    }
}
