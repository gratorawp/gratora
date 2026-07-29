<?php

declare(strict_types=1);

namespace Dono\Donors;

use Dono\Donations\Donation;
use Dono\Donors\Erasure\ErasureRegistry;
use Dono\Donors\Erasure\ErasureRequest;
use Dono\Recurring\RecurringPlan;
use Dono\Foundation\Crypto\Crypto;
use Dono\Foundation\Identity\IdentityHasher;
use Dono\Foundation\Time\Clock;
use InvalidArgumentException;
use Throwable;
use Dono\Vendor\Queryable\DB;

/**
 * Domain operations on donors.
 *
 * @version 1.0.0
 */
final class DonorService
{
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
     * Find an existing donor by email, or create one with the given profile.
     *
     * @param array{
     *     first_name?: ?string,
     *     last_name?: ?string,
     *     country?: ?string,
     *     locale?: ?string,
     *     company?: ?string,
     *     donor_type?: 'individual'|'organization'|'household',
     * } $profile
     */
    public function findOrCreate(string $email, array $profile = [], bool $reactivateIfRedacted = false): Donor
    {
        $email = $this->hasher->normalizeEmail($email);
        $hash  = $this->hasher->emailHash($email);

        $existing = $this->donors->findByEmailHash($hash);
        if ($existing !== null) {
            // One donor per email. Only a genuine new donation re-activates a
            // redacted donor (restoring the encrypted email + clearing the
            // flag). A bare lookup - e.g. an unauthenticated portal link
            // request - must leave the erased row untouched: never un-redact it
            // and never re-populate PII through refreshProfile below.
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

    /** Look a donor up without creating one and without touching an erased row. */
    public function findByEmail(string $email): ?Donor
    {
        return $this->donors->findByEmailHash(
            $this->hasher->emailHash($this->hasher->normalizeEmail($email))
        );
    }

    /**
     * Donor-initiated portal edit: overwrites any field present in the patch (unlike
     * refreshProfile's lock-on-first-write back-fill). Empty string clears to null;
     * absent keys are untouched.
     *
     * @param array{first_name?:?string,last_name?:?string,country?:?string,company?:?string,locale?:?string,phone?:?string,address?:array<string,mixed>|null} $patch
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

        // One write path: the encrypted fields are set on the model above and
        // persisted by this single save(), so an unchanged phone/address no
        // longer issues a second UPDATE or fires donor.updated on a no-op.
        if ($changed) {
            $donor->updated_at = $this->clock->now()->format('Y-m-d H:i:s');
            $donor->save();
            do_action('dono.donor.updated', $donor);
        }

        return $donor;
    }

    /**
     * Back-fill only empty donor profile fields from the donation payload
     */
    public function refreshProfile(Donor $donor, array $profile): Donor
    {
        $changed = false;
        $fields = ['first_name', 'last_name', 'country', 'locale', 'company'];

        foreach ($fields as $f) {
            if (! array_key_exists($f, $profile)) continue;
            $value = $profile[$f];
            if ($value === null || $value === '') continue;
            // Only fill empty fields; never overwrite a populated one.
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

    /** Admin-only email change. Recomputes email_hash and email_encrypted. */
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
     * GDPR soft-redact: zeroes PII, preserves the row and donation totals.
     * Financial records (amount, reference, dates) are retained for legal/tax
     * purposes; custom form-field answers and per-donation names are cleared.
     *
     * The identifiers are read before anything is wiped, because handlers for
     * tables with no donor_id (webhook bodies, AI transcripts, importer maps)
     * can only find the donor by searching for the values themselves.
     */
    public function redact(Donor $donor): Donor
    {
        if ($donor->redacted_at !== null) {
            return $donor;
        }

        $request = $this->erasureRequest($donor);

        $donor->email_encrypted    = '';
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

            // Core and every add-on erase through the same registry, inside
            // this transaction: a handler that cannot finish its part rolls the
            // whole thing back rather than leaving the donor marked erased when
            // only some of their data went.
            $this->erasure->run($request);

            // A zero retention window means there is no grace period in which a
            // returning donor is reunited with this record, so the handle goes
            // now rather than on tomorrow's sweep.
            if ($this->purge->purgesOnRedaction()) {
                $this->purge->purge($donor);
            }
        });

        return $donor;
    }

    /**
     * Snapshot of everything that identifies this donor, taken while it is
     * still readable. Gateway ids are in here because a webhook body has no
     * donor_id: `pi_...` or `cus_...` is the only thread back from the raw
     * payload to the person it describes.
     */
    private function erasureRequest(Donor $donor): ErasureRequest
    {
        $donations = Donation::query()->where('donor_id', $donor->id)->getAll();
        $plans     = RecurringPlan::query()->where('donor_id', $donor->id)->getAll();

        $candidates = [
            $this->decryptEmail($donor),
            $this->decrypt($donor->phone_encrypted),
            $this->decrypt($donor->tax_id_encrypted),
            $donor->first_name,
            $donor->last_name,
            trim((string) $donor->first_name . ' ' . (string) $donor->last_name),
            $donor->company,
        ];

        $donationIds = [];
        foreach ($donations as $d) {
            $donationIds[]  = (int) $d->id;
            $candidates[]   = $d->reference;
            $candidates[]   = $d->gateway_intent_id;
            $candidates[]   = $d->gateway_txn_id;
        }
        foreach ($plans as $p) {
            $candidates[] = $p->gateway_subscription_id;
            $candidates[] = $p->gateway_customer_id;
        }

        return ErasureRequest::make(
            (int) $donor->id,
            $donationIds,
            $candidates,
            $this->clock->now()->format('Y-m-d H:i:s'),
        );
    }

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
     * Decrypts donor email. Authorized contexts only; never from public APIs.
     */
    public function decryptEmail(Donor $donor): ?string
    {
        if ($donor->redacted_at !== null || $donor->email_encrypted === '') {
            return null;
        }
        return $this->crypto->decrypt($donor->email_encrypted);
    }

    /** Same authorisation contract as decryptEmail. */
    public function decryptPhone(Donor $donor): ?string
    {
        if ($donor->redacted_at !== null || ! $donor->phone_encrypted) {
            return null;
        }
        return $this->crypto->decrypt($donor->phone_encrypted);
    }

    /**
     * Structured address form (the EditPanel writes this shape on save). Returns
     * the raw decoded struct as stored, so callers can render it any way they
     * like. Use decryptAddress() when you only want the joined display string.
     *
     * @return array{line1?:string,line2?:string,city?:string,region?:string,postal?:string,country?:string}|null
     */
    public function decryptAddressStruct(Donor $donor): ?array
    {
        if ($donor->redacted_at !== null || ! $donor->address_encrypted) return null;
        $raw = $this->crypto->decrypt($donor->address_encrypted);
        if ($raw === null || $raw === '') return null;
        $decoded = json_decode($raw, true);
        return is_array($decoded) && $decoded !== [] ? $decoded : null;
    }

    /** Same authorisation contract as decryptEmail. */
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

    /**
     * Normalise a structured address into a JSON payload ready for encryption.
     * Returns null when every field is empty.
     *
     * @param array<string,mixed>|null $address
     */
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

    /** Encrypts and persists a value into an encrypted donor column. Null/empty clears it. */
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
     * Donor ids matching a free-text term. Name uses LIKE; email is exact hash match.
     *
     * @return array<int>
     */
    public function findIdsBySearch(string $term): array
    {
        $term = trim($term);
        if ($term === '') return [];

        $hash = $this->hasher->emailHash($term);

        $rows = Donor::query()
            ->where(function ($q) use ($term, $hash): void {
                $q->whereLike('first_name', $term)
                  ->orWhereLike('last_name', $term)
                  ->orWhere('email_hash', $hash);
            })
            ->getAll();

        return array_map(static fn ($d) => (int) $d->id, $rows);
    }
}
