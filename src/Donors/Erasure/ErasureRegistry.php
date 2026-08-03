<?php

declare(strict_types=1);

namespace Dono\Donors\Erasure;

/**
 * Every plugin that stores donor data subscribes a handler here, and erasure
 * runs all of them.
 *
 * Register with the `dono.donor.erasure_handlers` filter at boot:
 *
 *     add_filter('dono.donor.erasure_handlers', static function (array $h): array {
 *         $h[] = new MyHandler();
 *         return $h;
 *     });
 *
 * @version 1.0.0
 */
final class ErasureRegistry
{
    /** @return list<ErasureHandler> */
    public function handlers(): array
    {
        $handlers = apply_filters('dono.donor.erasure_handlers', []);
        if (! is_array($handlers)) return [];

        $out = [];
        foreach ($handlers as $handler) {
            if ($handler instanceof ErasureHandler) $out[] = $handler;
        }
        return $out;
    }

    /**
     * Runs every handler. A handler that throws aborts the erasure: the caller
     * runs this inside a transaction, so a plugin that cannot complete its part
     * rolls back the rest rather than reporting a compliance action as done
     * when it only partly happened.
     */
    public function run(ErasureRequest $request): void
    {
        foreach ($this->handlers() as $handler) {
            $handler->erase($request);
        }
    }
}
