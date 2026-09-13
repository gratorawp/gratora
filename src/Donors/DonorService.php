<?php

declare(strict_types=1);

namespace Gratora\Donors;

use Gratora\Analytics\ErrorLog;
use Gratora\Analytics\EventRecorder;
use Gratora\Donations\Donation;
use Gratora\Donations\DonationDeleter;
use Gratora\Donations\DonationTrasher;
use Gratora\Donors\Erasure\ErasureRegistry;
use Gratora\Donors\Erasure\ErasureRequest;
use Gratora\Foundation\Crypto\Crypto;
use Gratora\Foundation\Identity\IdentityHasher;
use Gratora\Foundation\Plugin;
use Gratora\Foundation\Time\Clock;
use Gratora\Recurring\GatewayUnreachable;
use Gratora\Recurring\RecurringCanceller;
use Gratora\Recurring\RecurringPlan;
use Gratora\Recurring\RecurringPlanRepository;
use Gratora\Vendor\Queryable\DB;
use InvalidArgumentException;
use Throwable;
use Gratora\Analytics\DonationAudit;

/** @since 1.0.0 */
final class DonorService
{
    /**
     * Ids one search may resolve to. The result goes into a single IN() list,
     * and a two-letter term typed into a box that queries on every keystroke
     * matches most of a large donor table, so the compiled statement runs to
     * megabytes.
     *
     * @since 1.0.0
     */
    public const SEARCH_MATCH_CAP = 1000;

    /** Words a name search will take before it stops adding LIKE pairs. */
    private const SEARCH_WORD_CAP = 4;

    /** @since 1.0.0 */
    public function __construct(
        private DonorRepository $donors,
        private IdentityHasher $hasher,
        private Crypto $crypto,
        private Clock $clock,
        private ErasureRegistry $erasure,
        private DonorPurge $purge,
    ) {
    }

    /**
     * @param array{
     *     first_name?: ?string,
     *     last_name?: ?string,
     *     country?: ?string,
     *     locale?: ?string,
     *     company?: ?string,
     *     donor_type?: 'individual'|'organization'|'household',
     *     phone?: ?string,
     *     address?: array<string,mixed>|null,
     * } $profile
     *
     * @param bool $profileOnlyOnCreate Apply $profile to a donor this call
     *     creates and to no other. A caller that decided the profile was safe
     *     by looking the address up first is deciding on a read that this
     *     method repeats: between the two, a donation can create the donor, and
     *     back-filling then writes a name onto somebody the caller never meant
     *     to touch.
     *
     * @since 1.0.0
     */
    public function findOrCreate(
        string $email,
        array $profile = [],
        bool $reactivateIfRedacted = false,
        bool $profileOnlyOnCreate = false,
    ): Donor {
        $email = $this->hasher->normalizeEmail($email);
        $hash  = $this->hasher->emailHash($email);

        $existing = $this->donors->findByEmailHash($hash);
        if ($existing !== null) {
            // One donor per email. Only a genuine new donation re-activates a
            // redacted donor. A bare lookup, such as an unauthenticated portal
            // link request, must leave the erased row untouched: never
            // un-redact it and never re-populate PII through refreshProfile.
            if ($existing->redacted_at !== null) {
                if (! $reactivateIfRedacted) {
                    return $existing;
                }
                $existing->updateColumns([
                    'email_encrypted' => $this->crypto->encrypt($email),
                    'redacted_at'     => null,
                    'updated_at'      => $this->clock->now()->format('Y-m-d H:i:s'),
                ]);
            }
            return $this->refreshProfile($existing, $profileOnlyOnCreate ? [] : $profile);
        }

        $now = $this->clock->now()->format('Y-m-d H:i:s');

        $donor = Donor::make();
        $donor->email_hash          = $hash;
        $donor->email_encrypted     = $this->crypto->encrypt($email);
        $donor->first_name          = $profile['first_name']  ?? null;
        $donor->last_name           = $profile['last_name']   ?? null;
        $donor->country             = isset($profile['country']) ? strtoupper(substr((string) $profile['country'], 0, 2)) : null;
        $donor->locale              = $profile['locale']      ?? null;
        $donor->company             = $profile['company']     ?? null;
        $donor->donor_type          = $profile['donor_type']  ?? 'individual';
        if (! empty($profile['phone'])) {
            $donor->phone_encrypted = $this->crypto->encrypt((string) $profile['phone']);
        }
        $addressJson = $this->addressPayload($profile['address'] ?? null);
        if ($addressJson !== null) {
            $donor->address_encrypted = $this->crypto->encrypt($addressJson);
        }
        $donor->total_donated_cents = 0;
        $donor->donations_count     = 0;
        $donor->created_at          = $now;
        $donor->updated_at          = $now;

        $donor->save();

        do_action('gratora.donor.created', $donor);

        return $donor;
    }

