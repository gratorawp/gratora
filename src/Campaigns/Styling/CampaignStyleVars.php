<?php

declare(strict_types=1);

namespace Dono\Campaigns\Styling;

use Dono\Campaigns\Campaign;

/**
 * A campaign's style is a map of 26 tokens, not one colour.
 *
 * The campaign blocks emitted only `--dono-accent`, and `campaign-stats` emitted
 * nothing at all, while their stylesheet reads `--dono-text`, `--dono-bg`,
 * `--dono-border` and `--dono-radius` 73 times between them. Those four are
 * defined in `donation-form/runtime.scss` and nowhere else, so a campaign's
 * Corner radius, Background and Text settings reached the donation form and
 * stopped there: every other block on the page fell back to the Sass literals.
 * The page also carries a `dono-campaign-styled` body class that only the P2P
 * add-on ever defined, so on a standard campaign it promised a rule that was
 * never printed.
 *
 * This emits the whole resolved map as inline custom properties on a block's
 * wrapper. The stylesheet reads them with the design's own values as fallbacks,
 * so an unstyled campaign renders exactly as designed and a styled one carries
 * its identity across every block.
 */
final class CampaignStyleVars
{
    /** @var array<int,string> resolved declarations, keyed by campaign id */
    private static array $cache = [];

    /**
     * Inline custom properties for a campaign, ready for a style attribute.
     * Escape at the point of output, as any attribute value must be.
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

    /** Tests seed campaigns per case, so the per-request cache has to be clearable. */
    public static function flush(): void
    {
        self::$cache = [];
    }
}
