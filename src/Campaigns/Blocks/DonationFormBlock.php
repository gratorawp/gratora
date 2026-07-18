<?php

declare(strict_types=1);

namespace Dono\Campaigns\Blocks;

use Dono\Forms\FormRepository;
use Dono\Forms\Shortcode\DonationFormShortcode;
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
        private readonly ?DonationFormShortcode $shortcode = null,
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

        // Editor preview: the block renderer runs as a REST request (is_admin()
        // is false there), so detect it via REST_REQUEST. ServerSideRender injects
        // the response as raw HTML and never runs its scripts, so the live runtime
        // can't mount in the editor frame directly. Instead render the real form
        // into an iframe srcdoc (its own browsing context boots the runtime in
        // isolation) built through the preview path, whose stub slug makes the
        // form non-submittable from the editor. The real form renders on the front.
        if (defined('REST_REQUEST') && REST_REQUEST) {
            $previewDoc = '';
            if ($this->shortcode !== null) {
                $preview = $this->shortcode->renderPreview(
                    (string) $form->blocks,
                    is_array($form->settings) ? $form->settings : null,
                    (int) $form->campaign_id,
                );
                $previewDoc = $this->shortcode->buildPreviewDocument($preview, autoResize: true, transparent: true);
            }

            return View::loadRelative(__DIR__, 'views/donation-form', [
                'mode'         => 'editor',
                'previewDoc'   => $previewDoc,
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
