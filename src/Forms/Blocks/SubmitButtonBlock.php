<?php

declare(strict_types=1);

namespace Dono\Forms\Blocks;

use Dono\Foundation\Helpers\View;

final class SubmitButtonBlock implements Block
{
    public function name(): string
    {
        return 'dono/submit-button';
    }

    public function attributes(): array
    {
        return [
            'label'       => ['type' => 'string',  'default' => ''],
            'align'       => ['type' => 'string',  'default' => 'left'],
        ];
    }

    public function render(array $attrs, string $content): string
    {
        $align = (string) ($attrs['align'] ?? 'left');
        if (! in_array($align, ['left', 'center', 'right', 'full'], true)) {
            $align = 'left';
        }

        return View::loadRelative(__DIR__, 'views/submit-button', [
            'label' => (string) ($attrs['label'] ?? '') ?: __('Donate now', 'dono'),
            'align' => $align,
        ]);
    }
}
