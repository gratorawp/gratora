<?php

declare(strict_types=1);

namespace Dono\Foundation\Modules;

use Dono\Foundation\Container\Container;

/**
 * Implemented by every gateway, integration, add-on, and core.
 * All modules plug into the same boot pipeline, schema migrations, and admin/REST.
 *
 * @version 1.0.0
 */
interface DonoModule
{
    /** Distribution tier returned by tier(). */
    public const TIER_CORE = 'core';
    public const TIER_FREE = 'free';
    public const TIER_PRO  = 'pro';

    /** Globally-unique identifier, e.g. 'core', 'dono-p2p'. */
    public function id(): string;

    /** Human-readable name. */
    public function name(): string;

    /** Semver. */
    public function version(): string;

    /** Dependency constraints, e.g. ['core' => '^0.1', 'modules' => ['analytics']]. */
    public function requires(): array;

    /** Return false when the module requires a license that is not active. */
    public function isLicensed(): bool;

    /** Distribution tier, one of the TIER_* constants. */
    public function tier(): string;

    /** Bind services, register routes/blocks/hooks/admin pages. */
    public function boot(Container $container): void;

    /** @return array<class-string<\Dono\Vendor\Queryable\Model>> */
    public function migrations(): array;
}
