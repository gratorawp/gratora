<?php

declare(strict_types=1);

namespace Gratora\Campaigns\Blocks;

use Gratora\Campaigns\Campaign;
use Gratora\Campaigns\CampaignRepository;
use Gratora\Forms\FormRepository;
use Gratora\Forms\Shortcode\DonationFormShortcode;
use Gratora\Foundation\Helpers\View;

/** @since 1.0.0 */
final class DonationFormBlock extends CampaignFormBlock
{
    /** @since 1.0.0 */
    public function __construct(
        CampaignRepository $campaigns,
        private readonly FormRepository $forms,
        private readonly DonationFormShortcode $shortcode,
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
    public function render(array $attrs): EmbeddedForm
    {
        $campaign = $this->resolveCampaign($attrs);
        if (! $campaign) return new EmbeddedForm($this->notBoundNotice($attrs));

        $form = $this->forms->publishedForCampaign(
            (int) $campaign->id,
            $campaign->default_form_id ? (int) $campaign->default_form_id : null
        );

        // Keep the seeded heading from captioning unrelated content.
        if (! $form) {
            [$before, $after] = $this->wrapper($campaign, [
                'emptyText' => (string) ($attrs['emptyText'] ?? '')
                    ?: __('Donations are not open for this campaign yet.', 'gratora-donation-platform'),
                'notice'    => (is_user_logged_in() && current_user_can('edit_posts'))
                    ? __('This campaign has no published donation form yet.', 'gratora-donation-platform')
                    : '',
            ]);

            return new EmbeddedForm($before, after: $after);
        }

        // Editor preview: ServerSideRender injects the response as raw HTML and
        // never runs its scripts, so the live runtime can't mount in the editor
        // frame directly. Instead render the form into an iframe srcdoc, whose
        // own browsing context boots the runtime in isolation. The real form
        // renders on the front.
        if ($this->isBlockRendererRequest()) {
            $preview = $this->shortcode->renderPreview(
                (string) $form->blocks,
                is_array($form->settings) ? $form->settings : null,
                (int) $form->campaign_id,
            );
            $document = $this->shortcode->buildPreviewDocument($preview, autoResize: true, transparent: true);

            [$before, $after] = $this->wrapper($campaign);

            return new EmbeddedForm($before, previewDocument: $document, previewTitle: (string) $form->title, after: $after);
        }

        // A hidden form is empty for a visitor. The shortcode tells a manager
        // why, so only everyone else gets this block's own empty card.
        if ($this->shortcode->gate($form) === 'hidden' && ! DonationFormShortcode::showsReasons()) {
            [$before, $after] = $this->wrapper($campaign, [
                'emptyText' => (string) ($attrs['emptyText'] ?? '')
                    ?: __('Donations are not open for this campaign yet.', 'gratora-donation-platform'),
                'notice'    => (is_user_logged_in() && current_user_can('edit_posts'))
                    ? __('This campaign is not accepting donations, so the form is hidden. Publish the campaign and check its schedule.', 'gratora-donation-platform')
                    : '',
            ]);

            return new EmbeddedForm($before, after: $after);
        }

        [$before, $after] = $this->wrapper($campaign);

        return new EmbeddedForm($before, formSlug: (string) $form->slug, after: $after);
    }

    /**
     * @param array{emptyText?: string, notice?: string} $emptyCard
     * @return array{string, string}
     *
     * @since 1.1.0
     */
    private function wrapper(Campaign $campaign, array $emptyCard = []): array
    {
        return [
            View::loadRelative(__DIR__, 'views/donation-form', [
                'part'      => 'before',
                'styleVars' => $this->styleVars($campaign),
            ] + $emptyCard),
            View::loadRelative(__DIR__, 'views/donation-form', ['part' => 'after']),
        ];
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

        $postId = isset($_GET['post_id']) ? (int) $_GET['post_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- names the post the block renderer previews; core's route has already checked edit_post for it, and nothing is written.

        return $postId > 0
            ? current_user_can('edit_post', $postId)
            : current_user_can('edit_posts');
    }
}
