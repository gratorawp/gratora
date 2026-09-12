<?php

declare(strict_types=1);

namespace Gratora\Donations;

defined('ABSPATH') || exit;

/**
 * What happened to one row in a trash request.
 *
 * Per row, never per batch: an admin clearing a page of spam gets some rows
 * stopped and some refused, and a single verdict for the whole selection either
 * overstates what was done or hides it.
 *
 * A collision is its own outcome. Telling the second admin that money may have
 * arrived, when what actually happened is that someone else is working the same
 * row, is the one message that stops a cleanup.
 *
 * @since 1.0.0
 */
final class TrashOutcome
{
    public const TRASHED = 'trashed';
    public const REFUSED = 'refused';
    public const LOCKED  = 'locked';

    /** Already in the trash. Not a failure: the admin asked for a state it is in. */
    public const ALREADY = 'already';

    private function __construct(
        public readonly string $outcome,
        public readonly ?string $reason = null,
        public readonly bool $paymentStopped = false,
    ) {
    }

    /** @since 1.0.0 */
    public static function trashed(bool $paymentStopped): self
    {
        return new self(self::TRASHED, null, $paymentStopped);
    }

    /** @since 1.0.0 */
    public static function refused(string $reason): self
    {
        return new self(self::REFUSED, $reason);
    }

    /** @since 1.0.0 */
    public static function locked(): self
    {
        return new self(self::LOCKED, __('Another admin is acting on this donation. Try again in a moment.', 'gratora-donation-platform'));
    }

    /** @since 1.0.0 */
    public static function already(): self
    {
        return new self(self::ALREADY);
    }

    /** @since 1.0.0 */
    public function ok(): bool
    {
        return $this->outcome === self::TRASHED || $this->outcome === self::ALREADY;
    }
}
