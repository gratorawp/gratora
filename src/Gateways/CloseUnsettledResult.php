<?php

declare(strict_types=1);

namespace Gratora\Gateways;

defined('ABSPATH') || exit;

/**
 * What a gateway found when asked to close the payment behind an unsettled row.
 *
 * Five answers, not two. "The gateway says money can still arrive" and "another
 * request is already acting on this donation" both stop a trash, and folding
 * them into one refusal tells the second admin their spam row may have been
 * paid, which is false and is the one message that stops a cleanup. A refusal
 * and an unreachable host are separated for the same reason they are separated
 * in GatewayTransportException: only one of them may fall back to the age rule.
 *
 * @since 1.0.0
 */
final class CloseUnsettledResult
{
    /** Nothing payable is open any more. */
    public const CLOSED = 'closed';

    /** The gateway holds, or may yet take, the money. */
    public const MONEY_MAY_ARRIVE = 'money_may_arrive';

    /** The gateway answered, and the answer was no. */
    public const REFUSED = 'refused';

    /** The request never reached the gateway, so nothing is known either way. */
    public const UNREACHABLE = 'unreachable';

    /** Another request holds this donation's charge lock. */
    public const LOCKED = 'locked';

    private function __construct(
        public readonly string $outcome,
        public readonly ?string $reason = null,
    ) {
    }

    /** @since 1.0.0 */
    public static function closed(): self
    {
        return new self(self::CLOSED);
    }

    /** @since 1.0.0 */
    public static function moneyMayArrive(string $reason): self
    {
        return new self(self::MONEY_MAY_ARRIVE, $reason);
    }

    /** @since 1.0.0 */
    public static function refused(string $reason): self
    {
        return new self(self::REFUSED, $reason);
    }

    /** @since 1.0.0 */
    public static function unreachable(string $reason): self
    {
        return new self(self::UNREACHABLE, $reason);
    }

    /** @since 1.0.0 */
    public static function locked(): self
    {
        return new self(self::LOCKED);
    }

    /** @since 1.0.0 */
    public function isClosed(): bool
    {
        return $this->outcome === self::CLOSED;
    }

    /**
     * Whether the age fallback may still admit this row.
     *
     * Only an unreachable gateway qualifies. A gateway that answered and
     * refused has told us something, and waiting thirty days does not make its
     * answer any less true.
     *
     * @since 1.0.0
     */
    public function mayFallBackOnAge(): bool
    {
        return $this->outcome === self::UNREACHABLE;
    }
}
