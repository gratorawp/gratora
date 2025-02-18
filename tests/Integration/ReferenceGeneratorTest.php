<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Foundation\References\ReferenceGenerator;
use Dono\Foundation\Time\FrozenClock;

final class ReferenceGeneratorTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        delete_option(ReferenceGenerator::OPTION_SETTINGS);
        // Wipe any counter rows lingering across tests.
        self::$wpdb->query(
            "DELETE FROM " . self::$prefix . "options WHERE option_name LIKE 'dono_reference_counter_%'"
        );
    }

    public function test_next_increments_monotonically(): void
    {
        $gen = $this->generatorAt('2026-05-13');
        $this->assertSame('DONO-2026-00001', $gen->next('donation'));
        $this->assertSame('DONO-2026-00002', $gen->next('donation'));
        $this->assertSame('DONO-2026-00003', $gen->next('donation'));
    }

    public function test_each_scope_has_its_own_counter(): void
    {
        $gen = $this->generatorAt('2026-05-13');
        $this->assertSame('DONO-2026-00001', $gen->next('donation'));
        $this->assertSame('REC-2026-00001',  $gen->next('receipt'));
        $this->assertSame('REF-2026-00001',  $gen->next('refund'));
        $this->assertSame('DONO-2026-00002', $gen->next('donation'));  // donation continues from 1
        $this->assertSame('REC-2026-00002',  $gen->next('receipt'));
    }

    public function test_yearly_reset_starts_fresh_each_year(): void
    {
        $gen2026 = $this->generatorAt('2026-06-01');
        $this->assertSame('DONO-2026-00001', $gen2026->next('donation'));
        $this->assertSame('DONO-2026-00002', $gen2026->next('donation'));

        $gen2027 = $this->generatorAt('2027-01-01');
        $this->assertSame('DONO-2027-00001', $gen2027->next('donation'),
            'Counter resets at year boundary when reset_yearly = true');
    }

    public function test_continuous_numbering_when_reset_yearly_disabled(): void
    {
        update_option(ReferenceGenerator::OPTION_SETTINGS, [
            'prefixes'     => ['donation' => 'DONO'],
            'padding'      => 5,
            'include_year' => true,
            'reset_yearly' => false,
        ]);

        $gen2026 = $this->generatorAt('2026-06-01');
        $this->assertSame('DONO-2026-00001', $gen2026->next('donation'));
        $this->assertSame('DONO-2026-00002', $gen2026->next('donation'));

        $gen2027 = $this->generatorAt('2027-01-01');
        $this->assertSame('DONO-2027-00003', $gen2027->next('donation'),
            'Counter continues across years when reset_yearly = false');
    }

    public function test_next_number_override_jumps_counter_forward(): void
    {
        $gen = $this->generatorAt('2026-05-13');
        $this->assertSame('DONO-2026-00001', $gen->next('donation'));
        $this->assertSame('DONO-2026-00002', $gen->next('donation'));

        $gen->nextNumber('donation', 5847);

        $this->assertSame('DONO-2026-05847', $gen->next('donation'));
        $this->assertSame('DONO-2026-05848', $gen->next('donation'));
    }

    public function test_next_number_rejects_value_at_or_below_current(): void
    {
        $gen = $this->generatorAt('2026-05-13');
        $gen->next('donation'); // counter is now 1
        $gen->next('donation'); // counter is now 2

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot set counter for donation to 2');
        $gen->nextNumber('donation', 2);
    }

    public function test_next_number_rejects_zero_or_negative(): void
    {
        $gen = $this->generatorAt('2026-05-13');
        $this->expectException(\InvalidArgumentException::class);
        $gen->nextNumber('donation', 0);
    }

    public function test_peek_next_does_not_advance_counter(): void
    {
        $gen = $this->generatorAt('2026-05-13');
        $this->assertSame(1, $gen->peekNext('donation'));
        $this->assertSame(1, $gen->peekNext('donation'));  // idempotent
        $this->assertSame('DONO-2026-00001', $gen->next('donation'));  // first call increments
        $this->assertSame(2, $gen->peekNext('donation'));
    }

    private function generatorAt(string $iso): ReferenceGenerator
    {
        return new ReferenceGenerator(new FrozenClock(new \DateTimeImmutable($iso)));
    }
}
