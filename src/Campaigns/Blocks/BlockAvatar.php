<?php

declare(strict_types=1);

namespace Dono\Campaigns\Blocks;

/**
 * Tiny circular initial-avatar used by the donor-activity blocks. The hue is
 * derived deterministically from the name so the same donor keeps the same
 * color. Decorative (aria-hidden); the donor name is always present as text.
 *
 * @since 1.0.0
 */
final class BlockAvatar
{
    /** @since 1.0.0 */
    public static function markup(string $name, bool $anonymous = false, string $imageUrl = ''): string
    {
        $name = trim($name);
        if ($anonymous || $name === '') {
            return '<span class="dono-avatar dono-avatar--anon" aria-hidden="true">?</span>';
        }


        // Names are stored HTML-encoded, so decode before picking the
        // initial. Letter-first, matching DonoP2P\Blocks\Initials.
        $decoded = html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $initial = preg_match('/\p{L}|\p{N}/u', $decoded, $m) === 1
            ? mb_strtoupper($m[0])
            : mb_strtoupper(mb_substr($decoded, 0, 1));
        // Hue comes from the same character the avatar draws. mb_ord, not
        // ord: ord reads the UTF-8 lead byte, which a whole range shares.
        $hue = ((mb_ord($initial, 'UTF-8') ?: 0) * 47) % 360;

        // The picture layers over the initial rather than replacing it:
        // Gravatar is asked for a transparent image when it has none on file,
        // so a donor without one keeps their colored letter.
        $photo = $imageUrl === '' ? '' : sprintf(
            '<img class="dono-avatar__photo" src="%s" alt="" loading="lazy" decoding="async">',
            esc_url($imageUrl)
        );

        return sprintf(
            '<span class="dono-avatar" aria-hidden="true" style="background: hsl(%d 52%% 42%%);">%s%s</span>',
            $hue,
            esc_html($initial),
            $photo
        );
    }
}
