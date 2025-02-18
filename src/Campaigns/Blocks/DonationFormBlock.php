<?php

declare(strict_types=1);

namespace Dono\Campaigns\Blocks;

use Dono\Forms\FormRepository;
use Dono\Foundation\Helpers\View;

/**
 * Renders a campaign's donation form inline on the page (the in-page
 * counterpart to the donate-button modal).
 *
 * @version 1.0.0
 */
final class DonationFormBlock extends CampaignBlock
{
    public function __construct(
        \Dono\Campaigns\CampaignRepository $campaigns,
        private readonly FormRepository $forms,
    ) {
        parent::__construct($campaigns);
    }

    public function name(): string
    {
        return 'dono/donation-form';
    }

    public function attributes(): array
    {
        return $this->campaignIdAttr() + [
            'align' => ['type' => 'string', 'default' => 'left'],
        ];
    }

    public function render(array $attrs, string $content): string
    {
        $campaign = $this->resolveCampaign($attrs);
        if (! $campaign) return $this->notBoundNotice();

        $form = $this->forms->publishedForCampaign(
            (int) $campaign->id,
            $campaign->default_form_id ? (int) $campaign->default_form_id : null
        );

        if (! $form) {
            return (is_user_logged_in() && current_user_can('edit_posts'))
                ? '<div class="dono-block-notice">'
                    . esc_html__('This campaign has no published donation form yet.', 'dono')
                    . '</div>'
                : '';
        }

        $align = in_array($attrs['align'] ?? 'left', ['left', 'center'], true)
            ? (string) $attrs['align'] : 'left';

        // Editor preview: a lightweight placeholder. The live form boots a JS
        // runtime we don't want mounting inside the editor frame, and the block
        // renderer runs as a REST request (is_admin() is false there), so detect
        // the preview via REST_REQUEST. The real form renders on the front end.
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return View::loadRelative(__DIR__, 'views/donation-form', [
                'mode'         => 'editor',
                'formTitle'    => (string) $form->title,
                'align'        => $align,
                'themePrimary' => $campaign->accentColor(),
            ]);
        }

        return View::loadRelative(__DIR__, 'views/donation-form', [
            'mode'         => 'front',
            'formHtml'     => do_shortcode('[dono_donation_form slug="' . esc_attr($form->slug) . '"]'),
            'align'        => $align,
            'themePrimary' => $campaign->accentColor(),
        ]);
    }
}
