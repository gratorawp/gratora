<?php

declare(strict_types=1);

namespace Dono\Campaigns\Styling;

use Dono\Campaigns\Campaign;

/**
 * A campaign's style is a map of 26 tokens, not one color. This emits the
 * whole resolved map as inline custom properties on a block wrapper; the
 * stylesheets read them with the design's own values as fallbacks, so an
 * unstyled campaign renders as designed and a styled one carries its identity
 * across every block.
 *
 * @since 1.0.0
 */
final class CampaignStyleVars
{
    /** @var array<int,string> resolved declarations, keyed by campaign id */
    private static array $cache = [];

    /**
     * Inline custom properties for a campaign, ready for a style attribute.
     * Escape at the point of output, as any attribute value must be.
     *
     * @since 1.0.0
     */
    public static function forCampaign(?Campaign $campaign): string
    {
        $id = $campaign ? (int) $campaign->id : 0;
        if (isset(self::$cache[$id])) {
            return self::$cache[$id];
        }

        $tokens = (new CampaignStyleResolver())->resolveForCampaign($campaign);

        // resolveForCampaign applies dono.campaign_style.tokens after merging,
        // so whatever a filter returned has not been through the allowlist.
        // These values land verbatim in CSS, where a stray ; or } escapes the
        // declaration, so sanitize once more rather than trusting the filter.
        $tokens = Tokens::sanitize($tokens);

        $css = '';
        foreach ($tokens as $key => $value) {
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }
            $css .= '--' . $key . ':' . $value . ';';
        }

        return self::$cache[$id] = $css;
    }

    /**
     * Tests seed campaigns per case, so the per-request cache has to be clearable.
     *
     * @since 1.0.0
     */
    public static function flush(): void
    {
        self::$cache = [];
    }
}
