<?php

declare(strict_types=1);

namespace Dono\Forms\Blocks;

use Dono\Foundation\Helpers\View;

/**
 * First/last name field block.
 *
 * @version 1.0.0
 */
final class NameBlock implements Block
{
    /**
     * Block type name.
     */
    public function name(): string
    {
        return 'dono/name';
    }

    /**
     * Block attribute schema.
     */
    public function attributes(): array
    {
        return [
            'firstLabel'       => ['type' => 'string',  'default' => ''],
            'lastLabel'        => ['type' => 'string',  'default' => ''],
            'firstPlaceholder' => ['type' => 'string',  'default' => ''],
            'lastPlaceholder'  => ['type' => 'string',  'default' => ''],
            'requireFirst'     => ['type' => 'boolean', 'default' => true],
            'requireLast'      => ['type' => 'boolean', 'default' => true],
        ];
    }

    /**
     * Renders the name fields.
     */
    public function render(array $attrs, string $content): string
    {
        return View::loadRelative(__DIR__, 'views/name', [
            'firstLabel'       => (string) ($attrs['firstLabel'] ?? ''),
            'lastLabel'        => (string) ($attrs['lastLabel'] ?? ''),
            'firstPlaceholder' => (string) ($attrs['firstPlaceholder'] ?? ''),
            'lastPlaceholder'  => (string) ($attrs['lastPlaceholder'] ?? ''),
            'requireFirst'     => (bool) ($attrs['requireFirst'] ?? true),
            'requireLast'      => (bool) ($attrs['requireLast']  ?? true),
        ]);
    }
}
