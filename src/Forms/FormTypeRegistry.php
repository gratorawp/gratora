<?php

declare(strict_types=1);

namespace Dono\Forms;

use Dono\Donations\DonationIntent;
use RuntimeException;

/**
 * Registry of form type handlers keyed by type identifier.
 *
 * @since 1.0.0
 */
final class FormTypeRegistry
{
    /** @var array<string,FormTypeHandler> */
    private array $handlers = [];

    /**
     * Register a handler; throws if its type is already registered.
     *
     * @since 1.0.0
     */
    public function register(FormTypeHandler $handler): void
    {
        $type = $handler->type();
        if (isset($this->handlers[$type])) {
            throw new RuntimeException("Form type '{$type}' is already registered.");
        }
        $this->handlers[$type] = $handler;
    }

    /** @since 1.0.0 */
    public function has(string $type): bool
    {
        return isset($this->handlers[$type]);
    }

    /** @since 1.0.0 */
    public function get(string $type): ?FormTypeHandler
    {
        return $this->handlers[$type] ?? null;
    }

    /**
     * Resolve the handler for an intent, falling back to the default.
     *
     * @since 1.0.0
     */
    public function handlerFor(DonationIntent $intent): FormTypeHandler
    {
        $type = (string) ($intent->extra['form_type'] ?? 'donation');
        return $this->handlers[$type]
            ?? $this->handlers['donation']
            ?? new DefaultFormTypeHandler();
    }

    /**
     * @return array<string,FormTypeHandler>
     *
     * @since 1.0.0
     */
    public function all(): array
    {
        return $this->handlers;
    }
}
