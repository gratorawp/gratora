<?php

declare(strict_types=1);

namespace Dono\Recurring;

/**
 * Who asked for a change to a plan, and whether the donor should be told.
 *
 * The donor changing their own gift and an admin changing it for them are the
 * same operation against the gateway but not the same event: one needs no
 * notice because the donor just did it, the other alters what a card is
 * charged without the cardholder present. Carrying both facts to the point of
 * the write is what lets the activity log say who, and the mailer decide
 * whether to send.
 *
 * @version 1.0.0
 */
final class RecurringPlanChange
{
    public const BY_DONOR = 'donor';
    public const BY_ADMIN = 'admin';

    /** @param array<string,mixed> $detail Before/after values for the log. */
    public function __construct(
        public readonly string $action,
        public readonly string $by = self::BY_DONOR,
        public readonly ?int $userId = null,
        public readonly bool $notifyDonor = false,
        public array $detail = [],
    ) {
    }

    public static function byDonor(string $action): self
    {
        // The donor is looking at the screen that made the change, so a mail
        // telling them what they just did is noise.
        return new self($action, self::BY_DONOR, null, false);
    }

    public static function byAdmin(string $action, bool $notifyDonor = true): self
    {
        return new self($action, self::BY_ADMIN, get_current_user_id() ?: null, $notifyDonor);
    }

    public function isByAdmin(): bool
    {
        return $this->by === self::BY_ADMIN;
    }
}
