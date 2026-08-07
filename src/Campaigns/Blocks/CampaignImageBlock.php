<?php

declare(strict_types=1);

namespace Dono\Campaigns\Blocks;

use Dono\Foundation\Helpers\View;

/**
 * Core's featured-image block reads the post, which is only the page a campaign
 * happens to be rendered on. This reads the campaign, so it works on any page.
 */
final class CampaignImageBlock extends CampaignBlock
{
    public function name(): string
    {
        return 'dono/campaign-image';
    }

    public function attributes(): array
    {
        return $this->campaignIdAttr() + [
            'aspectRatio' => ['type' => 'string',  'default' => '16-9'],
            'rounded'     => ['type' => 'boolean', 'default' => true],
            // The cover is usually the element LCP is measured on. An author who
            // places it further down can hand the priority back.
            'priority'    => ['type' => 'boolean', 'default' => true],
        ];
    }

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

    /** Shown only to whoever can act on it; a visitor gets nothing. */
    private function noImageNotice(): string
    {
        if (! is_user_logged_in() || ! current_user_can('edit_posts')) {
            return '';
        }

        return '<div class="dono-block-notice">'
            . esc_html__('This campaign has no cover image yet. Add one in the campaign settings.', 'dono')
            . '</div>';
    }
}