    /** @since 1.0.0 */
    /** @since 1.0.0 */
    public function findById(int $id): ?Donor
    {
        return $this->donors->findById($id);
    }

    /** @since 1.0.0 */
    public function findByEmail(string $email): ?Donor
    {
        return $this->donors->findByEmailHash(
            $this->hasher->emailHash($this->hasher->normalizeEmail($email))
        );
    }

    /**
     * Donor-initiated portal edit: overwrites any field present in the patch,
     * unlike refreshProfile's lock-on-first-write back-fill. Empty string
     * clears to null; absent keys are untouched.
     *
     * @param array{first_name?:?string,last_name?:?string,country?:?string,company?:?string,locale?:?string,phone?:?string,address?:array<string,mixed>|null} $patch
     *
     * @since 1.0.0
     */
    public function editProfile(Donor $donor, array $patch): Donor
    {
        if ($donor->redacted_at !== null) {
            throw new InvalidArgumentException(esc_html__('This donor has been erased and can no longer be edited.', 'gratora-donation-platform'));
        }
        // A value of the wrong type is not an edit to that field, and coercing
        // one overwrites what the site holds. For phone and address the
        // encrypted column is the only copy, so the coercion destroys it.
        foreach (['first_name', 'last_name', 'company', 'locale', 'country', 'phone'] as $f) {
            if (array_key_exists($f, $patch) && $patch[$f] !== null && ! is_string($patch[$f])) {
                throw new InvalidArgumentException(esc_html__('Give every profile field as text.', 'gratora-donation-platform'));
            }
        }

        if (array_key_exists('address', $patch) && $patch['address'] !== null && ! is_array($patch['address'])) {
            throw new InvalidArgumentException(esc_html__('Give the address as a set of fields.', 'gratora-donation-platform'));
        }

        $dirty = [];
        $textFields = ['first_name' => 100, 'last_name' => 100, 'company' => 150, 'locale' => 10];

        foreach ($textFields as $f => $maxLen) {
            if (! array_key_exists($f, $patch)) continue;
            $value = $patch[$f];
            $value = $value === null ? null : trim((string) $value);
            if ($value === '') $value = null;
            if ($value !== null && $maxLen) $value = mb_substr($value, 0, $maxLen);
            if (($donor->$f ?? null) === $value) continue;
            $dirty[$f] = $value;
        }

        if (array_key_exists('country', $patch)) {
            $raw = $patch['country'];
            $value = $raw === null || $raw === '' ? null : strtoupper(substr((string) $raw, 0, 2));
            if (($donor->country ?? null) !== $value) {
                $dirty['country'] = $value;
            }
        }

        if (array_key_exists('phone', $patch)) {
            $raw     = trim((string) $patch['phone']);
            $current = $this->decryptPhone($donor) ?? '';
            if ($raw !== $current) {
                $dirty['phone_encrypted'] = $raw === '' ? null : $this->crypto->encrypt($raw);
            }
        }

        if (array_key_exists('address', $patch)) {
            $addr       = $patch['address'];
            $newPayload = $this->addressPayload($addr);
            $current    = $donor->address_encrypted ? $this->crypto->decrypt($donor->address_encrypted) : null;
            if ($newPayload !== $current) {
                $dirty['address_encrypted'] = ($newPayload === null || $newPayload === '')
                    ? null : $this->crypto->encrypt($newPayload);
            }
        }

        // One UPDATE of the fields that moved, so an unchanged phone or address
        // costs no second write and fires no donor.updated on a no-op, and the
        // lifetime giving aggregates are left to whoever owns them.
        if ($dirty !== []) {
            $dirty['updated_at'] = $this->clock->now()->format('Y-m-d H:i:s');
            $donor->updateColumns($dirty);
            do_action('gratora.donor.updated', $donor);
        }

        return $donor;
    }

