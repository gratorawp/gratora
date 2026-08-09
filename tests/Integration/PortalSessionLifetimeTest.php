<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donors\DonorService;
use Dono\Donors\Portal\PortalSession;
use Dono\Foundation\Plugin;

/**
 * Two clocks. Idle kills a session left open on a borrowed device; the absolute
 * cap bounds a stolen cookie no matter how often it is used.
 */
final class PortalSessionLifetimeTest extends IntegrationTestCase
{
    private const COOKIE = 'dono_donor_session';

    private function session(): PortalSession
    {
        return Plugin::instance()->container->get(PortalSession::class);
    }

    private function donorId(string $tag): int
    {
        return (int) Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate("portal-life-{$tag}-" . uniqid() . '@example.test')->id;
    }

    protected function tearDown(): void
    {
        unset($_COOKIE[self::COOKIE]);
        parent::tearDown();
    }

    public function test_a_fresh_session_resolves(): void
    {
        $id = $this->donorId('fresh');
        $_COOKIE[self::COOKIE] = $this->portalSession($id);

        $this->assertSame($id, $this->session()->currentDonorId());
    }

    public function test_a_session_idle_past_the_window_is_gone(): void
    {
        $id  = $this->donorId('idle');
        $sid = $this->portalSession($id);
        $_COOKIE[self::COOKIE] = $sid;

        delete_transient('dono_portal_' . hash('sha256', $sid));

        $this->assertNull($this->session()->currentDonorId());
    }

    public function test_a_session_older_than_the_cap_is_refused_however_active(): void
    {
        $id  = $this->donorId('capped');
        $sid = $this->portalSession($id, 'tok', time() - (8 * DAY_IN_SECONDS));
        $_COOKIE[self::COOKIE] = $sid;

        $this->assertNull($this->session()->currentDonorId(), 'past the absolute cap');
        $this->assertFalse(
            get_transient('dono_portal_' . hash('sha256', $sid)),
            'and the expired session is cleared rather than left to be read again'
        );
    }

    public function test_a_session_just_inside_the_cap_still_resolves(): void
    {
        $id  = $this->donorId('inside');
        $_COOKIE[self::COOKIE] = $this->portalSession($id, 'tok', time() - (6 * DAY_IN_SECONDS));

        $this->assertSame($id, $this->session()->currentDonorId());
    }

    /** A donor with several devices can end them all at once. */
    public function test_signing_out_everywhere_ends_every_session(): void
    {
        $id = $this->donorId('everywhere');

        $first  = $this->session()->open($id);
        $second = $this->session()->open($id);
        $this->assertNotSame($first['csrf'], $second['csrf']);

        $ended = $this->session()->destroyAllFor($id);
        $this->assertSame(2, $ended);

        $_COOKIE[self::COOKIE] = 'deadbeef';
        $this->assertNull($this->session()->currentDonorId());
    }

    /** Otherwise every sign-in leaves another live key behind for its full life. */
    public function test_sessions_beyond_the_cap_per_donor_are_revoked_oldest_first(): void
    {
        $id = $this->donorId('cap-per-donor');

        for ($i = 0; $i < 7; $i++) {
            $this->session()->open($id);
        }

        $this->assertSame(5, $this->session()->destroyAllFor($id), 'the two oldest were dropped');
    }

    public function test_one_donors_sign_out_does_not_touch_another_donor(): void
    {
        $mine   = $this->donorId('mine');
        $theirs = $this->donorId('theirs');

        $this->session()->open($mine);
        $this->session()->open($theirs);

        $this->session()->destroyAllFor($mine);

        $this->assertSame(1, $this->session()->destroyAllFor($theirs));
    }

    public function test_it_reports_how_long_ago_the_link_was_redeemed(): void
    {
        $id = $this->donorId('stepup');
        $_COOKIE[self::COOKIE] = $this->portalSession($id, 'tok', time() - 600);

        $this->assertEqualsWithDelta(600, $this->session()->authenticatedSecondsAgo(), 5);
    }

    public function test_the_route_ends_every_session_and_refuses_without_one(): void
    {
        $id = $this->donorId('route');

        $opened = $this->session()->open($id);
        $this->session()->open($id);
        $_COOKIE[self::COOKIE] = $this->portalSession($id, $opened['csrf']);

        $req = new \WP_REST_Request('POST', '/dono/v1/portal/logout-everywhere');
        $req->set_header('X-Dono-Csrf', $opened['csrf']);
        $res = rest_do_request($req);

        $this->assertSame(200, $res->get_status());
        $this->assertGreaterThan(0, ((array) $res->get_data())['ended']);

        unset($_COOKIE[self::COOKIE]);
        $this->assertSame(
            403,
            rest_do_request(new \WP_REST_Request('POST', '/dono/v1/portal/logout-everywhere'))->get_status(),
            'the permission callback refuses before the route runs'
        );
    }

    public function test_activity_slides_the_idle_window_forward(): void
    {
        $id  = $this->donorId('sliding');
        $sid = $this->portalSession($id);
        $key = 'dono_portal_' . hash('sha256', $sid);

        $stored = get_transient($key);
        $stored['seen'] = time() - 400;
        set_transient($key, $stored, 60);

        $_COOKIE[self::COOKIE] = $sid;
        $this->assertSame($id, $this->session()->currentDonorId());

        $this->assertGreaterThan(
            time() + 600,
            (int) get_option('_transient_timeout_' . $key),
            'reading an active session pushes its expiry out'
        );
    }
}
