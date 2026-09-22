<?php

declare(strict_types=1);

namespace Gratora\Campaigns\Blocks;

use Gratora\Campaigns\CampaignRepository;
use Gratora\Forms\FormRepository;
use Gratora\Forms\Shortcode\DonationFormShortcode;
use Gratora\Foundation\Helpers\View;

/** @since 1.0.0 */
final class DonateButtonBlock extends CampaignFormBlock
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
    public function render(array $attrs): EmbeddedForm
    {
        $campaign = $this->resolveCampaign($attrs);
        if (! $campaign) return new EmbeddedForm($this->notBoundNotice($attrs));

        $form = $this->forms->publishedForCampaign(
            (int) $campaign->id,
            $campaign->default_form_id ? (int) $campaign->default_form_id : null
        );

        // Show missing-form notices only to editors.
        if (! $form) {
            return new EmbeddedForm((is_user_logged_in() && current_user_can('edit_posts'))
                ? '<div class="gratora-block-notice">'
                    . esc_html__('This campaign has no published donation form yet.', 'gratora-donation-platform')
                    . '</div>'
                : '');
        }

        // The modal carries the form inline so it opens without a network
        // roundtrip. The block-editor preview leaves it out rather than boot the
        // form runtime inside the editor frame.
        $editorPreview = $this->isBlockRendererRequest();

        // A button is only worth drawing over a form the gate will render. A
        // closed campaign, or a form a manager is told is hidden, would give a
        // button that opens a notice or nothing at all.
        if (! $editorPreview && $this->shortcode->gate($form) !== 'render') {
            $message = match ($campaign->notAcceptingReason()) {
                'ended'    => __('This campaign has finished accepting donations.', 'gratora-donation-platform'),
                'goal_met' => __('This campaign has reached its goal. Thank you.', 'gratora-donation-platform'),
                default    => __('Donations are not open for this campaign yet.', 'gratora-donation-platform'),
            };

            return new EmbeddedForm('<p class="gratora-block__empty">' . esc_html($message) . '</p>'
                . ((is_user_logged_in() && current_user_can('edit_posts'))
                    ? '<div class="gratora-block-notice">'
                        . esc_html__('This campaign is not accepting donations, so the donate button is hidden. Publish the campaign and check its schedule.', 'gratora-donation-platform')
                        . '</div>'
                    : ''));
        }

        $before = View::loadRelative(__DIR__, 'views/donate-button', [
            'part'         => 'before',
            // Use ?: because an unset label is an empty string.
            'label'        => (string) ($attrs['label'] ?? '') ?: __('Donate now', 'gratora-donation-platform'),
            'align'        => (string) ($attrs['align'] ?? 'left'),
            'size'         => in_array($attrs['size'] ?? 'md', ['sm', 'md', 'lg'], true)
                ? (string) $attrs['size'] : 'md',
            'fullWidth'    => (bool) ($attrs['fullWidth'] ?? false),
            'formSlug'     => $form->slug,
            'withForm'     => ! $editorPreview,
            'styleVars'    => $this->styleVars($campaign),
        ]);

        return $editorPreview
            ? new EmbeddedForm($before)
            : new EmbeddedForm($before, formSlug: (string) $form->slug, after: View::loadRelative(__DIR__, 'views/donate-button', ['part' => 'after']));
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

        $postId = isset($_GET['post_id']) ? (int) $_GET['post_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- names the post the block renderer previews; core's route has already checked edit_post for it, and nothing is written.

        return $postId > 0
            ? current_user_can('edit_post', $postId)
            : current_user_can('edit_posts');
    }
}
