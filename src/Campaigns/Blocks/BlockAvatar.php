<?php

declare(strict_types=1);

namespace Gratora\Campaigns\Blocks;

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
    public static function render(string $name, bool $anonymous = false, string $imageUrl = ''): void
    {
        $name = trim($name);
        if ($anonymous || $name === '') {
            echo '<span class="gratora-avatar gratora-avatar--anon" aria-hidden="true">?</span>';
            return;
        }

        // Decode stored HTML entities before selecting the initial.
        $decoded = html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $initial = preg_match('/\p{L}|\p{N}/u', $decoded, $m) === 1
            ? mb_strtoupper($m[0])
            : mb_strtoupper(mb_substr($decoded, 0, 1));
        // Use mb_ord so multibyte initials have distinct hues.
        $hue = ((mb_ord($initial, 'UTF-8') ?: 0) * 47) % 360;

        printf(
            '<span class="gratora-avatar" aria-hidden="true" style="background: hsl(%d 52%% 42%%);">%s',
            (int) $hue,
            esc_html($initial)
        );

        // The picture layers over the initial rather than replacing it:
        // Gravatar is asked for a transparent image when it has none on file,
        // so a donor without one keeps their colored letter.
        if ($imageUrl !== '') {
            printf(
                '<img class="gratora-avatar__photo" src="%s" alt="" loading="lazy" decoding="async">',
                esc_url($imageUrl)
            );
        }

        echo '</span>';
    }
}
