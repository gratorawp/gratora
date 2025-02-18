<?php

declare(strict_types=1);

namespace Dono\Tests\Unit\Foundation;

use DateTimeImmutable;
use Dono\Foundation\Time\FrozenClock;
use Dono\Foundation\Time\SystemClock;
use PHPUnit\Framework\TestCase;

final class ClockTest extends TestCase
{
    public function test_system_clock_returns_now(): void
    {
        $clock = new SystemClock();
        $before = time();
        $now = $clock->now();
        $after = time();

        $this->assertGreaterThanOrEqual($before, $now->getTimestamp());
        $this->assertLessThanOrEqual($after, $now->getTimestamp());
    }

    public function test_frozen_clock_returns_the_frozen_value(): void
    {
        $when = new DateTimeImmutable('2026-05-13 09:00:00');
        $clock = new FrozenClock($when);

        $this->assertEquals($when, $clock->now());
        $this->assertEquals($when, $clock->now(), 'subsequent calls return the same frozen value');
    }
}
