<?php

declare(strict_types=1);

namespace Dono\Donors\Erasure;

/**
 * Everything a handler needs to find this donor, captured before the wipe.
 *
 * This is the whole reason the registry exists. The old
 * `dono.donor.redacted` action fired at the end of DonorService::redact(),
 * by which point email_encrypted was already '' and the names were null, so a
 * listener could only ever match on donor_id. That is fine for a table with a
 * donor_id column and useless for the ones without: AI chat transcripts,
 * webhook payloads and importer mappings hold the donor's email and name as
 * loose text, and the only way to find them is to search for the values the
 * action could no longer see.
 *
 * @version 1.0.0
 */
final class ErasureRequest
{
    /**
     * Below this length a needle stops identifying anyone. Searching a
     * transcript for a three-letter surname would scrub unrelated rows, which
     * is its own kind of data loss.
     */
    private const MIN_NEEDLE = 4;

    /**
     * A name has to be at least this long, on top of having more than one part,
     * before it is worth searching loose text for.
     */
    private const MIN_NAME_NEEDLE = 8;

    /**
     * @param list<int>    $donationIds donations belonging to this donor
     * @param list<string> $needles     identifiers as they read before the wipe
     * @param string       $emailHash   reaches rows keyed by address rather
     *                                  than by donor id, which is the only
     *                                  handle an unproven signup ever has
     */
    public function __construct(
        public readonly int $donorId,
        public readonly array $donationIds,
        public readonly array $needles,
        public readonly string $at,
        public readonly string $emailHash = '',
    ) {
    }

    /**
     * Needles are searched as `LIKE '%needle%'` over longtext payloads, so what
     * goes in the list decides whose rows get scrubbed. Four characters is long
     * enough for a value that is unique by construction and nowhere near enough
     * for a name: erasing Anna Bell matched joanna@example.com, susanna@,
     * campbell@ and the string "Bellevue", and the handlers do not redact those
     * rows, they blank them. Unrelated donors lost their analytics history and
     * their donations lost the raw gateway payload they can be audited or
     * replayed from, silently, on a schedule, because DonorRetention runs this.
     *
     * So the two kinds are kept apart. An identifier is unique by construction
     * and is trusted as a substring. A name is not: only a multi-part one long
     * enough to be distinctive is searched for, which keeps "Anna Bell" and
     * drops the "Anna" and "Bell" that were doing the damage.
     *
     * @param list<?string> $identifiers unique by construction: email, phone,
     *                                   tax id, references, gateway ids
     * @param list<?string> $names       free text: personal and company names
     * @param list<int>     $donationIds
     */
    public static function make(
        int $donorId,
        array $donationIds,
        array $identifiers,
        array $names,
        string $at,
        string $emailHash = '',
    ): self {
        $needles = [];

        foreach ($identifiers as $value) {
            $value = trim((string) $value);
            if (strlen($value) < self::MIN_NEEDLE) continue;
            $needles[strtolower($value)] = $value;
        }

        foreach ($names as $value) {
            $value = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');
            if (strlen($value) < self::MIN_NAME_NEEDLE) continue;
            // One word is a word, not an identifier.
            if (! str_contains($value, ' ')) continue;
            $needles[strtolower($value)] = $value;
        }

        return new self($donorId, array_values($donationIds), array_values($needles), $at, $emailHash);
    }

    /** True when there is nothing specific enough to search text for. */
    public function hasNoNeedles(): bool
    {
        return $this->needles === [];
    }

    /**
     * Needles as contains-patterns for LIKE. Wildcards inside a needle are
     * escaped: an unescaped `%` in a donor's own data would widen the match to
     * every row in the table and scrub other people's records.
     *
     * @return list<string>
     */
    public function likePatterns(): array
    {
        return array_map(
            static fn (string $n): string => '%' . str_replace(
                ['\\', '%', '_'],
                ['\\\\', '\\%', '\\_'],
                $n
            ) . '%',
            $this->needles
        );
    }
}
