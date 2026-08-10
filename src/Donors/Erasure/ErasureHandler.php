<?php

declare(strict_types=1);

namespace Dono\Donors\Erasure;

/**
 * One plugin's share of a donor erasure.
 *
 * Handlers run inside the redaction transaction, so throwing rolls the whole
 * erasure back rather than leaving the donor half-erased and marked done.
 *
 * @since 1.0.0
 */
interface ErasureHandler
{
    /**
     * Stable id, unique across core and add-ons. Used in logs and tests.
     *
     * @since 1.0.0
     */
    public function key(): string;

    /** @since 1.0.0 */
    public function erase(ErasureRequest $request): void;
}
