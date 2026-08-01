<?php

declare(strict_types=1);

namespace Dono\Forms\Blocks;

use Dono\Foundation\Helpers\View;

/**
 * Toggle for the donor to cover the transaction fee.
 *
 * @version 1.0.0
 */
final class CoverFeesBlock implements Block
{
    /** Block name. */
    public function name(): string
    {
        return 'dono/cover-fees';
    }

    /** Editor attribute schema. */
    public function attributes(): array
    {
        return [
            'percent'   => ['type' => 'number',  'default' => 2.9],
            'fixed'     => ['type' => 'number',  'default' => 30],
            'label'     => ['type' => 'string',  'default' => ''],
            'defaultOn' => ['type' => 'boolean', 'default' => false],
        ];
    }

    /** Render server-side markup. */
    public function render(array $attrs, string $content): string
    {
        return View::loadRelative(__DIR__, 'views/cover-fees', [
            'percent'   => (float) ($attrs['percent'] ?? 2.9),
            'fixed'     => (int)   ($attrs['fixed']   ?? 30),
            'label'     => (string) ($attrs['label']  ?? '') ?: __('I\'d like to help cover the transaction fee', 'dono'),
            'defaultOn' => (bool)   ($attrs['defaultOn'] ?? false),
        ]);
    }
}
