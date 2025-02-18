<?php

declare(strict_types=1);

namespace Dono\Forms\Blocks;

use Dono\Foundation\Helpers\View;

/**
 * Form submit button block.
 *
 * @version 1.0.0
 */
final class SubmitButtonBlock implements Block
{
    /**
     * Block type name.
     */
    public function name(): string
    {
        return 'dono/submit-button';
    }

    /**
     * Block attribute schema.
     */
    public function attributes(): array
    {
        return [
            'label'       => ['type' => 'string',  'default' => 'Donate'],
            'align'       => ['type' => 'string',  'default' => 'left'],
            'showSummary' => ['type' => 'boolean', 'default' => true],
        ];
    }

    /**
     * Renders the submit button.
     */
    public function render(array $attrs, string $content): string
    {
        $align = (string) ($attrs['align'] ?? 'left');
        if (! in_array($align, ['left', 'center', 'right', 'full'], true)) {
            $align = 'left';
        }

        return View::loadRelative(__DIR__, 'views/submit-button', [
            'label' => (string) ($attrs['label'] ?? __('Donate', 'dono')),
            'align' => $align,
        ]);
    }
}
