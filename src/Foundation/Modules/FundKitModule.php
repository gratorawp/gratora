<?php

declare(strict_types=1);

namespace FundKit\Foundation\Modules;

use FundKit\Foundation\Container\Container;

/** @since 1.0.0 */
interface FundKitModule
{
    /** Distribution tier returned by tier(). */
    public const TIER_CORE = 'core';
    public const TIER_FREE = 'free';
    public const TIER_PRO  = 'pro';

    /**
     * Globally unique module ID.
     *
     * @since 1.0.0
     */
    public function id(): string;

    /** @since 1.0.0 */
    public function name(): string;

    /**
     * Semver.
     *
     * @since 1.0.0
     */
    public function version(): string;

    /**
     * Dependency constraints, e.g. ['core' => '^0.1', 'modules' => ['analytics']].
     *
     * @since 1.0.0
     */
    public function requires(): array;

    /**
     * Return false when the module requires a license that is not active.
     *
     * @since 1.0.0
     */
    public function isLicensed(): bool;

    /**
     * One of TIER_*.
     *
     * @since 1.0.0
     */
    public function tier(): string;

    /** @since 1.0.0 */
    public function boot(Container $container): void;

    /**
     * @return array<class-string<\FundKit\Vendor\Queryable\Model>>
     * @since 1.0.0
     */
    public function migrations(): array;
}
