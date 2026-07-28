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
     * @param list<int>    $donationIds donations belonging to this donor
     * @param list<string> $needles     identifiers as they read before the wipe
     */
    public function __construct(
        public readonly int $donorId,
        public readonly array $donationIds,
        public readonly array $needles,
        public readonly string $at,
    ) {
    }

    /**
     * @param list<?string> $candidates
     * @param list<int>     $donationIds
     */
    public static function make(int $donorId, array $donationIds, array $candidates, string $at): self
    {
        $needles = [];
        foreach ($candidates as $value) {
            $value = trim((string) $value);
            if (strlen($value) < self::MIN_NEEDLE) continue;
            $needles[strtolower($value)] = $value;
        }

        return new self($donorId, array_values($donationIds), array_values($needles), $at);
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
