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

    /**
     * PayPal's shared error model requires `name` and leaves `details`
     * optional, so an error can arrive naming itself and carrying nothing else.
     * Read from details alone there is nothing to match, every issue check
     * answers false, and the guard written to recognise that error never fires:
     * a re-capture fails the donor on money PayPal already took, and an action
     * on a subscription already in the asked-for state is reported as an error.
     */
    public function test_an_error_that_names_itself_and_nothing_else_is_still_matchable(): void
    {
        $e = new PayPalApiException('x', PayPalApiException::issuesFrom([
            'name'     => 'INVALID_RESOURCE_STATE',
            'message'  => 'The requested action could not be performed.',
            'debug_id' => 'b7a1c9f3',
        ]));

        $this->assertTrue($e->hasIssue('INVALID_STATE', 'INVALID_RESOURCE_STATE'));
    }

    /** The name is read as well as the details, not instead of them. */
    public function test_the_name_does_not_hide_the_detail_issues(): void
    {
        $issues = PayPalApiException::issuesFrom([
            'name'    => 'UNPROCESSABLE_ENTITY',
            'details' => [['issue' => 'SUBSCRIPTION_STATUS_INVALID']],
        ]);

        $this->assertSame(['UNPROCESSABLE_ENTITY', 'SUBSCRIPTION_STATUS_INVALID'], $issues);
    }

    /**
     * The name PayPal puts on most 4xx is generic, so it must not answer for a
     * specific code the caller is asking about.
     */
    public function test_a_generic_name_does_not_answer_for_a_specific_code(): void
    {
        $e = new PayPalApiException('x', PayPalApiException::issuesFrom([
            'name'    => 'UNPROCESSABLE_ENTITY',
            'details' => [['issue' => 'PERMISSION_DENIED']],
        ]));

        $this->assertFalse($e->hasIssue('ORDER_ALREADY_CAPTURED'));
        $this->assertFalse($e->hasIssue('SUBSCRIPTION_STATUS_INVALID'));
    }

    public function test_duplicate_codes_collapse(): void
    {
        $issues = PayPalApiException::issuesFrom([
            'details' => [['issue' => 'INVALID_STATE'], ['issue' => 'INVALID_STATE']],
        ]);

        $this->assertSame(['INVALID_STATE'], $issues);
    }
}
