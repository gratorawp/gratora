<?php

declare(strict_types=1);

namespace Dono\Campaigns\Blocks;

use Dono\Forms\FormRepository;
use Dono\Foundation\Helpers\View;

/**
 * Renders the donate button and its inline form modal.
 *
 * @since 1.0.0
 */
final class DonateButtonBlock extends CampaignBlock
{
    /** @since 1.0.0 */
    public function __construct(
        \Dono\Campaigns\CampaignRepository $campaigns,
        private readonly FormRepository $forms,
    ) {
        parent::__construct($campaigns);
    }

    /** @since 1.0.0 */
    public function name(): string
    {
        return 'dono/donate-button';
    }

    /** @since 1.0.0 */
    public function attributes(): array
    {
        return $this->campaignIdAttr() + [
            'label'     => ['type' => 'string',  'default' => ''],
            'align'     => ['type' => 'string',  'default' => 'left'],
            'size'      => ['type' => 'string',  'default' => 'md'],
            'fullWidth' => ['type' => 'boolean', 'default' => false],
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

        // No published form: nothing for visitors (a dead disabled button reads
        // as broken), an editor-only notice for anyone who can fix it.
        if (! $form) {
            return (is_user_logged_in() && current_user_can('edit_posts'))
                ? '<div class="dono-block-notice">'
                    . esc_html__('This campaign has no published donation form yet.', 'dono-fundraising-platform')
                    . '</div>'
                : '';
        }

        // do_shortcode renders the form HTML inline so the modal can show it
        // without an extra network roundtrip. Skipped in the block-editor
        // preview to avoid booting the form runtime inside the editor frame.
        $editorPreview = $this->isBlockRendererRequest();
        $formHtml      = '';
        if (! $editorPreview) {
            $formHtml = do_shortcode('[dono_donation_form slug="' . esc_attr($form->slug) . '"]');
        }

        // The form gate renders no form while the campaign sits outside its
        // schedule, and the view only emits the modal alongside form HTML, so
        // a button here would open nothing at all.
        //
        // Asked of the markup rather than of emptiness: the gate also returns a
        // short explanation to anyone who can manage Dono, and a button opening
        // that is no better than a button opening nothing.
        $hasForm = str_contains($formHtml, 'data-form-slug=');
        if (! $editorPreview && ! $hasForm) {
            $message = $campaign->notAcceptingReason() === 'ended'
                ? __('This campaign has finished accepting donations.', 'dono-fundraising-platform')
                : __('Donations are not open for this campaign yet.', 'dono-fundraising-platform');

            $notice = (is_user_logged_in() && current_user_can('edit_posts'))
                ? '<div class="dono-block-notice">'
                    . esc_html__('This campaign is not accepting donations, so the donate button is hidden. Publish the campaign and check its schedule.', 'dono-fundraising-platform')
                    . '</div>'
                : '';

            return '<p class="dono-block__empty">' . esc_html($message) . '</p>' . $notice;
        }

        return View::loadRelative(__DIR__, 'views/donate-button', [
            // ?: not ??: the attribute exists and is an empty string when the
            // organizer has not renamed it, so ?? would hand the view ''.
            'label'        => (string) ($attrs['label'] ?? '') ?: __('Donate now', 'dono-fundraising-platform'),
            'align'        => (string) ($attrs['align'] ?? 'left'),
            'size'         => in_array($attrs['size'] ?? 'md', ['sm', 'md', 'lg'], true)
                ? (string) $attrs['size'] : 'md',
            'fullWidth'    => (bool) ($attrs['fullWidth'] ?? false),
            'formSlug'     => $form?->slug,
            'formHtml'     => $formHtml,
            'styleVars' => $this->styleVars($campaign),
        ]);
    }

    /**
     * Whether this render is the block editor asking for its own preview.
     *
     * REST_REQUEST alone cannot answer that: WP defines it for every /wp-json
     * call, and a public read of a published page runs the_content ->
     * do_blocks, so an anonymous reader would be served a button with no modal
     * behind it and no closed-campaign explanation. ServerSideRender always
     * calls /wp/v2/block-renderer/, whose core permission check requires edit
     * access; the capability is re-checked here so nothing but an editor can
     * reach it.
     *
     * Re-checked the way the route itself checks, against the post being
     * edited when ServerSideRender names one. A stricter test would fail for
     * someone core already let through, an editor of pages but not of posts,
     * and drop the live front-end form into their editor canvas.
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
