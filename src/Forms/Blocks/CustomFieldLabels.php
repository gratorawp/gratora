<?php

declare(strict_types=1);

namespace Dono\Forms\Blocks;

/**
 * Resolve custom-field slug => human label for a form's blocks.
 *
 * Slugs must match the derived field keys the donor runtime stores
 * (DropdownBlock::deriveField).
 *
 * @version 1.0.0
 */
final class CustomFieldLabels
{
    /** @return array<string,string> */
    public static function forBlocks(string $blocks): array
    {
        if (trim($blocks) === '') {
            return [];
        }
        $out = [];
        self::walk(parse_blocks($blocks), $out);
        return $out;
    }

    /**
     * @param array<int,array<string,mixed>> $blocks
     * @param array<string,string>           $out
     */
    private static function walk(array $blocks, array &$out): void
    {
        foreach ($blocks as $block) {
            $name  = (string) ($block['blockName'] ?? '');
            $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];

            $slug = '';
            switch ($name) {
                case 'dono/text-input':
                case 'dono/number-input':
                case 'dono/date':
                case 'dono/dropdown':
                case 'dono/radio':
                case 'dono/checkbox':
                case 'dono/multi-select':
                    $slug = DropdownBlock::deriveField(
                        (string) ($attrs['field'] ?? ''),
                        (string) ($attrs['label'] ?? '')
                    );
                    break;
                case 'dono/hidden':
                    $slug = (string) ($attrs['field'] ?? '');
                    break;
            }

            if ($slug !== '') {
                $label = trim((string) ($attrs['label'] ?? ''));
                if ($label !== '') {
                    $out[$slug] = $label;
                }
            }

            if (! empty($block['innerBlocks']) && is_array($block['innerBlocks'])) {
                self::walk($block['innerBlocks'], $out);
            }
        }
    }
}
