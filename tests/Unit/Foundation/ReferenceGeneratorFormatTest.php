<?php

declare(strict_types=1);

namespace Dono\Tests\Unit\Foundation;

use DateTimeImmutable;
use Dono\Foundation\References\ReferenceGenerator;
use Dono\Foundation\Time\FrozenClock;
use PHPUnit\Framework\TestCase;

/**
 * Pure-format tests - the `format()` method is settings-driven but doesn't
 * touch the DB. We hammer on every config permutation here so we never have
 * to verify formatting via a full integration test.
 */
final class ReferenceGeneratorFormatTest extends TestCase
{
    private ReferenceGenerator $gen;

    protected function setUp(): void
    {
        ($GLOBALS['_dono_reset_options'])();
        $this->gen = new ReferenceGenerator(new FrozenClock(new DateTimeImmutable('2026-05-13')));
    }

    public function test_default_format(): void
    {
        $this->assertSame('DONO-2026-00001', $this->gen->format('donation', 2026, 1));
        $this->assertSame('DONO-2026-00042', $this->gen->format('donation', 2026, 42));
        $this->assertSame('REC-2026-00007',  $this->gen->format('receipt', 2026, 7));
        $this->assertSame('REF-2026-00099',  $this->gen->format('refund', 2026, 99));
    }

    public function test_custom_prefix_per_scope(): void
    {
        update_option(ReferenceGenerator::OPTION_SETTINGS, [
            'prefixes' => ['donation' => 'DONA', 'receipt' => 'TAX'],
        ]);
        $this->assertSame('DONA-2026-00001', $this->gen->format('donation', 2026, 1));
        $this->assertSame('TAX-2026-00001',  $this->gen->format('receipt', 2026, 1));
    }

    public function test_unknown_scope_falls_back_to_uppercased_name(): void
    {
        $this->assertSame('PLEDGE-2026-00001', $this->gen->format('pledge', 2026, 1));
    }

    public function test_padding_width(): void
    {
        update_option(ReferenceGenerator::OPTION_SETTINGS, ['padding' => 3]);
        $this->assertSame('DONO-2026-001',   $this->gen->format('donation', 2026, 1));
        $this->assertSame('DONO-2026-999',   $this->gen->format('donation', 2026, 999));
        // Counter exceeds padding width - pad doesn't truncate.
        $this->assertSame('DONO-2026-1000',  $this->gen->format('donation', 2026, 1000));
    }

    public function test_padding_clamps_minimum_to_1(): void
    {
        update_option(ReferenceGenerator::OPTION_SETTINGS, ['padding' => 0]);
        $this->assertSame('DONO-2026-1', $this->gen->format('donation', 2026, 1));
    }

    public function test_year_can_be_omitted(): void
    {
        update_option(ReferenceGenerator::OPTION_SETTINGS, ['include_year' => false]);
        $this->assertSame('DONO-00001', $this->gen->format('donation', 2026, 1));
        $this->assertSame('REC-00042',  $this->gen->format('receipt',  2026, 42));
    }

    public function test_custom_separator(): void
    {
        update_option(ReferenceGenerator::OPTION_SETTINGS, ['separator' => '_']);
        $this->assertSame('DONO_2026_00001', $this->gen->format('donation', 2026, 1));
    }

    public function test_route_unsafe_separator_and_prefix_are_coerced(): void
    {
        // '.', '/', '#' would mint references no REST route can match; coerce
        // the separator to '-' and strip the prefix down to the safe alphabet.
        update_option(ReferenceGenerator::OPTION_SETTINGS, [
            'separator' => '/',
            'prefixes'  => ['donation' => 'DO.NO'],
        ]);
        $this->assertSame('DONO-2026-00001', $this->gen->format('donation', 2026, 1));
    }

    public function test_combined_overrides(): void
    {
        update_option(ReferenceGenerator::OPTION_SETTINGS, [
            'prefixes'     => ['donation' => 'D'],
            'padding'      => 4,
            'include_year' => false,
            'separator'    => '_',
        ]);
        $this->assertSame('D_0042', $this->gen->format('donation', 2026, 42));
    }

    public function test_settings_partial_override_merges_with_defaults(): void
    {
        // Only override padding - prefixes & include_year should keep defaults.
        update_option(ReferenceGenerator::OPTION_SETTINGS, ['padding' => 6]);
        $this->assertSame('DONO-2026-000001', $this->gen->format('donation', 2026, 1));
        $this->assertSame('REC-2026-000001',  $this->gen->format('receipt',  2026, 1));
    }

    public function test_invalid_settings_falls_back_to_defaults(): void
    {
        update_option(ReferenceGenerator::OPTION_SETTINGS, 'not-an-array');
        $this->assertSame('DONO-2026-00001', $this->gen->format('donation', 2026, 1));
    }
}
