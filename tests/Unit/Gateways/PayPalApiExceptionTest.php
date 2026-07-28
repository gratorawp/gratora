<?php

declare(strict_types=1);

namespace Dono\Tests\Unit\Gateways;

use Dono\Gateways\PayPal\PayPalApiException;
use PHPUnit\Framework\TestCase;

/**
 * The issue codes used to be reachable only by grepping the formatted message,
 * and the formatter prefers `description` over `issue`, so they were gone
 * before any caller looked. `ORDER_ALREADY_CAPTURED` therefore never matched on
 * PayPal's real response shape and a re-capture failed the donor on money
 * PayPal had already taken.
 */
final class PayPalApiExceptionTest extends TestCase
{
    /** PayPal's actual body: the issue code always arrives with a description. */
    public function test_the_issue_code_survives_alongside_its_description(): void
    {
        $body = [
            'name'    => 'UNPROCESSABLE_ENTITY',
            'message' => 'The requested action could not be performed.',
            'details' => [[
                'issue'       => 'ORDER_ALREADY_CAPTURED',
                'description' => "Order already captured.If 'intent=CAPTURE' only one capture per order is allowed.",
            ]],
        ];

        $e = new PayPalApiException('PayPal API: whatever', PayPalApiException::issuesFrom($body));

        $this->assertTrue($e->hasIssue('ORDER_ALREADY_CAPTURED'));
    }

    public function test_codes_are_matched_case_insensitively(): void
    {
        $e = new PayPalApiException('x', PayPalApiException::issuesFrom([
            'details' => [['issue' => 'invalid_state']],
        ]));

        $this->assertTrue($e->hasIssue('INVALID_STATE'));
        $this->assertTrue($e->hasIssue('invalid_state'));
    }

    public function test_any_of_several_codes_matches(): void
    {
        $e = new PayPalApiException('x', PayPalApiException::issuesFrom([
            'details' => [['issue' => 'SUBSCRIPTION_STATUS_INVALID']],
        ]));

        $this->assertTrue($e->hasIssue('INVALID_STATE', 'SUBSCRIPTION_STATUS_INVALID'));
        $this->assertFalse($e->hasIssue('ORDER_ALREADY_CAPTURED'));
    }

    /**
     * The mirror of the original bug: the old `already` needle matched the
     * *description* of unrelated errors, so a genuine failure could be
     * swallowed as "already in that state".
     */
    public function test_a_description_mentioning_a_code_does_not_count_as_that_code(): void
    {
        $e = new PayPalApiException('x', PayPalApiException::issuesFrom([
            'details' => [[
                'issue'       => 'PERMISSION_DENIED',
                'description' => 'Order already captured by another actor.',
            ]],
        ]));

        $this->assertFalse($e->hasIssue('ORDER_ALREADY_CAPTURED'));
        $this->assertTrue($e->hasIssue('PERMISSION_DENIED'));
    }

    public function test_a_body_with_no_details_yields_no_codes(): void
    {
        $this->assertSame([], PayPalApiException::issuesFrom(['message' => 'nope']));
        $this->assertSame([], PayPalApiException::issuesFrom(['details' => 'not-an-array']));
        $this->assertFalse((new PayPalApiException('x'))->hasIssue('ANYTHING'));
    }

    public function test_duplicate_codes_collapse(): void
    {
        $issues = PayPalApiException::issuesFrom([
            'details' => [['issue' => 'INVALID_STATE'], ['issue' => 'INVALID_STATE']],
        ]);

        $this->assertSame(['INVALID_STATE'], $issues);
    }
}
