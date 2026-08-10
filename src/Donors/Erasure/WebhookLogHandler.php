<?php

declare(strict_types=1);

namespace Dono\Donors\Erasure;

use Dono\Webhooks\WebhookLog;

/**
 * Raw gateway webhook bodies. These are the least obvious copy of a donor's
 * data and among the most complete: a Stripe or PayPal event carries the payer
 * email, billing name and address, and the card's last four, verbatim.
 *
 * The rows stay. Their unique (gateway, external_id) is what stops a gateway's
 * redelivery being processed twice, so deleting them would let a replayed
 * webhook re-create the donation the erasure just cleaned. Blanking the body
 * keeps that guard intact and costs only the ability to replay by hand.
 *
 * There is no donor_id here to key on, which is why the registry hands over the
 * identifiers captured before the wipe.
 *
 * @since 1.0.0
 */
final class WebhookLogHandler implements ErasureHandler
{
    private const CLEARED = ['payload' => '', 'headers' => null, 'error' => null];

    /** @since 1.0.0 */
    public function key(): string
    {
        return 'dono.webhook_log';
    }

    /** @since 1.0.0 */
    public function erase(ErasureRequest $request): void
    {
        if ($request->hasNoNeedles()) return;

        // One grouped scan rather than one per needle: payload is a longtext
        // and there are a dozen or more needles.
        $patterns = $request->likePatterns();
        WebhookLog::query()
            ->where(static function ($q) use ($patterns): void {
                $first = array_shift($patterns);
                $q->whereLike('payload', $first);
                foreach ($patterns as $pattern) {
                    $q->orWhereLike('payload', $pattern);
                }
            })
            ->update(self::CLEARED);
    }
}
