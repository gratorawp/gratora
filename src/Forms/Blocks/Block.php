<?php

declare(strict_types=1);

namespace Dono\Forms\Blocks;

/**
 * Server-side render contract for a dono block.
 *
 * @version 1.0.0
 */
interface Block
{
    /** Block name. */
    public function name(): string;

    /** @return array<string, array{type:string, default?:mixed}> */
    public function attributes(): array;

    /** Render server-side markup. */
    public function render(array $attrs, string $content): string;
}
