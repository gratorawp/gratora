<?php

declare(strict_types=1);

namespace Dono\Campaigns\Blocks;

use Dono\Campaigns\CampaignRepository;
use Dono\Forms\FormRepository;
use Dono\Forms\Shortcode\DonationFormShortcode;
use Dono\Foundation\Helpers\View;

/**
 * Renders a campaign's donation form inline on the page (the in-page
 * counterpart to the donate-button modal).
 *
 * @since 1.0.0
 */
final class DonationFormBlock extends CampaignBlock
{
    /** @since 1.0.0 */
    public function __construct(
        CampaignRepository $campaigns,
        private readonly FormRepository $forms,
        private readonly ?DonationFormShortcode $shortcode = null,
    ) {
        parent::__construct($campaigns);
    }

    /** @since 1.0.0 */
    public function name(): string
    {
        return 'dono/donation-form';
    }

    /** @since 1.0.0 */
    public function attributes(): array
    {
        return $this->campaignIdAttr() + [
            'emptyText' => ['type' => 'string', 'default' => ''],
        ];
    }

    /** @since 1.0.0 */
    public function render(array $attrs, string $content): string
    {
        $campaign = $this->resolveCampaign($attrs);
        if (! $campaign) return $this->notBoundNotice($attrs);

        $form = $this->forms->publishedForCampaign(
            (int) $campaign->id,
            $campaign->default_form_id ? (int) $campaign->default_form_id : null
        );

        // Renders something even with no form. A heading seeded above this
        // block would otherwise caption whatever came next.
        if (! $form) {
            return View::loadRelative(__DIR__, 'views/donation-form', [
                'mode'      => 'empty',
                'emptyText' => (string) ($attrs['emptyText'] ?? '')
                    ?: __('Donations are not open for this campaign yet.', 'dono-fundraising-platform'),
                'notice'    => (is_user_logged_in() && current_user_can('edit_posts'))
                    ? __('This campaign has no published donation form yet.', 'dono-fundraising-platform')
                    : '',
                'styleVars' => $this->styleVars($campaign),
            ]);
        }

        // Editor preview: ServerSideRender injects the response as raw HTML and
        // never runs its scripts, so the live runtime can't mount in the editor
        // frame directly. Instead render the form into an iframe srcdoc, whose
        // own browsing context boots the runtime in isolation. The real form
        // renders on the front.
        if ($this->isBlockRendererRequest()) {
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
                'styleVars' => $this->styleVars($campaign),
            ]);
        }

        // A published form still renders nothing when the campaign itself is
        // not taking donations, a draft or one outside its schedule. Having a
        // form row is not the same as having something to show.
        $formHtml = do_shortcode('[dono_donation_form slug="' . esc_attr($form->slug) . '"]');
        if (trim($formHtml) === '') {
            return View::loadRelative(__DIR__, 'views/donation-form', [
                'mode'      => 'empty',
                'emptyText' => (string) ($attrs['emptyText'] ?? '')
                    ?: __('Donations are not open for this campaign yet.', 'dono-fundraising-platform'),
                'notice'    => (is_user_logged_in() && current_user_can('edit_posts'))
                    ? __('This campaign is not accepting donations, so the form is hidden. Publish the campaign and check its schedule.', 'dono-fundraising-platform')
                    : '',
                'styleVars' => $this->styleVars($campaign),
            ]);
        }

        return View::loadRelative(__DIR__, 'views/donation-form', [
            'mode'         => 'front',
            'formHtml'     => $formHtml,
            'styleVars' => $this->styleVars($campaign),
        ]);
    }

    /**
     * Whether this render is the block editor asking for its own preview.
     *
     * REST_REQUEST alone cannot answer that: WP defines it for every /wp-json
     * call, and a public read of a published page runs the_content ->
     * do_blocks, so an anonymous reader would be served the editor preview
     * document instead of the form. ServerSideRender always calls
     * /wp/v2/block-renderer/, whose core permission check requires edit access;
     * the capability is re-checked here so nothing but an editor can reach it.
     *
     * @since 1.0.0
     */
    private function isBlockRendererRequest(): bool
    {
        $route = $GLOBALS['wp']->query_vars['rest_route'] ?? null;
        if (! is_string($route) || ! str_starts_with(ltrim($route, '/'), 'wp/v2/block-renderer/')) {
            return false;
        }

        return current_user_can('edit_posts');
    }
}
