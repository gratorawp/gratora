<?php

declare(strict_types=1);

namespace Dono\Donors\Erasure;

/**
 * One plugin's share of a donor erasure.
 *
 * Handlers run inside the redaction transaction, so throwing rolls the whole
 * erasure back rather than leaving the donor half-erased and marked done.
 *
 * @version 1.0.0
 */
interface ErasureHandler
{
    /** Stable id, unique across core and add-ons. Used in logs and tests. */
    public function key(): string;

    public function erase(ErasureRequest $request): void;
}
