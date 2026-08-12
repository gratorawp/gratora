<?php

declare(strict_types=1);

namespace Dono\Forms\Blocks;

use Dono\Foundation\Helpers\View;

/**
 * Toggle that lets a donor make the donation anonymous.
 *
 * @since 1.0.0
 */
final class AnonymousToggleBlock implements Block
{
    /** @since 1.0.0 */
    public function name(): string
    {
        return 'dono/anonymous-toggle';
    }

    /** @since 1.0.0 */
    public function attributes(): array
    {
        return [
            'label'      => ['type' => 'string',  'default' => ''],
            'defaultOn'  => ['type' => 'boolean', 'default' => false],
        ];
    }

    /** @since 1.0.0 */
    public function render(array $attrs, string $content): string
    {
        // OR the org always-anonymous default in, matching the walker.
        $privacyCfg    = get_option('dono_privacy', []);
        $globalDefault = is_array($privacyCfg) && ! empty($privacyCfg['always_anonymous_default']);

        return View::loadRelative(__DIR__, 'views/anonymous-toggle', [
            'label'     => (string) ($attrs['label']     ?? '') ?: __('Make this donation anonymous', 'dono-fundraising-platform'),
            'defaultOn' => (bool)   ($attrs['defaultOn'] ?? false) || $globalDefault,
        ]);
    }
}
