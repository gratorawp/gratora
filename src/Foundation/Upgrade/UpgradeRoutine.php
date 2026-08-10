<?php

declare(strict_types=1);

namespace Dono\Foundation\Upgrade;

/**
 * A one-shot data migration.
 *
 * dbDelta reconciles the shape of a table and nothing else. Anything that has
 * to touch the contents - backfilling a column a release added, recomputing a
 * denormalized counter, rewriting a stored format, or the ALTERs dbDelta will
 * not do (dropping a column or index, changing nullability) - has no other
 * place to live.
 *
 * @since 1.0.0
 */
interface UpgradeRoutine
{
    /**
     * Stable identifier, recorded once the routine finishes.
     *
     * It is written into an option and compared forever after, so renaming one
     * makes it run again on every site that already ran it. Date-prefix them
     * and treat the string as permanent.
     *
     * @since 1.0.0
     */
    public function id(): string;

    /**
     * Shown in the admin while the routine is outstanding.
     *
     * @since 1.0.0
     */
    public function description(): string;

    /**
     * Do a bounded amount of work.
     *
     * Bounded because this runs on a real site whose donations table may have
     * hundreds of thousands of rows, and because whatever calls it has a
     * timeout. Return false to be called again, true when there is nothing
     * left.
     *
     * Must be safe to run twice over the same rows: the process can die between
     * the last batch and the stamp that records completion, and it will then
     * start again from the top.
     *
     * @since 1.0.0
     */
    public function step(): bool;
}
