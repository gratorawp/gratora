<?php

declare(strict_types=1);

namespace Dono\Foundation\Hooks;

/**
 * Base class for declarative WP hook attachment.
 * Subclasses override actions() and/or filters() and call register().
 *
 * @since 1.0.0
 */
abstract class HookProvider
{
    /**
     * Map of hook name to method name or [method, priority, args].
     *
     * @return array<string, string|array{0:string,1?:int,2?:int}>
     * @since 1.0.0
     */
    protected function actions(): array
    {
        return [];
    }

    /**
     * @return array<string, string|array{0:string,1?:int,2?:int}>
     * @since 1.0.0
     */
    protected function filters(): array
    {
        return [];
    }

    /** @since 1.0.0 */
    public function register(): void
    {
        foreach ($this->actions() as $hook => $spec) {
            [$method, $priority, $args] = $this->normalize($spec);
            add_action($hook, [$this, $method], $priority, $args);
        }

        foreach ($this->filters() as $hook => $spec) {
            [$method, $priority, $args] = $this->normalize($spec);
            add_filter($hook, [$this, $method], $priority, $args);
        }
    }

    /**
     * Normalize a hook spec to [method, priority, accepted_args].
     *
     * @since 1.0.0
     */
    private function normalize(string|array $spec): array
    {
        if (is_string($spec)) {
            return [$spec, 10, 1];
        }
        return [$spec[0], $spec[1] ?? 10, $spec[2] ?? 1];
    }
}
