<?php

declare(strict_types=1);

namespace Dono\Donors\Erasure;

/**
 * Everything a handler needs to find this donor, captured before the wipe.
 *
 * Tables with no donor_id column (AI chat transcripts, webhook payloads,
 * importer mappings) hold the donor's email and name as loose text, and the
 * only way to reach them is to search for the values themselves.
 *
 * @since 1.0.0
 */
final class ErasureRequest
{
    /**
     * Below this length a needle stops identifying anyone: a three-letter
     * surname would scrub unrelated rows, which is its own kind of data loss.
     */
    private const MIN_NEEDLE = 4;

    /**
     * A name has to be at least this long, on top of having more than one part,
     * before it is worth searching loose text for.
     */
    private const MIN_NAME_NEEDLE = 8;

    /**
     * $emailHash reaches rows keyed by address rather than by donor id, which
     * is the only handle an unproven signup ever has.
     *
     * @since 1.0.0
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
     * Needles are searched as `LIKE '%needle%'` over longtext payloads and the
     * handlers blank whatever they match, so an over-broad needle is data loss
     * for other donors. An identifier is unique by construction and is trusted
     * as a substring; a name is not, so only a multi-part name long enough to
     * be distinctive is searched for.
     *
     * @param list<?string> $identifiers unique by construction: email, phone,
     *                                   tax id, references, gateway ids
     * @param list<?string> $names       free text: personal and company names
     *
     * @since 1.0.0
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

    /** @since 1.0.0 */
    public function hasNoNeedles(): bool
    {
        return $this->needles === [];
    }

    /**
     * Wildcards inside a needle are escaped: an unescaped `%` in a donor's own
     * data would widen the match to every row and scrub other people's records.
     *
     * @since 1.0.0
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
