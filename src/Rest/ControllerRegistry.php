<?php

declare(strict_types=1);

namespace Dono\Rest;

/**
 * Registry for add-on REST controllers. Any object with registerRoutes(): void
 * can be contributed via dono.rest.register without modifying RestProvider.
 *
 * @version 1.0.0
 */
final class ControllerRegistry
{
    /** @var list<object> */
    private array $controllers = [];

    public function add(object $controller): void
    {
        $this->controllers[] = $controller;
    }

    /** @return list<object> */
    public function all(): array
    {
        return $this->controllers;
    }
}