    /**
     * Back-fills only empty profile fields from the donation payload.
     *
     * An erased donor is rejected: erasure nulls every field this fills, and
     * redact() early-returns on an already-redacted row, so a back-fill here
     * would restore PII nothing could wipe again.
     *
     * @since 1.0.0
     */
    public function refreshProfile(Donor $donor, array $profile): Donor
    {
        if ($donor->redacted_at !== null) {
            throw new InvalidArgumentException(esc_html__('This donor has been erased and can no longer be edited.', 'gratora-donation-platform'));
        }

        $changed = false;
        $fields = ['first_name', 'last_name', 'country', 'locale', 'company'];

        foreach ($fields as $f) {
            if (! array_key_exists($f, $profile)) continue;
            $value = $profile[$f];
            if ($value === null || $value === '') continue;
            if (! empty($donor->$f)) continue;

            if ($f === 'country') {
                $value = strtoupper(substr((string) $value, 0, 2));
            }
            $donor->$f = $value;
            $changed = true;
        }

        if (! empty($profile['phone']) && empty($donor->phone_encrypted)) {
            $donor->phone_encrypted = $this->crypto->encrypt((string) $profile['phone']);
            $changed = true;
        }

        if (empty($donor->address_encrypted)) {
            $addressJson = $this->addressPayload($profile['address'] ?? null);
            if ($addressJson !== null) {
                $donor->address_encrypted = $this->crypto->encrypt($addressJson);
                $changed = true;
            }
        }

        if ($changed) {
            $donor->updated_at = $this->clock->now()->format('Y-m-d H:i:s');
            $donor->updateColumns([
                'first_name'        => $donor->first_name,
                'last_name'         => $donor->last_name,
                'country'           => $donor->country,
                'locale'            => $donor->locale,
                'company'           => $donor->company,
                'phone_encrypted'   => $donor->phone_encrypted,
                'address_encrypted' => $donor->address_encrypted,
                'updated_at'        => $donor->updated_at,
            ]);
            do_action('gratora.donor.updated', $donor);
        }

        return $donor;
    }

    /** @since 1.0.0 */
    public function changeEmail(Donor $donor, string $newEmail): Donor
    {
        if ($donor->redacted_at !== null) {
            throw new InvalidArgumentException(esc_html__('This donor has been erased and can no longer be edited.', 'gratora-donation-platform'));
        }
        $normalized = $this->hasher->normalizeEmail($newEmail);
        if ($normalized === '') {
            throw new InvalidArgumentException(esc_html__('Email is required.', 'gratora-donation-platform'));
        }

        $newHash = $this->hasher->emailHash($normalized);
        if ($newHash === $donor->email_hash) {
            return $donor;
        }

        $clash = $this->donors->findByEmailHash($newHash);
        if ($clash !== null && (int) $clash->id !== (int) $donor->id) {
            throw new EmailAlreadyAssignedException((int) $clash->id);
        }

        $oldHash = $donor->email_hash;
        $donor->updateColumns([
            'email_hash'      => $newHash,
            'email_encrypted' => $this->crypto->encrypt($normalized),
            'updated_at'      => $this->clock->now()->format('Y-m-d H:i:s'),
        ]);

        do_action('gratora.donor.email_changed', $donor, [
            'old_hash' => $oldHash,
            'new_hash' => $newHash,
        ]);
        do_action('gratora.donor.updated', $donor);

        return $donor;
    }

    /**
     * Why this donor cannot be deleted, or null when they can be.
     *
     * Deletion is for a record that should not have existed, not the erasure
     * path. A donor who gave keeps their row: the donation is a financial
     * record that has to survive, and erasure is how that person is forgotten.
     * Add-ons veto through the filter, because core cannot know what they hang
     * off a donor.
     *
     * @since 1.0.0
     */
    public function undeletableReason(Donor $donor): ?string
    {
        return $this->undeletableReasons([$donor])[(int) $donor->id] ?? null;
    }

