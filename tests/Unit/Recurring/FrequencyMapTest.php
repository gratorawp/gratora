<?php

declare(strict_types=1);

namespace Dono\Tests\Unit\Recurring;

use Dono\Recurring\FrequencyMap;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class FrequencyMapTest extends TestCase
{
    /** @dataProvider stripeProvider */
    public function test_to_stripe(string $frequency, string $expectedInterval, int $expectedCount): void
    {
        [$interval, $count] = FrequencyMap::toStripe($frequency);
        $this->assertSame($expectedInterval, $interval);
        $this->assertSame($expectedCount, $count);
    }

    /** @return array<string,array{0:string,1:string,2:int}> */
    public static function stripeProvider(): array
    {
        return [
            'weekly'    => ['weekly', 'week', 1],
            'biweekly'  => ['biweekly', 'week', 2],
            'monthly'   => ['monthly', 'month', 1],
            'quarterly' => ['quarterly', 'month', 3],
            'yearly'    => ['yearly', 'year', 1],
        ];
    }

    public function test_to_stripe_throws_on_one_time(): void
    {
        $this->expectException(RuntimeException::class);
        FrequencyMap::toStripe('one_time');
    }

    public function test_is_recurring(): void
    {
        $this->assertTrue(FrequencyMap::isRecurring('monthly'));
        $this->assertTrue(FrequencyMap::isRecurring('weekly'));
        $this->assertFalse(FrequencyMap::isRecurring('one_time'));
        $this->assertFalse(FrequencyMap::isRecurring(''));
    }

    public function test_next_renewal_day(): void
    {
        $now = strtotime('2026-06-01 12:00:00');
        $next = FrequencyMap::nextRenewalAfter($now, 'day', 3);
        $this->assertSame('2026-06-04', date('Y-m-d', $next));
    }

    public function test_next_renewal_week(): void
    {
        $now = strtotime('2026-06-01 12:00:00');
        $next = FrequencyMap::nextRenewalAfter($now, 'week', 2);
        $this->assertSame('2026-06-15', date('Y-m-d', $next));
    }

    public function test_next_renewal_month_from_end_of_month(): void
    {
        $jan31 = strtotime('2026-01-31 12:00:00');
        $next = FrequencyMap::nextRenewalAfter($jan31, 'month', 1);
        // PHP +1 month from Jan 31 overflows Feb to Mar 3. This matches
        // Stripe's billing_cycle_anchor behavior (calendar math, not clamped).
        $this->assertSame('2026-03-03', date('Y-m-d', $next));
    }

    public function test_next_renewal_month_mid_month_is_exact(): void
    {
        $jan15 = strtotime('2026-01-15 12:00:00');
        $next = FrequencyMap::nextRenewalAfter($jan15, 'month', 1);
        $this->assertSame('2026-02-15', date('Y-m-d', $next));
    }

    public function test_next_renewal_month_quarterly(): void
    {
        $now = strtotime('2026-03-15 10:00:00');
        $next = FrequencyMap::nextRenewalAfter($now, 'month', 3);
        $this->assertSame('2026-06-15', date('Y-m-d', $next));
    }

    public function test_next_renewal_year(): void
    {
        $now = strtotime('2026-06-01 12:00:00');
        $next = FrequencyMap::nextRenewalAfter($now, 'year', 1);
        $this->assertSame('2027-06-01', date('Y-m-d', $next));
    }

    public function test_next_renewal_unknown_interval_throws(): void
    {
        $this->expectException(RuntimeException::class);
        FrequencyMap::nextRenewalAfter(time(), 'fortnight', 1);
    }
}
