<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Campaigns\CampaignService;
use Dono\Forms\Form;
use Dono\Forms\FormTemplates;
use Dono\Foundation\Plugin;

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
        'dono/donation-summary',
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

    /**
     * A template that can be submitted has to say where the donor picks how to
     * pay. The runtime used to draw the selector on the last page when no block
     * was placed, so a template could omit it and still work; that fallback is
     * gone, and a template without the block now ships a form that chooses a
     * gateway for the donor without asking.
     */
    public function test_every_submittable_template_places_the_payment_gateways_block(): void
    {
        $missing = [];
        foreach (FormTemplates::all() as $template) {
            $blocks = (string) ($template['blocks'] ?? '');
            if (! str_contains($blocks, 'wp:dono/submit-button')) {
                continue;   // Blank ships no markup at all, by design.
            }
            if (! str_contains($blocks, 'wp:dono/payment-gateways')) {
                $missing[] = (string) $template['id'];
            }
        }

        $this->assertSame(
            [],
            $missing,
            "Templates can be submitted but never ask how to pay:\n  " . implode("\n  ", $missing)
        );
    }

    /**
     * The form every campaign is born with does not come from FormTemplates: it
     * is CampaignService's own starter markup, which is the copy most installs
     * actually see. Fixing the templates alone left it without a selector.
     */
    public function test_the_default_campaign_form_places_the_payment_gateways_block(): void
    {
        $campaign = Plugin::instance()->container->get(CampaignService::class)->create([
            'title'  => 'Starter blocks probe',
            'status' => 'draft',
        ]);

        $form = Form::query()->find('id', (int) $campaign->default_form_id);

        $this->assertNotNull($form, 'a campaign is created with a default form');
        $this->assertStringContainsString('wp:dono/submit-button', (string) $form->blocks);
        $this->assertStringContainsString(
            'wp:dono/payment-gateways',
            (string) $form->blocks,
            'the starter form must ask how to pay, like every template does'
        );
    }

    /**
     * The recap used to be drawn by the submit step, so it always sat directly
     * above the button whatever the author wanted. It is a block now, which
     * means a template that forgot it ships a form that never shows the donor
     * what they are about to give.
     */
    public function test_every_template_that_submits_also_recaps(): void
    {
        foreach (FormTemplates::all() as $id => $template) {
            $blocks = (string) ($template['blocks'] ?? '');
            if (! str_contains($blocks, 'dono/submit-button')) continue;

            $this->assertStringContainsString(
                'dono/donation-summary',
                $blocks,
                "template {$id} asks for money without showing the total"
            );
        }
    }
}
