<?php

declare(strict_types=1);

namespace Dono\Forms\Blocks;

use Dono\Foundation\Helpers\View;

/** @since 1.0.0 */
final class SubmitButtonBlock implements Block
{
    /** @since 1.0.0 */
    public function name(): string
    {
        return 'dono/submit-button';
    }

    /** @since 1.0.0 */
    public function attributes(): array
    {
        return [
            'label'       => ['type' => 'string',  'default' => ''],
            'align'       => ['type' => 'string',  'default' => 'left'],
        ];
    }

    /** @since 1.0.0 */
    public function render(array $attrs, string $content): string
    {
        $align = (string) ($attrs['align'] ?? 'left');
        if (! in_array($align, ['left', 'center', 'right', 'full'], true)) {
            $align = 'left';
        }

        return View::loadRelative(__DIR__, 'views/submit-button', [
            'label' => (string) ($attrs['label'] ?? '') ?: __('Donate now', 'dono-fundraising-platform'),
            'align' => $align,
        ]);
    }
}
