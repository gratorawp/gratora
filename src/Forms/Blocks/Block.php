<?php

declare(strict_types=1);

namespace Dono\Forms\Blocks;

/**
 * Server-side render contract for a dono block.
 *
 * @since 1.0.0
 */
interface Block
{
    /** @since 1.0.0 */
    public function name(): string;

    /**
     * @return array<string, array{type:string, default?:mixed}>
     *
     * @since 1.0.0
     */
    public function attributes(): array;

    /** @since 1.0.0 */
    public function render(array $attrs, string $content): string;
}
