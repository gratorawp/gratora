<?php

declare(strict_types=1);

namespace Dono\Donors;

use Dono\Donations\Donation;
use Dono\Donations\DonationTribute;
use Dono\Foundation\Crypto\Crypto;
use Dono\Foundation\Identity\IdentityHasher;
use Dono\Foundation\Time\Clock;
use InvalidArgumentException;
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
    public function findOrCreate(string $email, array $profile = []): Donor
    {
        $email = $this->hasher->normalizeEmail($email);
        $hash  = $this->hasher->emailHash($email);

        $existing = $this->donors->findByEmailHash($hash);
        if ($existing !== null) {
            // One donor per email. If the matched donor was redacted, a new
            // donation means they re-engaged and re-provided their data, so
            // re-activate the row (restore the encrypted email, clear the
            // redaction flag) instead of leaving it redacted-with-PII.
            if ($existing->redacted_at !== null) {
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

    /**
     * Donor-initiated portal edit: overwrites any field present in the patch (unlike
     * refreshProfile's lock-on-first-write back-fill). Empty string clears to null;
     * absent keys are untouched.
     *
     * @param array{first_name?:?string,last_name?:?string,country?:?string,company?:?string,locale?:?string,phone?:?string,address?:array<string,mixed>|null} $patch
     */
    public function editProfile(Donor $donor, array $patch): Donor
    {
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
     */
    public function redact(Donor $donor): Donor
    {
        if ($donor->redacted_at !== null) {
            return $donor;
        }

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

        DB::transaction(function () use ($donor) {
            $donor->save();

            foreach (Donation::query()->where('donor_id', $donor->id)->getAll() as $donation) {
                if ($donation->custom_data_encrypted === null
                    && $donation->donor_first_name === null
                    && $donation->donor_last_name === null
                    && ($donation->note_to_org ?? '') === ''
                ) {
                    continue;
                }
                $donation->custom_data_encrypted = null;
                $donation->donor_first_name      = null;
                $donation->donor_last_name       = null;
                // Donor-authored message can carry PII; clear it on erasure.
                $donation->note_to_org           = null;
                $donation->updated_at            = $donor->redacted_at;
                $donation->save();
            }

            // Tributes carry donor-authored PII the erasure must also remove:
            // the message, a third party's notify email, and the honoree name.
            DonationTribute::query()
                ->where('donor_id', $donor->id)
                ->update([
                    'name'                   => '',
                    'notify_email_encrypted' => null,
                    'message_encrypted'      => null,
                ]);

            // Revoke outstanding magic-link tokens so a previously-emailed
            // portal link can no longer open a session for the redacted donor.
            MagicLinkToken::query()->where('donor_id', $donor->id)->delete();
        });

        do_action('dono.donor.redacted', $donor);

        return $donor;
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
