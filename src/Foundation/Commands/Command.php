<?php

declare(strict_types=1);

namespace Dono\Foundation\Commands;

use Closure;
use InvalidArgumentException;

/**
 * Immutable descriptor for a registered command.
 *
 * @version 1.0.0
 */
final class Command
{
    /**
     * @param array<string,mixed>                       $inputSchema  JSON-Schema (rest_validate_value_from_schema shape)
     * @param array<string,mixed>                       $outputSchema
     * @param Closure(array, CommandContext): array     $handler
     * @param array<string,mixed>                       $meta
     */
    public function __construct(
        public readonly string $id,
        public readonly string $summary,
        public readonly array $inputSchema,
        public readonly array $outputSchema,
        public readonly string $capability,
        public readonly bool $idempotent,
        public readonly bool $mutating,
        public readonly Closure $handler,
        public readonly array $meta = [],
    ) {
        if ($id === '') {
            throw new InvalidArgumentException('Command id must not be empty.');
        }
    }
}
