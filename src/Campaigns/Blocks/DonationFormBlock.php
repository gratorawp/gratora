<?php

declare(strict_types=1);

namespace Gratora\Campaigns\Blocks;

use Gratora\Campaigns\CampaignRepository;
use Gratora\Forms\FormRepository;
use Gratora\Forms\Shortcode\DonationFormShortcode;
use Gratora\Foundation\Helpers\View;

/** @since 1.0.0 */
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
        return 'gratora/donation-form';
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

        // Keep the seeded heading from captioning unrelated content.
        if (! $form) {
            return View::loadRelative(__DIR__, 'views/donation-form', [
                'mode'      => 'empty',
                'emptyText' => (string) ($attrs['emptyText'] ?? '')
                    ?: __('Donations are not open for this campaign yet.', 'gratora-donation-platform'),
                'notice'    => (is_user_logged_in() && current_user_can('edit_posts'))
                    ? __('This campaign has no published donation form yet.', 'gratora-donation-platform')
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
        $formHtml = do_shortcode('[gratora_donation_form slug="' . esc_attr($form->slug) . '"]');
        if (trim($formHtml) === '') {
            return View::loadRelative(__DIR__, 'views/donation-form', [
                'mode'      => 'empty',
                'emptyText' => (string) ($attrs['emptyText'] ?? '')
                    ?: __('Donations are not open for this campaign yet.', 'gratora-donation-platform'),
                'notice'    => (is_user_logged_in() && current_user_can('edit_posts'))
                    ? __('This campaign is not accepting donations, so the form is hidden. Publish the campaign and check its schedule.', 'gratora-donation-platform')
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
     * Require the block-renderer route and permission to edit its post. REST_REQUEST also
     * covers public content renders and cannot identify editor previews.
     *
     * @since 1.0.0
     */
    private function isBlockRendererRequest(): bool
    {
        $route = $GLOBALS['wp']->query_vars['rest_route'] ?? null;
        if (! is_string($route) || ! str_starts_with(ltrim($route, '/'), 'wp/v2/block-renderer/')) {
            return false;
        }

        $postId = isset($_GET['post_id']) ? (int) $_GET['post_id'] : 0;

        return $postId > 0
            ? current_user_can('edit_post', $postId)
            : current_user_can('edit_posts');
    }
}
