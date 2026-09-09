<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Donors\MagicLinkToken;
use Gratora\Donors\PendingSignup;
use Gratora\Donors\PendingSignupRepository;
use Gratora\Donors\SignupRedemption;
use Gratora\Foundation\Plugin;

/**
 * The volume here is decided by unauthenticated callers: one row per address
 * behind a per-IP quota. Hydrating the whole expired set and issuing one DELETE
 * per claim died mid-pass on a busy week, so the next night started on a larger
 * set and died sooner, and every survivor kept a redeemable signup token
 * carrying the name its registration typed.
 */
final class SignupGcDrainsTest extends IntegrationTestCase
{
    private function expiredClaim(): int
    {
        $past = gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS);

        $c = PendingSignup::make();
        $c->email_hash      = 'gc-' . uniqid();
        $c->email_encrypted = 'x';
        $c->expires_at      = $past;
        $c->created_at      = $past;
        $c->save();

        $t = MagicLinkToken::make();
        $t->purpose    = SignupRedemption::PURPOSE;
        $t->target_id  = (int) $c->id;
        $t->token_hash = 'hash-' . uniqid();
        $t->expires_at = $past;
        $t->created_at = $past;
        $t->save();

        return (int) $c->id;
    }

    private function repo(): PendingSignupRepository
    {
        return Plugin::instance()->container->get(PendingSignupRepository::class);
    }

    public function test_one_pass_is_bounded(): void
    {
        for ($i = 0; $i < 7; $i++) {
            $this->expiredClaim();
        }

        $this->assertSame(3, $this->repo()->purgeExpired(3));
        $this->assertSame(4, PendingSignup::query()->count(), 'the rest wait for the next pass');
    }

    /** A token that outlives its claim is still redeemable. */
    public function test_the_tokens_go_with_the_claims(): void
    {
        $id = $this->expiredClaim();

        $this->repo()->purgeExpired(10);

        $this->assertSame(
            0,
            MagicLinkToken::query()->where('purpose', SignupRedemption::PURPOSE)->where('target_id', $id)->count()
        );
    }

    public function test_an_empty_sweep_costs_nothing(): void
    {
        $this->assertSame(0, $this->repo()->purgeExpired(10));
    }
}
