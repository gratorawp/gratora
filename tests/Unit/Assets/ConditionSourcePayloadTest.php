<?php

declare(strict_types=1);

namespace Dono\Tests\Unit\Assets;

use PHPUnit\Framework\TestCase;

/**
 * The form drops the value of a field its own condition hides, so a default the
 * donor never saw is not submitted. A value another block's condition reads is
 * the exception: the server evaluates the same conditions against the payload,
 * reads the gap as empty, and can end up requiring a field that is not on
 * screen, which no retry can satisfy.
 *
 * @since 1.0.0
 */
final class ConditionSourcePayloadTest extends TestCase
{
    private function source(): string
    {
        $path = dirname(__DIR__, 3) . '/assets/donation-form/state/store.js';
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /** The one call that turns form state into the submitted custom map. */
    private function customPayloadExpression(): string
    {
        $matched = preg_match('/custom:\s*buildCustom\((.*?)\),/s', $this->source(), $m);
        $this->assertSame(1, $matched, 'buildPayload no longer builds `custom` through buildCustom.');

        return trim((string) $m[1]);
    }

    public function test_the_payload_keeps_hidden_values_that_conditions_read(): void
    {
        $this->assertStringContainsString(
            'conditionSourceKeys',
            $this->customPayloadExpression(),
            'A hidden value another condition reads must still reach the validator.'
        );

        $this->assertStringContainsString(
            'function conditionSourceKeys(',
            $this->source(),
            'The keys authored conditions read are collected from the form definition.'
        );
    }

    public function test_condition_sources_are_collected_from_nested_items(): void
    {
        $matched = preg_match(
            '/function conditionSourceKeys\(.*?\n\}/s',
            $this->source(),
            $m
        );
        $this->assertSame(1, $matched);

        $body = (string) $m[0];

        $this->assertStringContainsString(
            'children',
            $body,
            'Conditions on the contents of a section or columns block count too.'
        );
        $this->assertStringContainsString(
            'fieldSteps(',
            $body,
            'Fields authored above a wizard live in the preamble, outside `steps`.'
        );
    }

    public function test_suppression_still_drops_every_other_hidden_value(): void
    {
        $matched = preg_match('/function buildCustom\(.*?\n\}/s', $this->source(), $m);
        $this->assertSame(1, $matched, 'buildCustom is gone.');

        $this->assertStringContainsString(
            'suppress',
            (string) $m[0],
            'A hidden value nothing depends on is still left out of the payload.'
        );
    }
}
