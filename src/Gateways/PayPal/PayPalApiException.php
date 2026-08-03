<?php

declare(strict_types=1);

namespace Dono\Gateways\PayPal;

use RuntimeException;

/**
 * A PayPal API error, carrying the machine-readable issue codes alongside the
 * human message.
 *
 * `PayPalApi::errorMessage()` prefers `details[].description` over
 * `details[].issue`, so the codes do not survive into the formatted message.
 * Matching on the message text is lossy in that direction and wrong in the
 * other: an `already` needle also hits the description "Order already
 * captured" on unrelated calls. Callers match on issues() instead.
 *
 * Extends RuntimeException so catch blocks up the stack still work.
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
