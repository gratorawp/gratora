<?php

declare(strict_types=1);

namespace Dono\Foundation\Commands;

/**
 * Value object returned from every CommandRegistry::dispatch() call.
 *
 * @since 1.0.0
 */
final class CommandResult
{
    /**
     * @param array<string,mixed> $data
     * @since 1.0.0
     */
    private function __construct(
        public readonly bool $ok,
        public readonly array $data,
        public readonly ?string $error_code,
        public readonly ?string $error,
    ) {
    }

    /**
     * @param array<string,mixed> $data
     * @since 1.0.0
     */
    public static function ok(array $data = []): self
    {
        return new self(true, $data, null, null);
    }

    /** @since 1.0.0 */
    public static function error(string $code, string $message): self
    {
        return new self(false, [], $code, $message);
    }

    /**
     * @param array<string,mixed> $preview
     * @since 1.0.0
     */
    public static function confirmationRequired(array $preview, string $confirmDigest): self
    {
        return new self(
            false,
            ['preview' => $preview, 'confirm_digest' => $confirmDigest],
            'command.confirmation_required',
            'Confirmation required for this command.',
        );
    }

    /**
     * @param array<string,mixed> $canonicalInput
     * @since 1.0.0
     */
    public static function dryRun(array $canonicalInput, string $confirmDigest): self
    {
        return new self(
            true,
            ['canonical_input' => $canonicalInput, 'confirm_digest' => $confirmDigest],
            null,
            null,
        );
    }
}
