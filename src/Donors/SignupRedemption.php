<?php

declare(strict_types=1);

namespace Dono\Donors;

use Dono\Vendor\Queryable\DB;

/**
 * Turns a proven claim into a donor.
 *
 * Signing up in the portal records an address nobody has proven. This is the
 * other half: the emailed link comes back, which is the only evidence the
 * address belongs to whoever typed it, and the donor is created at that moment.
 *
 * @version 1.0.0
 */
final class SignupRedemption
{
    /**
     * A link that creates a donor, as opposed to one that signs an existing
     * donor in. Kept apart so a sign-in link can never materialise an account
     * and a signup link can never open somebody else's.
     */
    public const PURPOSE = 'donor_portal_signup';

    public function __construct(
        private MagicLinkService $magicLinks,
        private PendingSignupRepository $pending,
        private DonorService $donors,
    ) {
    }

    /**
     * Consumes the link and returns the donor it created, or 0 when the link
     * is not one of ours, is spent, or has expired.
     *
     * The whole thing is one transaction. Consuming the token is an atomic
     * conditional update, so it is the step that decides who wins a race, but
     * if the donor could not then be created the link has to stay usable: a
     * spent token with no account behind it is a person locked out of an
     * account they never got.
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

            // Somebody erased who signs up again. Erasure is a decision, not a
            // lapsed state, and only a genuine new donation reverses it, so
            // this refuses rather than quietly rebuilding the record. Opening a
            // session instead would hand them a portal that 401s on its first
            // request.
            if ($existing !== null && $existing->redacted_at !== null) {
                return 0;
            }

            // The names on the claim reach a donor this call is creating, and
            // no other. Anyone can type anyone's address, so a claim can be
            // standing when its owner becomes a donor by donating; redeeming it
            // afterwards would otherwise hand findOrCreate a name to back-fill
            // onto a record the claimant never owned, and it would print on
            // that person's receipts and year-end statement.
            //
            // A second signup for the same address overwrites the names on the
            // shared claim, so an older link still in a mailbox creates the
            // donor with whatever was typed most recently.
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
