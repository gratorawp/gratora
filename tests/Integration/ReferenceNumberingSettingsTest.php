<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use DateTimeImmutable;
use Dono\Foundation\References\ReferenceGenerator;
use Dono\Foundation\Time\Clock;
use Dono\Foundation\Time\FrozenClock;
use Dono\Foundation\Plugin;

/**
 * Changing a numbering setting must never hand back a reference already in use.
 *
 * The counter was namespaced by reset_yearly while the printed reference was
 * namespaced by include_year. Toggling either moved the generator onto a
 * counter it had never used, which started at zero and walked back over
 * references already on donations. UNIQUE(reference) rejected the insert, and
 * because next() runs inside the donation's transaction the increment rolled
 * back with it, so the counter never advanced and every later donation failed
 * identically.
 */
final class ReferenceNumberingSettingsTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        delete_option(ReferenceGenerator::OPTION_SETTINGS);
        foreach (['', '_' . gmdate('Y')] as $suffix) {
            foreach (['donation', 'receipt'] as $scope) {
                delete_option("dono_reference_counter_{$scope}{$suffix}");
            }
        }
    }

    private function generator(?string $year = null): ReferenceGenerator
    {
        if ($year === null) {
            return new ReferenceGenerator(Plugin::instance()->container->get(Clock::class));
        }

        return new ReferenceGenerator(new FrozenClock(new DateTimeImmutable($year . '-06-15 12:00:00')));
    }

    private function settings(bool $includeYear, bool $resetYearly): void
    {
        update_option(ReferenceGenerator::OPTION_SETTINGS, [
            'include_year' => $includeYear,
            'reset_yearly' => $resetYearly,
        ]);
    }

    public function test_turning_off_the_yearly_reset_does_not_reissue_a_reference(): void
    {
        $this->settings(true, true);

        $issued = [];
        for ($i = 0; $i < 3; $i++) {
            $issued[] = $this->generator()->next('donation');
        }

        // The admin unticks "Reset numbering each year". Nothing else changes.
        $this->settings(true, false);

        $next = $this->generator()->next('donation');

        $this->assertNotContains($next, $issued, 'the next reference is not one already on a donation');
        $this->assertStringEndsWith('00004', $next, 'it carries on from where the old namespace stopped');
    }

    public function test_turning_the_year_off_does_not_reissue_a_reference_next_january(): void
    {
        // The mirror case is a fuse, not an immediate failure: the year leaves
        // the reference while the yearly reset stays on, and nothing goes wrong
        // until the rollover restarts a counter whose output no longer carries
        // the year that told the two sequences apart.
        $this->settings(false, true);

        $issued = [
            $this->generator('2026')->next('donation'),
            $this->generator('2026')->next('donation'),
        ];

        $after = [
            $this->generator('2027')->next('donation'),
            $this->generator('2027')->next('donation'),
        ];

        $this->assertSame(
            [],
            array_intersect($issued, $after),
            'January must not hand back last year\'s references'
        );
        $this->assertSame(['DONO-00001', 'DONO-00002'], $issued);
        $this->assertSame(['DONO-00003', 'DONO-00004'], $after);
    }

    public function test_a_yearly_reset_without_a_year_numbers_continuously(): void
    {
        // Restarting each January with no year printed would mint DONO-00001
        // twice, so this combination has to mean continuous numbering.
        $this->settings(false, true);

        $first = $this->generator()->next('donation');
        $this->generator()->next('donation');
        $third = $this->generator()->next('donation');

        $this->assertStringEndsWith('00001', $first);
        $this->assertStringEndsWith('00003', $third);
        $this->assertStringNotContainsString((string) gmdate('Y'), $third);
    }

    public function test_the_counter_still_restarts_each_year_when_the_year_is_printed(): void
    {
        $this->settings(true, true);

        $this->assertStringEndsWith('00001', $this->generator()->next('donation'));
        $this->assertStringEndsWith('00002', $this->generator()->next('donation'));

        // The counter really is year-scoped here, so January starts a fresh one
        // and the year in the reference keeps the two sequences apart.
        $this->assertNotFalse(
            get_option('dono_reference_counter_donation_' . gmdate('Y'), false),
            'the year-scoped counter is the one being used'
        );
        $this->assertFalse(
            get_option('dono_reference_counter_donation', false),
            'and the continuous counter is untouched'
        );
    }

    public function test_turning_the_yearly_reset_on_mid_year_does_not_reissue_a_reference(): void
    {
        // The direction the January reset makes dangerous: the continuous
        // counter has been printing this year's references, and switching to a
        // year-scoped counter mid-year would restart inside the same year the
        // continuous one was already numbering.
        $this->settings(true, false);

        $issued = [
            $this->generator('2026')->next('donation'),
            $this->generator('2026')->next('donation'),
        ];

        $this->settings(true, true);

        $next = $this->generator('2026')->next('donation');

        $this->assertNotContains($next, $issued);
        $this->assertSame('DONO-2026-00003', $next);
    }

    public function test_every_scope_keeps_its_own_high_water_mark(): void
    {
        $this->settings(true, true);

        $this->generator()->next('donation');
        $this->generator()->next('donation');
        $this->generator()->next('donation');

        // Receipts have their own counter and must not inherit the donation
        // scope's position when the namespace changes.
        $this->settings(true, false);

        $this->assertStringEndsWith('00001', $this->generator()->next('receipt'));
        $this->assertStringEndsWith('00004', $this->generator()->next('donation'));
    }
}
