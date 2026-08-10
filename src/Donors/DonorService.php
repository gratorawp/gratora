<?php

declare(strict_types=1);

namespace Dono\Donors;

use Dono\Donations\Donation;
use Dono\Donors\Erasure\ErasureRegistry;
use Dono\Donors\Erasure\ErasureRequest;
use Dono\Recurring\RecurringCanceller;
use Dono\Recurring\RecurringPlan;
use Dono\Foundation\Crypto\Crypto;
use Dono\Foundation\Identity\IdentityHasher;
use Dono\Foundation\Time\Clock;
use InvalidArgumentException;
use Throwable;
use Dono\Vendor\Queryable\DB;

/**
 * Donor writes: creation, profile edits, email changes, deletion and erasure.
 *
 * @since 1.0.0
 */
final class DonorService
{
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
     * } $profile
     *
     * @since 1.0.0
     */
    public function findOrCreate(string $email, array $profile = [], bool $reactivateIfRedacted = false): Donor
    {
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
                $existing->email_encrypted = $this->crypto->encrypt($email);
                $existing->redacted_at     = null;
                $existing->updated_at      = $this->clock->now()->format('Y-m-d H:i:s');
                $existing->save();
            }
            return $this->refreshProfile($existing, $profile);
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

        do_action('dono.donor.created', $donor);

        return $donor;
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
            throw new InvalidArgumentException(__('This donor has been erased and can no longer be edited.', 'dono'));
        }
        $changed = false;
        $textFields = ['first_name' => 100, 'last_name' => 100, 'company' => 150, 'locale' => 10];

        foreach ($textFields as $f => $maxLen) {
            if (! array_key_exists($f, $patch)) continue;
            $value = $patch[$f];
            $value = $value === null ? null : trim((string) $value);
            if ($value === '') $value = null;
            if ($value !== null && $maxLen) $value = substr($value, 0, $maxLen);
            if (($donor->$f ?? null) === $value) continue;
            $donor->$f = $value;
            $changed = true;
        }

        if (array_key_exists('country', $patch)) {
            $raw = $patch['country'];
            $value = $raw === null || $raw === '' ? null : strtoupper(substr((string) $raw, 0, 2));
            if (($donor->country ?? null) !== $value) {
                $donor->country = $value;
                $changed = true;
            }
        }

        if (array_key_exists('phone', $patch)) {
            $raw     = is_string($patch['phone']) ? trim($patch['phone']) : '';
            $current = $this->decryptPhone($donor) ?? '';
            if ($raw !== $current) {
                $donor->phone_encrypted = $raw === '' ? null : $this->crypto->encrypt($raw);
                $changed = true;
            }
        }

        if (array_key_exists('address', $patch)) {
            $addr       = is_array($patch['address']) ? $patch['address'] : null;
            $newPayload = $this->addressPayload($addr);
            $current    = $donor->address_encrypted ? $this->crypto->decrypt($donor->address_encrypted) : null;
            if ($newPayload !== $current) {
                $donor->address_encrypted = ($newPayload === null || $newPayload === '')
                    ? null : $this->crypto->encrypt($newPayload);
                $changed = true;
            }
        }

        // The encrypted fields are set on the model above and persisted by this
        // single save(), so an unchanged phone or address costs no second
        // UPDATE and fires no donor.updated on a no-op.
        if ($changed) {
            $donor->updated_at = $this->clock->now()->format('Y-m-d H:i:s');
            $donor->save();
            do_action('dono.donor.updated', $donor);
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
            throw new InvalidArgumentException(__('This donor has been erased and can no longer be edited.', 'dono'));
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
            $donor->save();
            do_action('dono.donor.updated', $donor);
        }

        return $donor;
    }

    /** @since 1.0.0 */
    public function changeEmail(Donor $donor, string $newEmail): Donor
    {
        if ($donor->redacted_at !== null) {
            throw new InvalidArgumentException(__('This donor has been erased and can no longer be edited.', 'dono'));
        }
        $normalized = $this->hasher->normalizeEmail($newEmail);
        if ($normalized === '') {
            throw new InvalidArgumentException(__('Email is required.', 'dono'));
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
        $donor->email_hash      = $newHash;
        $donor->email_encrypted = $this->crypto->encrypt($normalized);
        $donor->updated_at      = $this->clock->now()->format('Y-m-d H:i:s');
        $donor->save();

        do_action('dono.donor.email_changed', $donor, [
            'old_hash' => $oldHash,
            'new_hash' => $newHash,
        ]);
        do_action('dono.donor.updated', $donor);

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
        if (Donation::query()->where('donor_id', (int) $donor->id)->exists()) {
            return __('This donor has donations on record, which have to be kept. Erase them instead.', 'dono');
        }

        if (RecurringPlan::query()->where('donor_id', (int) $donor->id)->exists()) {
            return __('This donor has a recurring plan. Cancel it first.', 'dono');
        }

        $vetoed = apply_filters('dono.donor.undeletable_reason', null, $donor);

        return is_string($vetoed) && $vetoed !== '' ? $vetoed : null;
    }

    /**
     * @throws InvalidArgumentException when the donor must be kept.
     *
     * @since 1.0.0
     */
    public function delete(Donor $donor): void
    {
        $reason = $this->undeletableReason($donor);
        if ($reason !== null) {
            throw new InvalidArgumentException($reason);
        }

        $id   = (int) $donor->id;
        $hash = (string) $donor->email_hash;

        DB::transaction(function () use ($donor, $id, $hash): void {
            Consent::query()->where('donor_id', $id)->delete();
            DonorNote::query()->where('donor_id', $id)->delete();
            MagicLinkToken::query()->where('donor_id', $id)->delete();

            // Keyed by address, not by donor, so it is reached by hash or not
            // at all. A claim left behind would still carry a live link.
            if ($hash !== '') {
                PendingSignup::query()->where('email_hash', $hash)->delete();
            }

            Donor::query()->where('id', $id)->delete();

            // After the row is gone, so a listener cannot resurrect it by
            // writing something that references a donor which no longer exists.
            do_action('dono.donor.deleted', $id, $hash);
        });
    }

    /** @since 1.0.0 */
    public function redact(Donor $donor): Donor
    {
        if ($donor->redacted_at !== null) {
            return $donor;
        }

        // Before anything is destroyed: erasing first strands the mandate, so
        // the plan keeps billing and every renewal writes the donor's name and
        // email back into the webhook log.
        $this->stopRecurringBefore($donor);

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
        $donor->redacted_at        = $this->clock->now()->format('Y-m-d H:i:s');
        $donor->updated_at         = $donor->redacted_at;

        DB::transaction(function () use ($donor, $request) {
            $donor->save();

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
     * @since 1.0.0
     */
    private function stopRecurringBefore(Donor $donor): void
    {
        $plans = RecurringPlan::query()
            ->where('donor_id', (int) $donor->id)
            ->whereIn('status', ['active', 'paused'])
            ->getAll();

        if ($plans === []) {
            return;
        }

        $canceller = \Dono\Foundation\Plugin::instance()->container->get(RecurringCanceller::class);

        foreach ($plans as $plan) {
            $canceller->cancel($plan, __('The donor asked for their data to be erased.', 'dono'));
        }
    }

    /**
     * Everything that identifies this donor, read while it is still readable.
     * Gateway ids are in here because a webhook body has no donor_id: `pi_...`
     * or `cus_...` is the only thread back to the person it describes.
     *
     * @since 1.0.0
     */
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
        foreach ($donations as $d) {
            $donationIds[]  = (int) $d->id;
            $identifiers[]  = $d->reference;
            $identifiers[]  = $d->gateway_intent_id;
            $identifiers[]  = $d->gateway_txn_id;
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
            throw new InvalidArgumentException(__('This donor has been erased and can no longer be edited.', 'dono'));
        }
        if (! in_array($field, ['phone_encrypted', 'address_encrypted', 'notes_encrypted', 'tax_id_encrypted'], true)) {
            throw new InvalidArgumentException("Unsupported encrypted field: {$field}");
        }
        $encrypted = ($value === null || $value === '') ? null : $this->crypto->encrypt($value);
        DB::table('dono_donors')
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
        $rows = DB::table('dono_donors')
            ->selectRaw('id')
            ->where(function ($q) use ($term, $hash): void {
                $q->whereLike('first_name', $term)
                  ->orWhereLike('last_name', $term)
                  ->orWhere('email_hash', $hash);
            })
            ->getAll();

        return array_map(static fn ($r): int => (int) (is_array($r) ? $r['id'] : $r->id), $rows);
    }
}
