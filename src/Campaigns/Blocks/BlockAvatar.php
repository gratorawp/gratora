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

        $initial = mb_strtoupper(mb_substr($name, 0, 1));
        $hue     = (ord(strtoupper($name[0])) * 47) % 360;

        return sprintf(
            '<span class="dono-avatar" aria-hidden="true" style="background: hsl(%d 52%% 42%%);">%s</span>',
            $hue,
            esc_html($initial)
        );
    }
}
