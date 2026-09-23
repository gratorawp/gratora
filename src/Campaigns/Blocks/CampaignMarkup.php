<?php

declare(strict_types=1);

namespace Gratora\Campaigns\Blocks;

use Gratora\Forms\Rendering\FormMarkup;

/**
 * The HTML a campaign page block's own markup may hold.
 *
 * @since 1.0.0
 */
final class CampaignMarkup
{
    private const PAGE_ATTRIBUTES = [
        'circle' => [
            'cx' => true,
            'cy' => true,
            'r'  => true,
        ],
        'div'    => [
            'aria-modal'    => true,
            'aria-valuemax' => true,
            'aria-valuemin' => true,
            'aria-valuenow' => true,
        ],
        'img'    => [
            'decoding'      => true,
            'fetchpriority' => true,
            'sizes'         => true,
            'srcset'        => true,
        ],
        'path'   => [
            'd'    => true,
            'fill' => true,
        ],
        'rect'   => [
            'height' => true,
            'rx'     => true,
            'width'  => true,
            'x'      => true,
            'y'      => true,
        ],
        'span'   => [
            'aria-valuemax' => true,
            'aria-valuemin' => true,
            'aria-valuenow' => true,
        ],
        'svg'    => [
            'fill'            => true,
            'height'          => true,
            'stroke'          => true,
            'stroke-linecap'  => true,
            'stroke-linejoin' => true,
            'stroke-width'    => true,
            'viewbox'         => true,
            'width'           => true,
        ],
    ];

    /**
     * Post content plus the icons, responsive images and ARIA state the page
     * blocks draw.
     *
     * @return array<string, array<string, mixed>>
     *
     * @since 1.0.0
     */
    public static function allowedHtml(): array
    {
        $tags = wp_kses_allowed_html('post');

        foreach (self::PAGE_ATTRIBUTES as $tag => $attributes) {
            $existing   = is_array($tags[$tag] ?? null) ? $tags[$tag] : [];
            $tags[$tag] = array_merge(FormMarkup::GLOBAL_ATTRIBUTES, $existing, $attributes);
        }

        return $tags;
    }
}
