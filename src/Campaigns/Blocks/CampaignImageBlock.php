<?php

declare(strict_types=1);

namespace Gratora\Campaigns\Blocks;

use Gratora\Foundation\Helpers\View;

/**
 * Read the campaign image independently of the containing WP page.
 *
 * @since 1.0.0
 */
final class CampaignImageBlock extends CampaignBlock
{
    /** @since 1.0.0 */
    public function name(): string
    {
        return 'gratora/campaign-image';
    }

    /** @since 1.0.0 */
    public function attributes(): array
    {
        return $this->campaignIdAttr() + [
            'aspectRatio' => ['type' => 'string',  'default' => '16-9'],
            'rounded'     => ['type' => 'boolean', 'default' => true],
            // Prioritize the cover for LCP unless the author opts out.
            'priority'    => ['type' => 'boolean', 'default' => true],
        ];
    }

    /** @since 1.0.0 */
    public function render(array $attrs, string $content): string
    {
        $campaign = $this->resolveCampaign($attrs);
        if (! $campaign) return $this->notBoundNotice($attrs);

        $imageId = (int) ($campaign->image_attachment_id ?? 0);
        if ($imageId <= 0 || ! wp_get_attachment_image_src($imageId, 'large')) {
            return $this->noImageNotice();
        }

        $ratio = (string) ($attrs['aspectRatio'] ?? '16-9');

        return View::loadRelative(__DIR__, 'views/campaign-image', [
            'imageId'   => $imageId,
            'imageAlt'  => (string) $campaign->title,
            'ratio'     => in_array($ratio, ['16-9', '4-3', '1-1', '3-2', 'auto'], true) ? $ratio : '16-9',
            'rounded'   => (bool) ($attrs['rounded']  ?? true),
            'priority'  => (bool) ($attrs['priority'] ?? true),
            'styleVars' => $this->styleVars($campaign),
        ]);
    }

    /** @since 1.0.0 */
    private function noImageNotice(): string
    {
        if (! is_user_logged_in() || ! current_user_can('edit_posts')) {
            return '';
        }

        return '<div class="gratora-block-notice">'
            . esc_html__('This campaign has no cover image yet. Add one in the campaign settings.', 'gratora-donation-platform')
            . '</div>';
    }
}
