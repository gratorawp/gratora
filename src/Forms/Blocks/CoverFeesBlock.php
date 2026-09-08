<?php

declare(strict_types=1);

namespace FundKit\Forms\Blocks;

use FundKit\Foundation\Helpers\View;

/** @since 1.0.0 */
final class CoverFeesBlock implements Block
{
    /** @since 1.0.0 */
    public function name(): string
    {
        return 'fundkit/cover-fees';
    }

    /** @since 1.0.0 */
    public function attributes(): array
    {
        return [
            'percent'   => ['type' => 'number',  'default' => 2.9],
            'fixed'     => ['type' => 'number',  'default' => 30],
            'label'     => ['type' => 'string',  'default' => ''],
            'defaultOn' => ['type' => 'boolean', 'default' => false],
        ];
    }

    /** @since 1.0.0 */
    public function render(array $attrs, string $content): string
    {
        return View::loadRelative(__DIR__, 'views/cover-fees', [
            'percent'   => (float) ($attrs['percent'] ?? 2.9),
            'fixed'     => (int)   ($attrs['fixed']   ?? 30),
            'label'     => (string) ($attrs['label']  ?? '') ?: __('I\'d like to help cover the transaction fee', 'fundraising-toolkit'),
            'defaultOn' => (bool)   ($attrs['defaultOn'] ?? false),
        ]);
    }
}
