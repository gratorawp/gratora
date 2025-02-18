<?php

declare(strict_types=1);

namespace Dono\Forms\Blocks;

use Dono\Foundation\Helpers\View;

/**
 * Tribute dedication field block with admin-defined types.
 *
 * @version 1.1.0
 */
final class TributeBlock implements Block
{
    /**
     * Block type name.
     */
    public function name(): string
    {
        return 'dono/tribute';
    }

    /**
     * Block attribute schema.
     */
    public function attributes(): array
    {
        return [
            'types'       => ['type' => 'array',   'default' => []],
            'allowNotify' => ['type' => 'boolean', 'default' => true],
            'allowAnnual' => ['type' => 'boolean', 'default' => true],
            'label'       => ['type' => 'string',  'default' => ''],
        ];
    }

    /**
     * Renders the tribute dedication fields.
     */
    public function render(array $attrs, string $content): string
    {
        $types = [];
        foreach ((array) ($attrs['types'] ?? []) as $t) {
            $id    = isset($t['id'])    ? (string) $t['id']    : '';
            $label = isset($t['label']) ? (string) $t['label'] : '';
            if ($id === '' || $label === '') continue;
            $types[] = ['id' => $id, 'label' => $label];
        }
        if (empty($types)) {
            $types = [
                ['id' => 'honor',    'label' => __('In honor of', 'dono')],
                ['id' => 'memorial', 'label' => __('In memory of', 'dono')],
            ];
        }

        return View::loadRelative(__DIR__, 'views/tribute', [
            'types'       => $types,
            'allowNotify' => (bool) ($attrs['allowNotify'] ?? true),
            'allowAnnual' => (bool) ($attrs['allowAnnual'] ?? true),
            'label'       => (string) ($attrs['label']    ?? __('Make this donation in honor or memory of someone', 'dono')),
        ]);
    }
}
