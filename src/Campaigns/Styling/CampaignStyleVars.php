<?php

declare(strict_types=1);

namespace FundKit\Campaigns\Styling;

use FundKit\Campaigns\Campaign;

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

        // resolveForCampaign applies fundkit.campaign_style.tokens after merging,
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

        // Derived, not authored: nothing in the catalogue knows what the accent
        // is dark enough to need. Appended last so a filter cannot leave a
        // filled panel reversing white out of a pale accent.
        $css .= Ink::declarationsFor((string) ($tokens['fundkit-accent'] ?? ''));
        $css .= Ink::softDeclarations($tokens);
        $css .= Ink::fieldDeclarations($tokens);
        $css .= self::coverImage($campaign);

        return self::$cache[$id] = $css;
    }

    /**
     * The campaign's own photograph, as a value a stylesheet can use.
     *
     * A layout that wants the image as its ground reads this instead of placing
     * the image block and positioning it. The block carries an editor wrapper
     * that the editor itself owns and lays out, and a background has none, so
     * the canvas and the front end cannot disagree about it.
     *
     * @since 1.0.0
     */
    private static function coverImage(?Campaign $campaign): string
    {
        $id = $campaign ? (int) ($campaign->image_attachment_id ?? 0) : 0;
        if ($id <= 0) {
            return '';
        }

        $url = wp_get_attachment_image_url($id, '2048x2048');
        if (! is_string($url) || $url === '') {
            return '';
        }

        // A url() token, not a bare address: the stylesheet uses it directly and
        // the parentheses are what keep a stray one from ending the declaration.
        return '--fundkit-cover-image:url(' . esc_url_raw($url) . ');';
    }

    /**
     * Clear the per-request cache between test fixtures.
     *
     * @since 1.0.0
     */
    public static function flush(): void
    {
        self::$cache = [];
    }
}
