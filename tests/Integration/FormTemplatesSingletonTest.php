<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Campaigns\CampaignService;
use Gratora\Forms\Form;
use Gratora\Forms\FormTemplates;
use Gratora\Foundation\Plugin;

final class FormTemplatesSingletonTest extends IntegrationTestCase
{
    /** Block names whose JS registration sets `supports.multiple = false`. */
    private const SINGLETONS = [
        'gratora/fund-picker',
        'gratora/anonymous-toggle',
        'gratora/privacy-notice',
        'gratora/comment',
        'gratora/cover-fees',
        'gratora/submit-button',
        'gratora/donation-amount',
        'gratora/donation-summary',
        'gratora/payment-gateways',
        'gratora/consent',
        'gratora/currency-switcher',
        'gratora/steps',
        'gratora/phone',
        'gratora/address',
        'gratora/name',
        'gratora/email',
        'gratora/country',
        'gratora/recurring-toggle',
        'gratora/goal',
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
            if (! str_contains($blocks, 'wp:gratora/submit-button')) {
                continue;   // Blank ships no markup at all, by design.
            }
            if (! str_contains($blocks, 'wp:gratora/payment-gateways')) {
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
        $this->assertStringContainsString('wp:gratora/submit-button', (string) $form->blocks);
        $this->assertStringContainsString(
            'wp:gratora/payment-gateways',
            (string) $form->blocks,
            'the starter form must ask how to pay, like every template does'
        );
    }

    public function test_every_template_that_submits_also_recaps(): void
    {
        foreach (FormTemplates::all() as $id => $template) {
            $blocks = (string) ($template['blocks'] ?? '');
            if (! str_contains($blocks, 'gratora/submit-button')) continue;

            $this->assertStringContainsString(
                'gratora/donation-summary',
                $blocks,
                "template {$id} asks for money without showing the total"
            );
        }
    }
}
