<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Foundation\Uninstall\DataEraser;

/**
 * The answer to "delete all Gratora data on deactivation" belongs to the
 * deactivation it was given for.
 *
 * As a plain flag it outlived one. Tick the box, then close the tab or let the
 * deactivation fail, and the option stays set with nothing to clear it: the next
 * deactivation from any route at all, a bulk action on the plugins screen,
 * WP-CLI, a host's tooling, finds it and erases every campaign, donation, donor
 * and receipt without showing anyone a dialog.
 */
final class WipeIntentExpiresTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        delete_option(DataEraser::OPT_IN);
        parent::tearDown();
    }

    /** What the dialog writes when someone ticks the box. */
    private function answeredAt(int $when): void
    {
        update_option(DataEraser::OPT_IN, $when, false);
    }

    public function test_no_answer_erases_nothing(): void
    {
        $this->assertFalse(DataEraser::requested());
    }

    public function test_a_fresh_answer_is_honoured(): void
    {
        $this->answeredAt(time());

        $this->assertTrue(DataEraser::requested());
    }

    public function test_an_answer_from_an_abandoned_deactivation_expires(): void
    {
        // Ticked the box, then never went through with it.
        $this->answeredAt(time() - 3600);

        $this->assertFalse(
            DataEraser::requested(),
            'a stale answer would wipe the site on some later, unrelated deactivation'
        );
    }

    public function test_finishing_the_wipe_spends_the_answer(): void
    {
        $this->answeredAt(time());

        // The two calls deactivation makes around the erase. The erase itself
        // cannot be run here: it drops the plugin's tables, and the harness
        // rewrites that to a temporary-table drop, so exercising it would take
        // the rest of the suite's schema with it.
        $this->assertTrue(DataEraser::requested());
        DataEraser::forgetRequest();

        $this->assertFalse(
            DataEraser::requested(),
            'a second deactivation must not find the same answer waiting'
        );
    }

    /**
     * A wipe that stopped partway has already deleted the answer on the sites
     * it reached, so the plugin delete that follows would find nothing to act
     * on and the owner would keep donors they were told were gone.
     */
    public function test_an_unfinished_wipe_leaves_the_answer_for_the_retry(): void
    {
        $this->answeredAt(time());

        DataEraser::forgetRequest();
        $this->assertFalse(DataEraser::requested());

        DataEraser::renewRequest();

        $this->assertTrue(DataEraser::requested(), 'the plugin delete still has something to act on');
    }

    public function test_a_stale_answer_is_not_acted_on(): void
    {
        $this->answeredAt(time() - 3600);

        $this->assertFalse(DataEraser::requested());
    }

    public function test_a_value_that_is_not_a_time_is_not_an_answer(): void
    {
        // What the old flag wrote. Reading it as "yes" would be the unsafe way
        // to be wrong.
        update_option(DataEraser::OPT_IN, true, false);

        $this->assertFalse(DataEraser::requested());
    }
}
