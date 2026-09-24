<?php

declare(strict_types=1);

namespace Gratora\Campaigns\Styling;

use Gratora\Campaigns\Campaign;

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

    /** @since 1.0.0 */
    public static function register(): void
    {
        add_filter('safecss_filter_attr_allow_css', [self::class, 'allowColorFunctions'], 10, 2);
    }

    /**
     * safecss_filter_attr() removes the CSS functions it knows before testing a
     * declaration for a stray '(', and the colour functions are not among them,
     * so every rgba() in a style attribute is dropped: both shadows and all the
     * measured ink on a block wrapper, and the panel colours a form section
     * carries. This removes them the same way and then applies core's own test
     * to what is left, so a colour function is treated exactly as var() and
     * calc() already are and nothing else loosens.
     *
     * The body is digits and separators only, and for hsl() the words CSS gives
     * a hue, so rgb(url(x)) is not a colour function, is not removed, and core
     * still rejects it.
     *
     * @since 1.0.0
     */
    public static function allowColorFunctions(mixed $allow, string $declaration): bool
    {
        if ($allow) {
            return true;
        }

        $bare = preg_replace('/\b(?:rgba?\([0-9.,%\/\s-]*\)|hsla?\(' . Tokens::HSL_ARGS . '*\))/i', '', $declaration);

        return is_string($bare) && preg_match('%[\\\\(&=}]|/\*%', $bare) === 0;
    }

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

        // resolveForCampaign applies gratora.campaign_style.tokens after merging,
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
        $css .= Ink::declarationsFor((string) ($tokens['gratora-accent'] ?? ''));
        $css .= Ink::softDeclarations($tokens);
        $css .= Ink::fieldDeclarations($tokens);
        $css .= self::coverImage($campaign);

        // A pass-through token is unset so it inherits, which is right until this
        // map is written on a block nested in a page that already declared it for
        // another campaign. Stating the fall-through keeps it reading this map.
        foreach (Tokens::inherited() as $key => $value) {
            if (! isset($tokens[$key])) {
                $css .= '--' . $key . ':' . $value . ';';
            }
        }

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
        return '--gratora-cover-image:url(' . esc_url_raw($url) . ');';
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
