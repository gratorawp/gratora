<?php

declare(strict_types=1);

namespace Dono\Foundation\Time;

use DateTimeImmutable;

/**
 * Single source of "now". Inject a Clock; never call new DateTimeImmutable() directly.
 * Tests use FrozenClock.
 *
 * @version 1.0.0
 */
interface Clock
{
    public function now(): DateTimeImmutable;
}
