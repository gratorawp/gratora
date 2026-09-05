<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Foundation\Plugin;
use FundKit\Foundation\References\ReferenceGenerator;

/**
 * "Reset numbering each year" moves the generator between a year-scoped
 * counter and a continuous one. Both print the same string while the year is
 * in the reference, so a counter the generator returns to has to clear
 * whatever ran ahead of it in the meantime.
 *
 * Getting this wrong does not cost one donation. UNIQUE(reference) rejects the
 * insert, and next() runs inside the donation's own transaction, so the
 * increment rolls back with it and the counter never advances: every later
 * checkout fails the same way, for good.
 */
final class ReferenceCounterToggleTest extends IntegrationTestCase
{
    private function generator(): ReferenceGenerator
    {
        return Plugin::instance()->container->get(ReferenceGenerator::class);
    }

    private function numbering(bool $resetYearly): void
    {
        update_option('fundkit_reference_settings', [
            'include_year' => true,
            'reset_yearly' => $resetYearly,
            'padding'      => 5,
            'separator'    => '-',
        ]);
    }

    /** @return list<string> */
    private function mint(int $count): array
    {
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[] = $this->generator()->next('donation');
        }

        return $out;
    }

    public function test_turning_the_yearly_reset_off_again_does_not_reissue(): void
    {
        $this->numbering(false);
        $before = $this->mint(2);

        $this->numbering(true);
        $during = $this->mint(2);

        $this->numbering(false);
        $after = $this->mint(2);

        $all = [...$before, ...$during, ...$after];

        $this->assertSame(
            $all,
            array_values(array_unique($all)),
            'a reference was issued twice: ' . implode(', ', $all)
        );
    }

    public function test_it_holds_across_repeated_flipping(): void
    {
        $seen = [];
        foreach ([false, true, false, true, false] as $reset) {
            $this->numbering($reset);
            $seen = [...$seen, ...$this->mint(3)];
        }

        $this->assertCount(15, $seen);
        $this->assertSame($seen, array_values(array_unique($seen)));
    }

    public function test_the_preview_agrees_with_what_will_actually_be_issued(): void
    {
        $this->numbering(false);
        $this->mint(3);

        $this->numbering(true);
        $this->mint(2);

        $this->numbering(false);

        $peek = $this->generator()->peekNext('donation');
        $next = $this->generator()->next('donation');

        $this->assertStringContainsString(
            str_pad((string) $peek, 5, '0', STR_PAD_LEFT),
            $next,
            'the settings screen previewed a number other than the one issued'
        );
    }

    public function test_a_counter_is_never_walked_backwards(): void
    {
        $this->numbering(true);
        $this->mint(5);

        // The year counter is at 5 and the continuous one at 0. Coming back to
        // the continuous counter must not restart the sequence.
        $this->numbering(false);

        $this->assertGreaterThan(5, $this->generator()->peekNext('donation'));
    }

    public function test_setting_the_next_number_still_refuses_to_go_backwards(): void
    {
        $this->numbering(false);
        $this->mint(4);
        $this->numbering(true);
        $this->mint(4);
        $this->numbering(false);

        $this->expectException(\RuntimeException::class);
        $this->generator()->nextNumber('donation', 3);
    }
}
