<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Foundation\Plugin;
use Dono\Foundation\References\ReferenceGenerator;

/**
 * Changing the numbering settings moves which option holds the counter, and the
 * new one does not exist yet. Every read has to seed from the counters already
 * in use, or the screen offers a number that was printed on someone's receipt
 * last month.
 */
final class ReferenceCounterSeedingTest extends IntegrationTestCase
{
    private function gen(): ReferenceGenerator
    {
        return Plugin::instance()->container->get(ReferenceGenerator::class);
    }

    private function settings(array $patch): void
    {
        $opt = get_option(ReferenceGenerator::OPTION_SETTINGS, []);
        $opt = is_array($opt) ? $opt : [];
        update_option(ReferenceGenerator::OPTION_SETTINGS, array_merge(
            ReferenceGenerator::DEFAULT_SETTINGS,
            $opt,
            $patch
        ));
    }

    /** Put the site where it would be after 500 donations this year. */
    private function alreadyIssued(int $count): void
    {
        $year = (int) gmdate('Y');
        update_option("dono_reference_counter_donation_{$year}", (string) $count, false);
    }

    public function test_turning_yearly_reset_off_does_not_restart_the_numbering(): void
    {
        $this->settings(['reset_yearly' => true]);
        $this->alreadyIssued(500);

        // The admin unchecks "Reset numbering each year". The counter now lives
        // under a key that has never existed.
        $this->settings(['reset_yearly' => false]);

        $this->assertSame(501, $this->gen()->peekNext('donation'), 'the screen must not offer a number already used');
    }

    public function test_the_setter_refuses_a_number_that_was_already_issued(): void
    {
        $this->settings(['reset_yearly' => true]);
        $this->alreadyIssued(500);
        $this->settings(['reset_yearly' => false]);

        $this->expectException(\RuntimeException::class);
        $this->gen()->nextNumber('donation', 2);
    }

    public function test_minting_after_the_change_continues_the_sequence(): void
    {
        $this->settings(['reset_yearly' => true]);
        $this->alreadyIssued(500);
        $this->settings(['reset_yearly' => false]);

        $ref = $this->gen()->next('donation');

        $this->assertStringContainsString('00501', $ref, 'the first reference after the change follows the last one before it');
    }

    public function test_a_fresh_site_still_starts_at_one(): void
    {
        $this->settings(['reset_yearly' => true]);

        $this->assertSame(1, $this->gen()->peekNext('refund'), 'seeding must not invent a history that is not there');
    }
}
