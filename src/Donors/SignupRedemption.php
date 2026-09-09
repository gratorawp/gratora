<?php

declare(strict_types=1);

namespace Gratora\Donors;

use Gratora\Vendor\Queryable\DB;

/**
 * Turns a proven claim into a donor. The emailed link coming back is the only
 * evidence the address belongs to whoever typed it.
 *
 * @since 1.0.0
 */
final class SignupRedemption
{
    /**
     * Kept apart from the sign-in purpose so a sign-in link can never
     * materialize an account and a signup link can never open somebody else's.
     */
    public const PURPOSE = 'donor_portal_signup';

    /** @since 1.0.0 */
    public function __construct(
        private MagicLinkService $magicLinks,
        private PendingSignupRepository $pending,
        private DonorService $donors,
    ) {
    }

    /**
     * One transaction. Consuming the token is an atomic conditional update, so
     * it decides who wins a race, but if the donor cannot then be created the
     * link has to stay usable: a spent token with no account behind it is a
     * person locked out of an account they never got.
     *
     * @since 1.0.0
     */
    public function redeem(string $rawToken): int
    {
        return (int) DB::transaction(function () use ($rawToken): int {
            $token = $this->magicLinks->consumeAndValidate($rawToken, self::PURPOSE);
            if (! $token) return 0;

            $claim = $this->pending->findById((int) $token->target_id);
            if (! $claim || ! $this->pending->isLive($claim)) return 0;

            $email = (string) ($this->pending->decryptEmail($claim) ?? '');
            if ($email === '' || ! is_email($email)) return 0;

            // Take the name from the signed token, not the shared claim. Apply it only within
            // donor creation so another registration cannot rename an existing or concurrently
            // created donor.
            $profile = [];
            foreach (['first_name', 'last_name'] as $field) {
                if (($token->$field ?? null) !== null && $token->$field !== '') {
                    $profile[$field] = $token->$field;
                }
            }

            $donor = $this->donors->findOrCreate($email, $profile, false, true);

            // Erasure is a decision, not a lapsed state, and only a genuine new
            // donation reverses it, so somebody erased who signs up again is
            // refused rather than quietly rebuilt. Asking the donor we ended up
            // with, not the one read a moment ago, means an erasure landing in
            // between is still honoured. The claim stays for its TTL; nothing
            // above it was written.
            if (($donor->redacted_at ?? null) !== null) {
                return 0;
            }

            $this->pending->delete((int) $claim->id);

            return (int) $donor->id;
        });
    }
}
