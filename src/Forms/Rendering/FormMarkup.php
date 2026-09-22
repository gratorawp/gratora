<?php

declare(strict_types=1);

namespace Gratora\Forms\Rendering;

/**
 * The HTML a donation form's blocks may carry.
 *
 * @since 1.1.0
 */
final class FormMarkup
{
    /**
     * Core merges _wp_add_global_attributes() into the post tags once, when
     * kses loads, so a tag added here has to list the same set itself.
     */
    public const GLOBAL_ATTRIBUTES = [
        'aria-controls'    => true,
        'aria-current'     => true,
        'aria-describedby' => true,
        'aria-details'     => true,
        'aria-expanded'    => true,
        'aria-hidden'      => true,
        'aria-label'       => true,
        'aria-labelledby'  => true,
        'aria-live'        => true,
        'class'            => true,
        'data-*'           => true,
        'dir'              => true,
        'hidden'           => true,
        'id'               => true,
        'lang'             => true,
        'style'            => true,
        'tabindex'         => true,
        'title'            => true,
        'role'             => true,
        'xml:lang'         => true,
    ];

    private const FORM_ATTRIBUTES = [
        'input'    => [
            'autocomplete' => true,
            'checked'      => true,
            'disabled'     => true,
            'inputmode'    => true,
            'max'          => true,
            'maxlength'    => true,
            'min'          => true,
            'name'         => true,
            'pattern'      => true,
            'placeholder'  => true,
            'readonly'     => true,
            'required'     => true,
            'step'         => true,
            'type'         => true,
            'value'        => true,
        ],
        'select'   => [
            'disabled' => true,
            'name'     => true,
            'required' => true,
        ],
        'option'   => [
            'disabled' => true,
            'selected' => true,
            'value'    => true,
        ],
        'textarea' => [
            'maxlength'   => true,
            'placeholder' => true,
            'required'    => true,
        ],
        'button'   => [
            'aria-checked' => true,
        ],
        'div'      => [
            'aria-valuemax' => true,
            'aria-valuemin' => true,
            'aria-valuenow' => true,
        ],
    ];

    /**
     * Post content plus the form controls the field blocks render.
     *
     * @return array<string, array<string, mixed>>
     *
     * @since 1.1.0
     */
    public static function allowedHtml(): array
    {
        $tags = wp_kses_allowed_html('post');

        foreach (self::FORM_ATTRIBUTES as $tag => $attributes) {
            $existing   = is_array($tags[$tag] ?? null) ? $tags[$tag] : [];
            $tags[$tag] = array_merge(self::GLOBAL_ATTRIBUTES, $existing, $attributes);
        }

        return $tags;
    }

    /**
     * Holds stored form markup to core's post-content rule for an author
     * without unfiltered_html. Only the literal HTML chunks are filtered, so the
     * JSON in the block delimiters survives.
     *
     * @since 1.1.0
     */
    public static function sanitizeBlocks(string $markup): string
    {
        if ($markup === '' || current_user_can('unfiltered_html')) {
            return $markup;
        }

        return serialize_blocks(self::ksesBlockList(parse_blocks($markup)));
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @return array<int, array<string, mixed>>
     *
     * @since 1.1.0
     */
    private static function ksesBlockList(array $blocks): array
    {
        foreach ($blocks as &$block) {
            if (is_array($block['innerContent'] ?? null)) {
                $block['innerContent'] = array_map(
                    static fn ($chunk) => is_string($chunk) ? wp_kses_post($chunk) : $chunk,
                    $block['innerContent']
                );
            }
            if (! empty($block['innerBlocks']) && is_array($block['innerBlocks'])) {
                $block['innerBlocks'] = self::ksesBlockList($block['innerBlocks']);
            }
        }
        unset($block);

        return $blocks;
    }
}
