<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\AntiSpamGuard;
use Dono\Foundation\Config\SystemSetting;
use Dono\Foundation\Plugin;

/**
 * Full-codebase QA Batch 3: the anti-spam form token is a coarse day bucket so
 * a page-cached donation form keeps validating instead of 403-ing once the old
 * 2h render-timestamp TTL elapsed.
 */
final class FormTokenCacheTest extends IntegrationTestCase
{
    private function guard(): AntiSpamGuard
    {
        return Plugin::instance()->container->get(AntiSpamGuard::class);
    }

    /** Forge a validly-signed token dated $days in the past. */
    private function tokenForDaysAgo(int $days): string
    {
        $this->guard()->mintFormToken(); // ensures the signing secret exists
        $secret = (string) SystemSetting::read('form_signing_secret_v1');
        $bucket = (int) floor((time() - $days * DAY_IN_SECONDS) / DAY_IN_SECONDS);
        // Tokens are bound to the form id (0 here, matching a no-arg verify).
        return $bucket . '.' . hash_hmac('sha256', '0|' . $bucket, $secret);
    }

    public function test_freshly_minted_token_validates(): void
    {
        $this->assertNull($this->guard()->verifyFormToken($this->guard()->mintFormToken()));
    }

    public function test_cached_form_within_window_still_validates(): void
    {
        // A donation form cached 20 days ago must still submit.
        $this->assertNull($this->guard()->verifyFormToken($this->tokenForDaysAgo(20)));
    }

    public function test_token_older_than_window_is_rejected(): void
    {
        $this->assertNotNull($this->guard()->verifyFormToken($this->tokenForDaysAgo(40)));
    }

    public function test_future_or_tampered_token_is_rejected(): void
    {
        $this->assertNotNull($this->guard()->verifyFormToken($this->tokenForDaysAgo(-2)), 'future bucket');
        $this->assertNotNull($this->guard()->verifyFormToken('99999999.deadbeef'), 'bad signature');
        $this->assertNotNull($this->guard()->verifyFormToken('not-a-token'), 'malformed');
    }

    public function test_token_minted_for_one_form_is_rejected_on_another(): void
    {
        $token = $this->guard()->mintFormToken(5);
        $this->assertNull($this->guard()->verifyFormToken($token, 5), 'valid for its own form');
        $this->assertNotNull($this->guard()->verifyFormToken($token, 6), 'rejected when replayed on a different form');
    }
}
