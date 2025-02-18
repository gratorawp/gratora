<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donors\DonorService;
use Dono\Donors\MagicLinkService;
use Dono\Donors\Portal\PortalSession;
use Dono\Foundation\Plugin;

/**
 * Magic-link tokens are the donor portal's only auth path - a regression in
 * the redeem side silently locks every donor out of receipts, recurring,
 * and (with p2p) their fundraising pages.
 *
 * PortalSignInLinkTest already covers the "send" side (async job emails a
 * link). This locks the "redeem" side:
 *
 *  - issue + consume roundtrip works
 *  - consume is SINGLE-USE (the security-critical property)
 *  - tokens minted for one purpose don't unlock another
 *  - expired tokens don't redeem
 *  - PortalSession::startFromToken inherits the single-use guarantee
 *    end-to-end (a reused link can never start a second session)
 */
final class MagicLinkRedemptionTest extends IntegrationTestCase
{
    public function test_issued_token_can_be_consumed_exactly_once(): void
    {
        $svc   = $this->magicLinks();
        $donor = $this->makeDonor();

        $raw = $svc->issue((int) $donor->id, 'donor_portal');

        $first = $svc->consumeAndValidate($raw, 'donor_portal');
        $this->assertNotNull($first, 'first consume validates and returns the token');
        $this->assertSame((int) $donor->id, (int) $first->donor_id);
        $this->assertNotEmpty($first->used_at, 'consume stamps used_at atomically with the validate');

        $second = $svc->consumeAndValidate($raw, 'donor_portal');
        $this->assertNull($second, 'second consume of the same token returns null - single-use enforced');
    }

    public function test_consume_rejects_token_minted_for_a_different_purpose(): void
    {
        $svc   = $this->magicLinks();
        $donor = $this->makeDonor();

        // A token minted for "donor_portal" should NOT unlock a "download_receipt"
        // flow even if an attacker captures the raw value.
        $raw = $svc->issue((int) $donor->id, 'donor_portal');

        $crossPurpose = $svc->consumeAndValidate($raw, 'download_receipt');
        $this->assertNull($crossPurpose, 'token minted for one purpose cannot be redeemed against another');

        // And the original purpose still redeems cleanly (cross-purpose miss
        // didn't accidentally consume the row).
        $same = $svc->consumeAndValidate($raw, 'donor_portal');
        $this->assertNotNull($same, 'a failed cross-purpose attempt leaves the token redeemable for its real purpose');
    }

    public function test_consume_rejects_expired_token(): void
    {
        $svc   = $this->magicLinks();
        $donor = $this->makeDonor();

        // Negative TTL produces a row whose expires_at is in the past.
        $raw = $svc->issue((int) $donor->id, 'donor_portal', null, -1);

        $this->assertNull(
            $svc->consumeAndValidate($raw, 'donor_portal'),
            'expired tokens never redeem'
        );
    }

    public function test_target_scoped_token_only_redeems_for_its_target(): void
    {
        $svc   = $this->magicLinks();
        $donor = $this->makeDonor();

        // Receipt-download tokens carry a target_id (the receipt row). A token
        // for receipt #42 must not unlock receipt #43.
        $raw = $svc->issue((int) $donor->id, 'download_receipt', 42, 3600);

        $this->assertNull(
            $svc->consumeAndValidate($raw, 'download_receipt', 43),
            'target-scoped token does not redeem for a different target'
        );

        $this->assertNotNull(
            $svc->consumeAndValidate($raw, 'download_receipt', 42),
            'target-scoped token redeems for its own target'
        );
    }

    public function test_portal_session_startFromToken_is_single_use(): void
    {
        $svc      = $this->magicLinks();
        $sessions = Plugin::instance()->container->get(PortalSession::class);
        $donor    = $this->makeDonor();

        $raw = $svc->issue((int) $donor->id, 'donor_portal');

        $session1 = $sessions->startFromToken($raw);
        $this->assertIsArray($session1, 'first redemption opens a session');
        $this->assertSame((int) $donor->id, (int) $session1['donor_id']);
        $this->assertNotEmpty($session1['csrf'], 'session carries a CSRF token');

        $session2 = $sessions->startFromToken($raw);
        $this->assertNull(
            $session2,
            'reusing the same magic link cannot open a second session - the link is dead after first click'
        );
    }

    private function magicLinks(): MagicLinkService
    {
        return Plugin::instance()->container->get(MagicLinkService::class);
    }

    private function makeDonor(): \Dono\Donors\Donor
    {
        return Plugin::instance()->container
            ->get(DonorService::class)
            ->findOrCreate('magic-' . uniqid() . '@example.test', [
                'first_name' => 'Magic',
                'last_name'  => 'Test',
            ]);
    }
}
