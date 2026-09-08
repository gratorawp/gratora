<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Campaigns\CampaignService;
use FundKit\Forms\Form;
use FundKit\Forms\FormTemplates;
use FundKit\Foundation\Plugin;

final class FormTemplatesSingletonTest extends IntegrationTestCase
{
    /** Block names whose JS registration sets `supports.multiple = false`. */
    private const SINGLETONS = [
        'fundkit/fund-picker',
        'fundkit/anonymous-toggle',
        'fundkit/privacy-notice',
        'fundkit/comment',
        'fundkit/cover-fees',
        'fundkit/submit-button',
        'fundkit/donation-amount',
        'fundkit/donation-summary',
        'fundkit/payment-gateways',
        'fundkit/consent',
        'fundkit/currency-switcher',
        'fundkit/steps',
        'fundkit/phone',
        'fundkit/address',
        'fundkit/name',
        'fundkit/email',
        'fundkit/country',
        'fundkit/recurring-toggle',
        'fundkit/goal',
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

    public function test_every_submittable_template_places_the_payment_gateways_block(): void
    {
        $missing = [];
        foreach (FormTemplates::all() as $template) {
            $blocks = (string) ($template['blocks'] ?? '');
            if (! str_contains($blocks, 'wp:fundkit/submit-button')) {
                continue;   // Blank ships no markup at all, by design.
            }
            if (! str_contains($blocks, 'wp:fundkit/payment-gateways')) {
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
        $this->assertStringContainsString('wp:fundkit/submit-button', (string) $form->blocks);
        $this->assertStringContainsString(
            'wp:fundkit/payment-gateways',
            (string) $form->blocks,
            'the starter form must ask how to pay, like every template does'
        );
    }

    public function test_every_template_that_submits_also_recaps(): void
    {
        foreach (FormTemplates::all() as $id => $template) {
            $blocks = (string) ($template['blocks'] ?? '');
            if (! str_contains($blocks, 'fundkit/submit-button')) continue;

            $this->assertStringContainsString(
                'fundkit/donation-summary',
                $blocks,
                "template {$id} asks for money without showing the total"
            );
        }
    }
}
