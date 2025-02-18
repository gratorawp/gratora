<?php

declare(strict_types=1);

namespace Dono\Forms;

use Dono\Donations\DonationIntent;
use RuntimeException;

/**
 * Registry of form type handlers keyed by type identifier.
 *
 * @version 1.0.0
 */
final class FormTypeRegistry
{
    /** @var array<string,FormTypeHandler> */
    private array $handlers = [];

    /** Register a handler; throws if its type is already registered. */
    public function register(FormTypeHandler $handler): void
    {
        $type = $handler->type();
        if (isset($this->handlers[$type])) {
            throw new RuntimeException("Form type '{$type}' is already registered.");
        }
        $this->handlers[$type] = $handler;
    }

    /** Whether a handler is registered for the type. */
    public function has(string $type): bool
    {
        return isset($this->handlers[$type]);
    }

    /** Handler for the type, or null. */
    public function get(string $type): ?FormTypeHandler
    {
        return $this->handlers[$type] ?? null;
    }

    /** Resolve the handler for an intent, falling back to the default. */
    public function handlerFor(DonationIntent $intent): FormTypeHandler
    {
        $type = (string) ($intent->extra['form_type'] ?? 'donation');
        return $this->handlers[$type]
            ?? $this->handlers['donation']
            ?? new DefaultFormTypeHandler();
    }

    /**
     * All registered handlers.
     *
     * @return array<string,FormTypeHandler>
     */
    public function all(): array
    {
        return $this->handlers;
    }
}
