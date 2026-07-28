<?php

declare(strict_types=1);

namespace Dono\Gateways\PayPal;

use RuntimeException;

/**
 * A PayPal API error, carrying the machine-readable issue codes alongside the
 * human message.
 *
 * The codes used to be reachable only by grepping the formatted message, and
 * `PayPalApi::errorMessage()` prefers `details[].description` over
 * `details[].issue`, so the codes were dropped before any caller saw them.
 * `ORDER_ALREADY_CAPTURED` therefore never matched on PayPal's real response
 * shape, and re-entrant capture silently failed the donor on a payment PayPal
 * had already taken. The `already` needle in the subscription-state check had
 * the mirror problem: it matched the *description* "Order already captured"
 * for an unrelated call.
 *
 * Extends RuntimeException so existing catch blocks keep working.
 *
 * @version 1.0.0
 */
final class PayPalApiException extends RuntimeException
{
    /** @param list<string> $issues PayPal's `details[].issue` codes, upper-case. */
    public function __construct(string $message, private array $issues = [])
    {
        parent::__construct($message);
    }

    /** @return list<string> */
    public function issues(): array
    {
        return $this->issues;
    }

    public function hasIssue(string ...$codes): bool
    {
        foreach ($codes as $code) {
            if (in_array(strtoupper($code), $this->issues, true)) return true;
        }
        return false;
    }

    /**
     * @param array<string,mixed> $body
     * @return list<string>
     */
    public static function issuesFrom(array $body): array
    {
        $details = $body['details'] ?? null;
        if (! is_array($details)) return [];

        $issues = [];
        foreach ($details as $detail) {
            if (! is_array($detail)) continue;
            $issue = strtoupper(trim((string) ($detail['issue'] ?? '')));
            if ($issue !== '') $issues[] = $issue;
        }
        return array_values(array_unique($issues));
    }
}