    /**
     * The same answer for a whole page of donors, in two queries rather than
     * two per row, so a list can offer Delete only where it would be allowed.
     *
     * @param list<Donor> $donors
     * @return array<int,?string> donor id => reason, null when deletable
     *
     * @since 1.0.0
     */
    public function undeletableReasons(array $donors): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn (Donor $d): int => (int) $d->id, $donors),
        )));

        // Asked of the rules that own the answer, not re-derived here. Whether
        // the donation took money is not among them: the delete closes the
        // payment at the processor and takes the amount back out of every
        // total, so what is left refusing is a receipt somebody can ask for
        // and a signup whose row is the only handle on the mandate.
        //
        // The donations are loaded rather than reduced to SQL because the
        // add-on veto in those rules is a filter, and a donation an add-on
        // still needs has to refuse the donor here, before the payments are
        // closed, rather than inside the transaction afterwards.
        $byDonor = [];
        if ($ids !== []) {
            $rules     = Plugin::instance()->container->get(DonationTrasher::class);
            $donations = Donation::query()->whereIn('donor_id', $ids)->getAll();
            $reasons   = $rules->undeletableReasons($donations);

            foreach ($donations as $donation) {
                $donorId = (int) $donation->donor_id;
                $reason  = $reasons[(int) $donation->id] ?? null;
                if ($reason !== null && ! isset($byDonor[$donorId])) {
                    // Composed rather than rewritten: the donation rules own
                    // the way out of their own refusal, and this screen has a
                    // second one they know nothing about. Erasing keeps the
                    // record and takes the person out of it, which is what an
                    // operator refused here usually wanted.
                    $byDonor[$donorId] = sprintf(
                        /* translators: %s: why one of this donor's donations cannot be deleted. */
                        __('One of this donor\'s donations has to be kept. %s Erase the donor instead to remove their details and leave the record standing.', 'gratora-donation-platform'),
                        $reason
                    );
                }
            }
        }

        $out = [];
        foreach ($donors as $donor) {
            $id = (int) $donor->id;

            if (isset($byDonor[$id])) {
                $out[$id] = $byDonor[$id];
                continue;
            }

            $vetoed  = apply_filters('gratora.donor.undeletable_reason', null, $donor);
            $out[$id] = is_string($vetoed) && $vetoed !== '' ? $vetoed : null;
        }

        return $out;
    }

    /**
     * @throws InvalidArgumentException when the donor must be kept.
     * @throws GatewayUnreachable when a mandate of theirs cannot be stopped.
     *
     * @since 1.0.0
     */
    public function delete(Donor $donor): void
    {
        $reason = $this->undeletableReason($donor);
        if ($reason !== null) {
            throw new InvalidArgumentException(esc_html($reason));
        }

        $id   = (int) $donor->id;
        $hash = (string) $donor->email_hash;

        // Built while the PII is still readable. The needle scan over payload
        // is the only thing that reaches an abandoned checkout's email, and an
        // abandoned checkout is what this delete releases.
        $request = $this->erasureRequest($donor);
        $dids    = $request->donationIds;

        // Read before the row goes, because the column is the only pointer to
        // the file: a picture the donor uploaded sits on a public URL, and
        // nothing else in the site knows it belonged to them.
        $avatarAttachmentId = (int) ($donor->avatar_attachment_id ?? 0);

        // Out here, before anything is locked: closing a payment can mean a
        // call to the gateway, and a request that times out would otherwise
        // hold this donor's rows for as long as it takes to give up. A row
        // that refuses to close refuses the whole delete, which is the answer
        // either way.
        //
        // Resolved from the container rather than held, because the gateway
        // registry it reaches is built from services that reach back here.
        // First, because a plan row is the only handle that can stop the
        // billing: taking it without asking the processor leaves the card
        // charged every month with nothing here able to reach it. A processor
        // that will not answer refuses the whole delete.
        $this->stopRecurringBefore($donor, __('The donor was deleted.', 'gratora-donation-platform'), true);

        $deleter   = Plugin::instance()->container->get(DonationDeleter::class);
        $donations = $dids === [] ? [] : Donation::query()->whereIn('id', $dids)->getAll();
        foreach ($donations as $donation) {
            $deleter->closeFor($donation);
        }

        DB::transaction(function () use ($donor, $id, $hash, $dids, $request, $deleter, $donations): void {
            if ($dids !== []) {
                // A receipt that still stands is a document somebody can ask
                // for, so it holds the donor. A voided one does not: the refund
                // that voided it is the record, and the delete names the number
                // on its audit row. Asked the same way the donation rule asks
                // it, or a donation deletable on its own would have a donor who
                // could never go.
                if (DB::table('gratora_receipts')->whereIn('donation_id', $dids)->where('voided', 0)->count() > 0) {
                    throw new InvalidArgumentException(esc_html__('This donor has a receipt that still stands. Refund the donation first, which withdraws the receipt, or erase the donor instead.', 'gratora-donation-platform'));
                }

                // Before anything is destroyed, so an add-on clears what it
                // hangs off these donations. A listener that throws aborts.
                do_action('gratora.test_data.purge_donations', $dids);
            }

            // Run every erasure handler before deleting rows, within the transaction, so
            // add-ons can resolve and remove dependent data.
            $this->erasure->run($request);

            // Everything the donor left behind except the record of the
            // destructive acts themselves. The wildcard is written out because
            // the compiler wraps a bare value in its own.
            DB::table('gratora_events')
                ->where('type', 'donor.%', 'NOT LIKE')
                ->whereNotIn('type', DonationAudit::TYPES)
                ->where('donor_id', $id)
                ->delete();

            if ($dids !== []) {
                DB::table('gratora_events')
                    ->where('type', 'donor.%', 'NOT LIKE')
                    ->whereNotIn('type', DonationAudit::TYPES)
                    ->whereIn('donation_id', $dids)
                    ->delete();

                DB::table('gratora_donation_notes')->whereIn('donation_id', $dids)->delete();
                DB::table('gratora_refunds')->whereIn('donation_id', $dids)->delete();
            }

            // Stopped at the processor above, so what goes here is the record
            // of a mandate that is no longer billing anything. The events go
            // by plan as well as by donor: a renewal event carries the plan it
            // renewed, and the donor column on it is not guaranteed.
            $planIds = array_map('intval', RecurringPlan::query()->where('donor_id', $id)->pluck('id'));
            if ($planIds !== []) {
                DB::table('gratora_events')
                    ->where('type', 'donor.%', 'NOT LIKE')
                    ->whereNotIn('type', DonationAudit::TYPES)
                    ->whereIn('recurring_plan_id', $planIds)
                    ->delete();
                RecurringPlan::query()->where('donor_id', $id)->delete();
            }

            Consent::query()->where('donor_id', $id)->delete();
            DonorNote::query()->where('donor_id', $id)->delete();
            MagicLinkToken::query()->where('donor_id', $id)->delete();

            // Keyed by address, not by donor, so it is reached by hash or not
            // at all. A claim left behind would still carry a live link, and
            // its tokens carry the name the signup typed.
            if ($hash !== '') {
                foreach (PendingSignup::query()->where('email_hash', $hash)->getAll() as $claim) {
                    PendingSignupRepository::deleteSignupTokensFor((int) $claim->id);
                }
                PendingSignup::query()->where('email_hash', $hash)->delete();
            }

            // Per row, through the one service that knows what a donation drags
            // with it: its consents, its notes, its non-audit events and the
            // retry marker on any parent it superseded. The payment is already
            // closed and the batch already announced, so neither happens again.
            foreach ($donations as $donation) {
                $deleter->delete($donation, null, false, true);
            }

            $this->events()->record('donor.deleted', [
                'donor_id' => $id,
                'payload'  => [
                    'by'                => self::actorKind(),
                    'actor_name'        => self::actorName(),
                    'was_redacted'      => $donor->redacted_at !== null,
                    'donations_deleted' => count($dids),
                    'plans_stopped'     => count($planIds),
                ],
            ]);

            Donor::query()->where('id', $id)->delete();

            // After the row is gone, so a listener cannot resurrect it by
            // writing something that references a donor which no longer exists.
            do_action('gratora.donor.deleted', $id, $hash);
        });

        // After the commit: file deletion cannot be rolled back, so a delete
        // that fails must not have already destroyed the picture.
        if ($avatarAttachmentId > 0) {
            wp_delete_attachment($avatarAttachmentId, true);
        }
    }

    /** @since 1.0.0 */
    public function redact(Donor $donor, string $actor = ''): Donor
    {
        if ($donor->redacted_at !== null) {
            return $donor;
        }

        // Before anything is destroyed: erasing first strands the mandate, so
        // the plan keeps billing and every renewal writes the donor's name and
        // email back into the webhook log.
        $who = self::actor($actor);

        $this->stopRecurringBefore($donor, __('The donor asked for their data to be erased.', 'gratora-donation-platform'));

        $request = $this->erasureRequest($donor);

        // Captured before the column is cleared so the file can go once the
        // transaction has actually committed. A picture the donor uploaded is
        // their data, and it sits on a public URL until it is deleted.
        $avatarAttachmentId = (int) ($donor->avatar_attachment_id ?? 0);

        $donor->email_encrypted    = '';
        $donor->avatar_attachment_id = null;
        $donor->first_name         = null;
        $donor->last_name          = null;
        $donor->address_encrypted  = null;
        $donor->phone_encrypted    = null;
        $donor->tax_id_encrypted   = null;
        $donor->notes_encrypted    = null;
        $donor->company            = null;
        $donor->country            = null;
        $donor->redacted_at        = $this->clock->now()->format('Y-m-d H:i:s');
        $donor->updated_at         = $donor->redacted_at;

        DB::transaction(function () use ($donor, $request, $who) {
            $donor->updateColumns([
                'email_encrypted'      => $donor->email_encrypted,
                'avatar_attachment_id' => $donor->avatar_attachment_id,
                'first_name'           => $donor->first_name,
                'last_name'            => $donor->last_name,
                'address_encrypted'    => $donor->address_encrypted,
                'phone_encrypted'      => $donor->phone_encrypted,
                'tax_id_encrypted'     => $donor->tax_id_encrypted,
                'notes_encrypted'      => $donor->notes_encrypted,
                'company'              => $donor->company,
                'country'              => $donor->country,
                'redacted_at'          => $donor->redacted_at,
                'updated_at'           => $donor->updated_at,
            ]);

            // Inside this transaction: a handler that cannot finish its part
            // rolls the whole thing back rather than leaving the donor marked
            // erased when only some of their data went.
            $this->erasure->run($request);

            // A zero retention window leaves no grace period in which a
            // returning donor is reunited with this record, so the handle goes
            // now rather than on tomorrow's sweep.
            if ($this->purge->purgesOnRedaction()) {
                $this->purge->purge($donor);
            }

            $this->events()->record('donor.redacted', [
                'donor_id' => (int) $donor->id,
                'payload'  => [
                    'by'                  => $who['by'],
                    'actor_name'          => $who['actor_name'],
                    'donations_retained'  => count($request->donationIds),
                ],
            ]);
        });

        // After the commit: file deletion cannot be rolled back, so a failed
        // erasure must not have already destroyed the picture.
        if ($avatarAttachmentId > 0) {
            wp_delete_attachment($avatarAttachmentId, true);
        }

        return $donor;
    }

    /**
     * Anything that cannot be stopped aborts the erasure rather than completing
     * it and losing the handles needed to stop it later.
     *
     * Resolved from the container rather than injected: RecurringCanceller
     * reaches DonationService, which reaches back here.
     *
     * @throws GatewayUnreachable when the processor holding a mandate cannot
     *                            be reached, so nothing here can stop it.
     *
     * @since 1.0.0
     */
    private function stopRecurringBefore(Donor $donor, string $reason, bool $everyPlan = false): void
    {
        $query = RecurringPlan::query()->where('donor_id', (int) $donor->id);

        // A delete takes the row, so it asks about every plan the donor has.
        // A local 'cancelled' is not proof the mandate is dead: an importer
        // writes it over statuses it has no state for, and cancelSubscription
        // is idempotent per its contract, so the cost of asking twice is a
        // round trip and the cost of not asking is a card still being charged.
        if (! $everyPlan) {
            $query->whereIn('status', RecurringPlanRepository::CANCELLABLE_STATUSES);
        }

        $plans = $query->getAll();

        if ($plans === []) {
            return;
        }

        $canceller = \Gratora\Foundation\Plugin::instance()->container->get(RecurringCanceller::class);

        $cancelled = [];

        foreach ($plans as $plan) {
            try {
                $canceller->cancel($plan, $reason);
                $cancelled[] = (int) $plan->id;
            } catch (Throwable $e) {
                // The erasure stops here, so the caller has to be told which
                // plans are already stopped and which one still bills.
                ErrorLog::record(
                    'donor.erasure.recurring',
                    sprintf(
                        'Plan %d could not be cancelled, so the request was abandoned: %s',
                        (int) $plan->id,
                        $e->getMessage()
                    ),
                    [
                        'donor_id'          => (int) $donor->id,
                        'recurring_plan_id' => (int) $plan->id,
                        'cancelled_first'   => $cancelled,
                    ]
                );

                throw $e;
            }
        }
    }

    /**
     * Everything that identifies this donor, read while it is still readable.
     * Gateway ids are in here because a webhook body has no donor_id: `pi_...`
     * or `cus_...` is the only thread back to the person it describes.
     *
     * @since 1.0.0
     */
    /**
     * Resolved from the container rather than injected: EventRecorder needs
     * SettingsService, which is not bound yet where this service is first
     * resolved during boot.
     *
     * @since 1.0.0
     */
    private function events(): EventRecorder
    {
        return Plugin::instance()->container->get(EventRecorder::class);
    }

    /**
     * Who acted, named by the caller where it knows.
     *
     * The sweep runs on whatever request happens to trip cron, so inferring it
     * put a staff member's name on an erasure nobody performed.
     *
     * @return array{by:string,actor_name:string}
     */
    private static function actor(string $forced = ''): array
    {
        return $forced !== ''
            ? ['by' => $forced, 'actor_name' => '']
            : ['by' => self::actorKind(), 'actor_name' => self::actorName()];
    }

    /**
     * Who acted, in a form that survives the user row being deleted later.
     *
     * The fallback for a caller that does not name itself: the portal's forget
     * route acts as the donor, and WP-CLI as itself.
     *
     * @since 1.0.0
     */
    private static function actorKind(): string
    {
        if (defined('WP_CLI') && WP_CLI) return 'cli';
        if (wp_doing_cron()) return 'retention';

        return get_current_user_id() > 0 ? 'admin' : 'donor';
    }

    /** @since 1.0.0 */
    private static function actorName(): string
    {
        $user = wp_get_current_user();

        return $user && $user->ID > 0 ? (string) $user->display_name : '';
    }

    private function erasureRequest(Donor $donor): ErasureRequest
    {
        $donations = Donation::query()->where('donor_id', $donor->id)->getAll();
        $plans     = RecurringPlan::query()->where('donor_id', $donor->id)->getAll();

        // Unique by construction, so they are safe to search loose text for as
        // substrings.
        $identifiers = [
            $this->decryptEmail($donor),
            $this->decrypt($donor->phone_encrypted),
            $this->decrypt($donor->tax_id_encrypted),
        ];

        // Free text. Bare first and last names are excluded: they are
        // substrings of other people's data (see ErasureRequest::make).
        $names = [
            trim((string) $donor->first_name . ' ' . (string) $donor->last_name),
            $donor->company,
        ];

        $donationIds = [];
        $columns     = ['reference' => [], 'gateway_intent_id' => [], 'gateway_txn_id' => []];
        foreach ($donations as $d) {
            $donationIds[] = (int) $d->id;
            foreach (array_keys($columns) as $column) {
                $columns[$column][] = (string) ($d->{$column} ?? '');
            }
        }

        // A reference is unique, not substring-unique: DON-1 is inside DON-10,
        // and these are searched for as loose text. Asked per column, because a
        // gateway's own id is opaque and has no such neighbour, while one a
        // sandbox derived from the reference has the same one.
        foreach ($columns as $column => $values) {
            foreach ($this->prefixUnique($column, $values, $donationIds) as $value) {
                $identifiers[] = $value;
            }
        }
        foreach ($plans as $p) {
            $identifiers[] = $p->gateway_subscription_id;
            $identifiers[] = $p->gateway_customer_id;
        }

        return ErasureRequest::make(
            (int) $donor->id,
            $donationIds,
            $identifiers,
            $names,
            $this->clock->now()->format('Y-m-d H:i:s'),
            (string) $donor->email_hash,
        );
    }

    /**
     * The values in one column that no other donation's value extends.
     *
     * @param list<string> $values
     * @param list<int>    $donationIds
     * @return list<string>
     */
    private function prefixUnique(string $column, array $values, array $donationIds): array
    {
        $values = array_values(array_unique(array_filter($values, static fn ($v): bool => (string) $v !== '')));
        if ($values === []) {
            return [];
        }

        $patterns = array_map(
            static fn (string $v): string => str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $v) . '%',
            $values
        );

        $rows = Donation::query()
            ->whereNotIn('id', $donationIds ?: [0])
            ->where(static function ($q) use ($column, $patterns): void {
                $first = array_shift($patterns);
                $q->whereLike($column, $first);
                foreach ($patterns as $pattern) {
                    $q->orWhereLike($column, $pattern);
                }
            })
            ->pluck($column);

        $extended = array_map('strval', (array) $rows);

        return array_values(array_filter(
            $values,
            static function (string $v) use ($extended): bool {
                foreach ($extended as $other) {
                    if ($other !== $v && str_starts_with($other, $v)) {
                        return false;
                    }
                }
                return true;
            }
        ));
    }

    /** @since 1.0.0 */
    private function decrypt(?string $value): ?string
    {
        if ($value === null || $value === '') return null;
        try {
            return $this->crypto->decrypt($value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Authorized contexts only; never from public APIs.
     *
     * @since 1.0.0
     */
    /**
     * Reunite an erased donor with their record, because they gave again.
     *
     * The retention window exists for exactly this, and erasure keeps the
     * handle so the record can still be found. What it does not do is decide
     * on its own: the caller has to have watched money move, because the
     * address alone proves nothing about who typed it.
     *
     * Idempotent, and silent on a donor who is not erased, so a replayed
     * settlement cannot make it mean anything twice.
     *
     * @since 1.0.0
     */
    public function reactivateRedacted(Donor $donor, string $email): bool
    {
        if ($donor->redacted_at === null || trim($email) === '') {
            return false;
        }

        // The address has to be the one this record answers to. A settlement
        // carrying some other address would otherwise rewrite whose record it
        // is, and the handle is what the whole reunification hangs on.
        if (! hash_equals((string) $donor->email_hash, $this->hasher->emailHash($this->hasher->normalizeEmail($email)))) {
            return false;
        }

        $donor->updateColumns([
            'email_encrypted' => $this->crypto->encrypt($email),
            'redacted_at'     => null,
            'updated_at'      => $this->clock->now()->format('Y-m-d H:i:s'),
        ]);

        return true;
    }

    /** @since 1.0.0 */
    public function decryptEmail(Donor $donor): ?string
    {
        if ($donor->redacted_at !== null || $donor->email_encrypted === '') {
            return null;
        }
        return $this->crypto->decrypt($donor->email_encrypted);
    }

    /**
     * Same authorization contract as decryptEmail.
     *
     * @since 1.0.0
     */
    public function decryptPhone(Donor $donor): ?string
    {
        if ($donor->redacted_at !== null || ! $donor->phone_encrypted) {
            return null;
        }
        return $this->crypto->decrypt($donor->phone_encrypted);
    }

    /**
     * The raw struct as stored; decryptAddress() gives the joined display
     * string.
     *
     * @return array{line1?:string,line2?:string,city?:string,region?:string,postal?:string,country?:string}|null
     *
     * @since 1.0.0
     */
    public function decryptAddressStruct(Donor $donor): ?array
    {
        if ($donor->redacted_at !== null || ! $donor->address_encrypted) return null;
        $raw = $this->crypto->decrypt($donor->address_encrypted);
        if ($raw === null || $raw === '') return null;
        $decoded = json_decode($raw, true);
        return is_array($decoded) && $decoded !== [] ? $decoded : null;
    }

    /**
     * Same authorization contract as decryptEmail.
     *
     * @since 1.0.0
     */
    public function decryptAddress(Donor $donor): ?string
    {
        if ($donor->redacted_at !== null || ! $donor->address_encrypted) {
            return null;
        }
        $raw = $this->crypto->decrypt($donor->address_encrypted);
        if ($raw === null || $raw === '') return null;

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) return null;

        $lines = [];
        if (! empty($decoded['line1'])) $lines[] = (string) $decoded['line1'];
        if (! empty($decoded['line2'])) $lines[] = (string) $decoded['line2'];
        $city         = trim((string) ($decoded['city']   ?? ''));
        $regionPostal = trim(
            trim((string) ($decoded['region'] ?? ''))
            . ' '
            . trim((string) ($decoded['postal'] ?? ''))
        );
        if ($city !== '' && $regionPostal !== '') {
            $lines[] = $city . ', ' . $regionPostal;
        } elseif ($city !== '') {
            $lines[] = $city;
        } elseif ($regionPostal !== '') {
            $lines[] = $regionPostal;
        }
        if (! empty($decoded['country'])) $lines[] = (string) $decoded['country'];

        return $lines === [] ? null : implode("\n", $lines);
    }

    /** @since 1.0.0 */
    public function addressPayload(?array $address): ?string
    {
        if (! is_array($address)) return null;
        $out = [];
        foreach (['line1', 'line2', 'city', 'region', 'postal', 'country'] as $k) {
            $v = trim((string) ($address[$k] ?? ''));
            if ($v === '') continue;
            $out[$k] = $k === 'country' ? strtoupper(substr($v, 0, 2)) : $v;
        }
        return $out === [] ? null : (string) wp_json_encode($out);
    }

    /** @since 1.0.0 */
    public function setEncryptedField(Donor $donor, string $field, ?string $value): void
    {
        if ($donor->redacted_at !== null) {
            throw new InvalidArgumentException(esc_html__('This donor has been erased and can no longer be edited.', 'gratora-donation-platform'));
        }
        if (! in_array($field, ['phone_encrypted', 'address_encrypted', 'notes_encrypted', 'tax_id_encrypted'], true)) {
            throw new InvalidArgumentException(esc_html("Unsupported encrypted field: {$field}"));
        }
        $encrypted = ($value === null || $value === '') ? null : $this->crypto->encrypt($value);
        DB::table('gratora_donors')
            ->where('id', $donor->id)
            ->update([$field => $encrypted, 'updated_at' => $this->clock->now()->format('Y-m-d H:i:s')]);
        $donor->$field = $encrypted ?? '';
    }

    /**
     * Name uses LIKE; email is an exact hash match.
     *
     * @since 1.0.0
     */
    public function findIdsBySearch(string $term): array
    {
        $term = trim($term);
        if ($term === '') return [];

        $hash = $this->hasher->emailHash($term);

        // Ids, not donors: hydrating a model per match just to read its id
        // costs far more time and memory than the id-only query.
        $rows = DB::table('gratora_donors')
            ->selectRaw('id')
            ->where(function ($q) use ($term, $hash): void {
                $q->whereLike('first_name', $term)
                  ->orWhereLike('last_name', $term)
                  ->orWhere('email_hash', $hash);

                // A name is two fields and the screens print it as one, so the
                // whole of what is on a row never matches either half. Every
                // word has to land on one name or the other, which keeps the
                // order free and makes typing more narrow the result rather
                // than widen it. Bounded because each word is another pair of
                // LIKEs, and nobody searches by five.
                $words = preg_split('/\s+/', $term, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                if (count($words) > 1 && count($words) <= self::SEARCH_WORD_CAP) {
                    $q->orWhere(function ($all) use ($words): void {
                        foreach ($words as $word) {
                            $all->where(function ($either) use ($word): void {
                                $either->whereLike('first_name', $word)
                                       ->orWhereLike('last_name', $word);
                            });
                        }
                    });
                }

                if (ctype_digit($term)) {
                    $q->orWhere('id', (int) $term);
                }
            })
            // Ordered, not just capped: the callers page over these ids, and an
            // unordered truncation would make page 2 disagree with page 1.
            ->orderBy('id', 'ASC')
            ->limit(self::SEARCH_MATCH_CAP)
            ->getAll();

        return array_map(static fn ($r): int => (int) (is_array($r) ? $r['id'] : $r->id), $rows);
    }
}
