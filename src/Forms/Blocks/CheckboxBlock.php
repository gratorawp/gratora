<?php

declare(strict_types=1);

namespace Dono\Forms\Blocks;

use Dono\Foundation\Helpers\View;

/**
 * Single checkbox field block.
 *
 * @since 1.0.0
 */
final class CheckboxBlock implements Block
{
    /** @since 1.0.0 */
    public function name(): string
    {
        return 'dono/checkbox';
    }

    /** @since 1.0.0 */
    public function attributes(): array
    {
        return [
            'label'     => ['type' => 'string',  'default' => ''],
            'helpText'  => ['type' => 'string',  'default' => ''],
            'required'  => ['type' => 'boolean', 'default' => false],
            'defaultOn' => ['type' => 'boolean', 'default' => false],
            'field'     => ['type' => 'string',  'default' => ''],
        ];
    }

    /** @since 1.0.0 */
    public function render(array $attrs, string $content): string
    {
        $field = DropdownBlock::deriveField((string) ($attrs['field'] ?? ''), (string) ($attrs['label'] ?? ''));

        return View::loadRelative(__DIR__, 'views/checkbox', [
            'label'     => (string) ($attrs['label']     ?? ''),
            'helpText'  => (string) ($attrs['helpText']  ?? ''),
            'required'  => (bool)   ($attrs['required']  ?? false),
            'defaultOn' => (bool)   ($attrs['defaultOn'] ?? false),
            'field'     => $field,
        ]);
    }
}
