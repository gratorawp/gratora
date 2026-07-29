<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Forms\FormTemplates;

/**
 * Several Dono blocks register `supports.multiple = false` in their editor
 * registration (one amount picker, one submit, one consent block, etc.).
 * The Gutenberg editor silently drops the second instance, which produced a
 * confusing "missing block" symptom in earlier templates. This regression
 * guard scans every shipped template's block markup against the canonical
 * single-instance list and fails fast on any duplicate.
 */
final class FormTemplatesSingletonTest extends IntegrationTestCase
{
    /** Block names whose JS registration sets `supports.multiple = false`. */
    private const SINGLETONS = [
        'dono/fund-picker',
        'dono/anonymous-toggle',
        'dono/privacy-notice',
        'dono/comment',
        'dono/cover-fees',
        'dono/submit-button',
        'dono/donation-amount',
        'dono/payment-gateways',
        'dono/consent',
        'dono/currency-switcher',
        'dono/steps',
        'dono/phone',
        'dono/address',
        'dono/name',
        'dono/email',
        'dono/country',
        'dono/recurring-toggle',
        'dono/goal',
    ];

    public function test_no_template_duplicates_a_single_instance_block(): void
    {
        $offences = [];
        foreach (FormTemplates::all() as $template) {
            $blocks = (string) ($template['blocks'] ?? '');
            foreach (self::SINGLETONS as $block) {
                $pattern = '#<!-- wp:' . preg_quote($block, '#') . '(\s|/-->|-->)#';
                $count = preg_match_all($pattern, $blocks);
                if ($count > 1) {
                    $offences[] = sprintf('%s: %s x%d', $template['id'], $block, $count);
                }
            }
        }

        $this->assertSame(
            [],
            $offences,
            "Templates duplicate single-instance blocks (editor silently drops the extras):\n  "
            . implode("\n  ", $offences)
        );
    }
}
