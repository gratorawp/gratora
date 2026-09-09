<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Foundation\Plugin;
use Gratora\Foundation\References\ReferenceGenerator;

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
        update_option("gratora_reference_counter_donation_{$year}", (string) $count, false);
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

        $this->assertSame(1, $this->gen()->peekNext('test_receipt'), 'seeding must not invent a history that is not there');
    }

    /**
     * Scopes are free-form strings and one can be a prefix of another:
     * gratora-events mints both 'ticket' and 'ticket_order'. Treating any
     * longer name as a year suffix drags the shorter sequence up to whatever
     * the longer one has reached.
     */
    public function test_a_sibling_scope_does_not_floor_this_one(): void
    {
        $this->settings(['reset_yearly' => false]);
        update_option('gratora_reference_counter_ticket_order', '500', false);
        update_option('gratora_reference_counter_ticket', '20', false);

        $this->assertSame(21, $this->gen()->peekNext('ticket'), 'a longer scope name is not a year suffix');
        $this->assertStringEndsWith('00021', $this->gen()->next('ticket'));
    }

    public function test_a_year_suffix_still_floors_the_continuous_counter(): void
    {
        $this->settings(['reset_yearly' => false]);
        update_option('gratora_reference_counter_ticket_2025', '900', false);
        update_option('gratora_reference_counter_ticket', '20', false);

        $this->assertSame(901, $this->gen()->peekNext('ticket'));
    }
}
