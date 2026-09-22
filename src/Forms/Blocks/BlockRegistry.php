<?php

declare(strict_types=1);

namespace Gratora\Forms\Blocks;

use Gratora\Forms\Rendering\FormMarkup;

/** @since 1.0.0 */
final class BlockRegistry
{
    /** @var array<string, Block> */
    private array $blocks = [];

    /** @since 1.0.0 */
    public function add(Block $block): void
    {
        $this->blocks[$block->name()] = $block;
    }

    /** @since 1.0.0 */
    public function has(string $name): bool
    {
        return isset($this->blocks[$name]);
    }

    /**
     * @return array<string, Block>
     *
     * @since 1.0.0
     */
    public function all(): array
    {
        return $this->blocks;
    }

    /** @since 1.0.0 */
    public function register(): void
    {
        foreach ($this->blocks as $name => $block) {
            $attrs = $block->attributes();
            // Every form block supports conditional visibility via the
            // gratora/condition inspector panel. Declare the attribute centrally
            // so individual blocks don't each need to repeat it.
            if (! isset($attrs['condition'])) {
                $attrs['condition'] = ['type' => 'object', 'default' => null];
            }
            register_block_type($name, [
                'attributes'      => $attrs,
                'render_callback' => fn (array $a, string $content): string => wp_kses($block->render($a, $content), FormMarkup::allowedHtml()),
            ]);
        }
    }
}
