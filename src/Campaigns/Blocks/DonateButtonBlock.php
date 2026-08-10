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
                    . esc_html__('This campaign has no published donation form yet.', 'dono')
                    . '</div>'
                : '';
        }

        // do_shortcode renders the form HTML inline so the modal can show it
        // without an extra network roundtrip. Skipped in the block-editor
        // preview (the block renderer is a REST request, where is_admin() is
        // false) to avoid booting the form runtime inside the editor frame.
        $formHtml = '';
        if ($form && ! (defined('REST_REQUEST') && REST_REQUEST)) {
            $formHtml = do_shortcode('[dono_donation_form slug="' . esc_attr($form->slug) . '"]');
        }

        return View::loadRelative(__DIR__, 'views/donate-button', [
            // ?: not ??: the attribute exists and is an empty string when the
            // organizer has not renamed it, so ?? would hand the view ''.
            'label'        => (string) ($attrs['label'] ?? '') ?: __('Donate now', 'dono'),
            'align'        => (string) ($attrs['align'] ?? 'left'),
            'size'         => in_array($attrs['size'] ?? 'md', ['sm', 'md', 'lg'], true)
                ? (string) $attrs['size'] : 'md',
            'fullWidth'    => (bool) ($attrs['fullWidth'] ?? false),
            'formSlug'     => $form?->slug,
            'formHtml'     => $formHtml,
            'styleVars' => $this->styleVars($campaign),
        ]);
    }
}
