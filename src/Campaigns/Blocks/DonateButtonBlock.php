<?php

declare(strict_types=1);

namespace Gratora\Campaigns\Blocks;

use Gratora\Forms\FormRepository;
use Gratora\Foundation\Helpers\View;

/** @since 1.0.0 */
final class DonateButtonBlock extends CampaignBlock
{
    /** @since 1.0.0 */
    public function __construct(
        \Gratora\Campaigns\CampaignRepository $campaigns,
        private readonly FormRepository $forms,
    ) {
        parent::__construct($campaigns);
    }

    /** @since 1.0.0 */
    public function name(): string
    {
        return 'gratora/donate-button';
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

        // Show missing-form notices only to editors.
        if (! $form) {
            return (is_user_logged_in() && current_user_can('edit_posts'))
                ? '<div class="gratora-block-notice">'
                    . esc_html__('This campaign has no published donation form yet.', 'gratora-donation-platform')
                    . '</div>'
                : '';
        }

        // do_shortcode renders the form HTML inline so the modal can show it
        // without an extra network roundtrip. Skipped in the block-editor
        // preview to avoid booting the form runtime inside the editor frame.
        $editorPreview = $this->isBlockRendererRequest();
        $formHtml      = '';
        if (! $editorPreview) {
            $formHtml = do_shortcode('[gratora_donation_form slug="' . esc_attr($form->slug) . '"]');
        }

        // The form gate renders no form while the campaign sits outside its
        // schedule, and the view only emits the modal alongside form HTML, so
        // a button here would open nothing at all.
        //
        // Asked of the markup rather than of emptiness: the gate also returns a
        // short explanation to anyone who can manage Gratora, and a button opening
        // that is no better than a button opening nothing.
        $hasForm = str_contains($formHtml, 'data-form-slug=');
        if (! $editorPreview && ! $hasForm) {
            $message = match ($campaign->notAcceptingReason()) {
                'ended'    => __('This campaign has finished accepting donations.', 'gratora-donation-platform'),
                'goal_met' => __('This campaign has reached its goal. Thank you.', 'gratora-donation-platform'),
                default    => __('Donations are not open for this campaign yet.', 'gratora-donation-platform'),
            };

            $notice = (is_user_logged_in() && current_user_can('edit_posts'))
                ? '<div class="gratora-block-notice">'
                    . esc_html__('This campaign is not accepting donations, so the donate button is hidden. Publish the campaign and check its schedule.', 'gratora-donation-platform')
                    . '</div>'
                : '';

            return '<p class="gratora-block__empty">' . esc_html($message) . '</p>' . $notice;
        }

        return View::loadRelative(__DIR__, 'views/donate-button', [
            // Use ?: because an unset label is an empty string.
            'label'        => (string) ($attrs['label'] ?? '') ?: __('Donate now', 'gratora-donation-platform'),
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
     * Require the block-renderer route and permission to edit its post; REST_REQUEST also
     * covers public renders.
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
