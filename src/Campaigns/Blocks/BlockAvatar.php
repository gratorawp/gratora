<?php

declare(strict_types=1);

namespace Dono\Campaigns\Blocks;

/**
 * Tiny circular initial-avatar used by the donor-activity blocks. The hue is
 * derived deterministically from the name so the same donor keeps the same
 * colour. Decorative (aria-hidden); the donor name is always present as text.
 *
 * @version 1.0.0
 */
final class BlockAvatar
{
    public static function markup(string $name, bool $anonymous = false): string
    {
        $name = trim($name);
        if ($anonymous || $name === '') {
            return '<span class="dono-avatar dono-avatar--anon" aria-hidden="true">?</span>';
        }

        // Decoded and letter-first, matching DonoP2P\Blocks\Initials. Names
        // are stored HTML-encoded, so a donor written with quotes put an
        // ampersand in the circle, and all of them shared one hue.
        $decoded = html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $initial = preg_match('/\p{L}|\p{N}/u', $decoded, $m) === 1
            ? mb_strtoupper($m[0])
            : mb_strtoupper(mb_substr($decoded, 0, 1));
        // From the same character the avatar shows, not from the first byte.
        // ord() on a UTF-8 string reads the lead byte, which is shared across a
        // whole range: every name beginning with a Latin letter carrying a
        // diacritic came out the same colour, as did every CJK name, while the
        // letter drawn on top was correct.
        $hue = ((mb_ord($initial, 'UTF-8') ?: 0) * 47) % 360;

        return sprintf(
            '<span class="dono-avatar" aria-hidden="true" style="background: hsl(%d 52%% 42%%);">%s</span>',
            $hue,
            esc_html($initial)
        );
    }
}
