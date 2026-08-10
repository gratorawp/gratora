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
                    ?: __('Donations are not open for this campaign yet.', 'dono'),
                'notice'    => (is_user_logged_in() && current_user_can('edit_posts'))
                    ? __('This campaign has no published donation form yet.', 'dono')
                    : '',
                'styleVars' => $this->styleVars($campaign),
            ]);
        }

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
                    ?: __('Donations are not open for this campaign yet.', 'dono'),
                'notice'    => (is_user_logged_in() && current_user_can('edit_posts'))
                    ? __('This campaign is not accepting donations, so the form is hidden. Publish the campaign and check its schedule.', 'dono')
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
}
