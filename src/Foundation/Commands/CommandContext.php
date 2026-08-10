<?php

declare(strict_types=1);

namespace Dono\Foundation\Commands;

/**
 * Execution context passed to every command dispatch.
 *
 * @since 1.0.0
 */
final class CommandContext
{
    /** @since 1.0.0 */
    public function __construct(
        public readonly ?int $user_id,
        public readonly string $source,
        public readonly string $request_id,
        public readonly bool $dry_run = false,
        public readonly ?string $confirmation = null,
    ) {
    }
}
