<?php

declare(strict_types=1);

namespace Dono\GiftAid;

use Dono\Donations\Donation;
use Dono\Donors\Donor;
use Dono\Foundation\Crypto\Crypto;

/**
 * The claim record: what HMRC needs about the donor for a given gift, frozen at
 * the moment the gift was stamped claimable.
 *
 * Snapshotted rather than read live off the donor. HMRC wants the address as it
 * was when the gift was made, so a donor moving house must not silently rewrite
 * a claim already submitted, and the record has to outlive donor erasure for
 * the six years HMRC can ask about it.
 *
 * @version 1.0.0
 */
final class GiftAidClaims
{
    /**
     * HMRC keeps Gift Aid records available for inspection for six years after
     * the end of the accounting period the claim falls in. Erasure honours that
     * as a legal obligation and clears the snapshot once it has passed.
     */
    public const RETENTION_YEARS = 6;

    public function __construct(private Crypto $crypto)
    {
    }

    /**
     * @param array<string,mixed> $profile the profile as submitted with the gift
     */
    public function snapshot(Donation $donation, ?Donor $donor, array $profile = []): void
    {
        $address = $this->address($donor, $profile);

        $claim = [
            'title'      => trim((string) ($profile['title'] ?? '')),
            'first_name' => trim((string) ($profile['first_name'] ?? $donor?->first_name ?? '')),
            'last_name'  => trim((string) ($profile['last_name']  ?? $donor?->last_name  ?? '')),
            // HMRC asks for the house name or number, not the whole line.
            'house'      => self::houseFrom((string) ($address['line1'] ?? '')),
            'postcode'   => strtoupper(trim((string) ($address['postal'] ?? ''))),
        ];

        $donation->gift_aid_claim_encrypted = $this->crypto->encrypt((string) wp_json_encode($claim));
    }

    /** @return array{title:string,first_name:string,last_name:string,house:string,postcode:string}|null */
    public function read(Donation $donation): ?array
    {
        if ($donation->gift_aid_claim_encrypted === null) return null;

        $decoded = json_decode((string) $this->crypto->decrypt($donation->gift_aid_claim_encrypted), true);
        if (! is_array($decoded)) return null;

        return [
            'title'      => (string) ($decoded['title']      ?? ''),
            'first_name' => (string) ($decoded['first_name'] ?? ''),
            'last_name'  => (string) ($decoded['last_name']  ?? ''),
            'house'      => (string) ($decoded['house']      ?? ''),
            'postcode'   => (string) ($decoded['postcode']   ?? ''),
        ];
    }

    /**
     * A claim HMRC will accept needs a surname, a house name or number and a
     * postcode. Anything short of that is an incomplete record the charity
     * should chase rather than submit.
     */
    public function isComplete(Donation $donation): bool
    {
        $claim = $this->read($donation);

        return $claim !== null
            && $claim['last_name'] !== ''
            && $claim['house']     !== ''
            && $claim['postcode']  !== '';
    }

    /** True once the statutory retention period for this gift has passed. */
    public function retentionExpired(Donation $donation, string $now): bool
    {
        $made = (string) ($donation->paid_at ?? $donation->created_at ?? '');
        if ($made === '') return false;

        $expires = strtotime($made . ' +' . self::RETENTION_YEARS . ' years');

        return $expires !== false && $expires < strtotime($now);
    }

    /** HMRC's schedule gives this column 40 characters. */
    private const HOUSE_MAX = 40;

    /**
     * Sub-dwelling words that belong to the house, not to the street. "Flat 2,
     * 14 Acacia Avenue" identifies a different property from "14 Acacia
     * Avenue", so dropping the flat loses the claim its match.
     */
    private const DESIGNATORS = ['flat', 'apartment', 'apt', 'unit', 'suite', 'room', 'no', 'block'];

    /**
     * The house name or number out of one free-text line.
     *
     * HMRC matches a claim on this plus the postcode, so it takes the leading
     * run that identifies the dwelling and stops at the street: "Flat 2, 14
     * Acacia Avenue" gives "Flat 2, 14", "14a Acacia Avenue" gives "14a".
     */
    public static function houseFrom(string $line1): string
    {
        $line1 = trim($line1);
        if ($line1 === '') return '';

        $kept = [];
        foreach (preg_split('/\s+/', $line1) ?: [] as $token) {
            $bare = strtolower(rtrim($token, '.,'));
            if (preg_match('/\d/', $token) !== 1 && ! in_array($bare, self::DESIGNATORS, true)) {
                break;
            }
            $kept[] = $token;
        }

        // A named house carries no number at all, so the name is whatever comes
        // before the street: everything up to the first comma.
        if ($kept === []) {
            $name = strtok($line1, ',');
            return substr(trim((string) $name), 0, self::HOUSE_MAX);
        }

        return substr(rtrim(implode(' ', $kept), ' ,'), 0, self::HOUSE_MAX);
    }

    /**
     * @param array<string,mixed> $profile
     * @return array<string,mixed>
     */
    private function address(?Donor $donor, array $profile): array
    {
        if (is_array($profile['address'] ?? null)) {
            return $profile['address'];
        }
        if ($donor?->address_encrypted) {
            $decoded = json_decode((string) $this->crypto->decrypt($donor->address_encrypted), true);
            if (is_array($decoded)) return $decoded;
        }
        return [];
    }
}
