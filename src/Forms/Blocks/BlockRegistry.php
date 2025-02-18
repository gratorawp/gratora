<?php

declare(strict_types=1);

namespace Dono\Forms\Blocks;

/**
 * Server-side dono block registry.
 *
 * @version 1.0.0
 */
final class BlockRegistry
{
    /** @var array<string, Block> */
    private array $blocks = [];

    /** Add a block to the registry. */
    public function add(Block $block): void
    {
        $this->blocks[$block->name()] = $block;
    }

    /** Whether a block name is registered. */
    public function has(string $name): bool
    {
        return isset($this->blocks[$name]);
    }

    /** @return array<string, Block> */
    public function all(): array
    {
        return $this->blocks;
    }

    /** Register every block with WordPress. */
    public function register(): void
    {
        foreach ($this->blocks as $name => $block) {
            $attrs = $block->attributes();
            // Every form block supports conditional visibility via the
            // dono/condition inspector panel. Declare the attribute centrally
            // so individual blocks don't each need to repeat it.
            if (! isset($attrs['condition'])) {
                $attrs['condition'] = ['type' => 'object', 'default' => null];
            }
            $args = [
                'attributes'      => $attrs,
                'render_callback' => fn (array $a, string $content): string => $block->render($a, $content),
            ];
            // Optional method. Blocks that opt in expose feature flags such
            // as the WP 7.0 `visibility` responsive control. Avoids forcing
            // every block to grow a no-op `supports()` shim.
            if (method_exists($block, 'supports')) {
                $supports = $block->supports();
                if (is_array($supports) && $supports !== []) {
                    $args['supports'] = $supports;
                }
            }
            register_block_type($name, $args);
        }
    }
}
