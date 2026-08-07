<?php

declare(strict_types=1);

namespace Dono\Donors;

use Dono\Vendor\Queryable\DB;

/**
 * Turns a proven claim into a donor. The emailed link coming back is the only
 * evidence the address belongs to whoever typed it.
 */
final class SignupRedemption
{
    /**
     * Kept apart from the sign-in purpose so a sign-in link can never
     * materialise an account and a signup link can never open somebody else's.
     */
    public const PURPOSE = 'donor_portal_signup';

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

            $existing = $this->donors->findByEmail($email);

            // Erasure is a decision, not a lapsed state, and only a genuine new
            // donation reverses it, so somebody erased who signs up again is
            // refused rather than quietly rebuilt.
            if ($existing !== null && $existing->redacted_at !== null) {
                return 0;
            }

            // The names on the claim reach a donor this call is creating, and
            // no other. Anyone can type anyone's address, so a claim can still
            // be standing when its owner becomes a donor by donating, and
            // back-filling then would print a stranger's name on that person's
            // receipts and year-end statement.
            $profile = [];
            if ($existing === null) {
                foreach (['first_name', 'last_name'] as $field) {
                    if (($claim->$field ?? null) !== null && $claim->$field !== '') {
                        $profile[$field] = $claim->$field;
                    }
                }
            }

            $donor = $this->donors->findOrCreate($email, $profile);
            $this->pending->delete((int) $claim->id);

            return (int) $donor->id;
        });
    }
}
