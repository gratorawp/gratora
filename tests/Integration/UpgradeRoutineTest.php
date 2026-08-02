<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donors\Consent;
use Dono\Donors\Donor;
use Dono\Donors\Erasure\ClearHashesOnAlreadyErasedConsents;
use Dono\Foundation\Upgrade\UpgradeRoutine;
use Dono\Foundation\Upgrade\UpgradeRunner;

/**
 * The mechanism, and the first routine that uses it.
 *
 * dbDelta reconciles shape and nothing else, so a release that needs to touch
 * the contents of a table had nowhere to put that work. These cover the two
 * things such a mechanism has to get right: it must not lose a routine, and it
 * must not run one twice in a way that matters.
 */
final class UpgradeRoutineTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        delete_option(UpgradeRunner::OPTION_DONE);
        delete_option('dono_upgrade_clear_consent_hashes_after');
    }

    private function erasedDonorWithConsent(): int
    {
        $now = gmdate('Y-m-d H:i:s');

        $donor = Donor::make();
        $donor->email_hash  = hash('sha256', uniqid('', true));
        $donor->redacted_at = $now;
        $donor->created_at  = $now;
        $donor->updated_at  = $now;
        $donor->save();

        $consent = Consent::make();
        $consent->donor_id        = (int) $donor->id;
        $consent->purpose         = 'marketing';
        $consent->granted         = true;
        $consent->source          = 'donation_form';
        $consent->ip_hash         = str_repeat('a', 64);
        $consent->user_agent_hash = str_repeat('b', 64);
        $consent->occurred_at     = $now;
        $consent->save();

        return (int) $donor->id;
    }

    private function drain(UpgradeRunner $runner, int $maxSteps = 50): int
    {
        $steps = 0;
        while ($runner->step() && $steps < $maxSteps) {
            $steps++;
        }

        return $steps;
    }

    public function test_a_routine_runs_once_and_is_not_repeated(): void
    {
        $runs = 0;
        $routine = new class ($runs) implements UpgradeRoutine {
            public function __construct(private int &$runs)
            {
            }
            public function id(): string
            {
                return 'test_runs_once';
            }
            public function description(): string
            {
                return 'test';
            }
            public function step(): bool
            {
                $this->runs++;
                return true;
            }
        };

        $runner = new UpgradeRunner([$routine]);

        $this->assertTrue($runner->hasPending());
        $runner->step();
        $this->assertSame(1, $runs);

        $this->assertFalse($runner->hasPending(), 'it is recorded as done');
        $runner->step();
        $this->assertSame(1, $runs, 'and a later boot does not run it again');
    }

    public function test_a_routine_that_throws_is_not_marked_done(): void
    {
        $routine = new class implements UpgradeRoutine {
            public function id(): string
            {
                return 'test_throws';
            }
            public function description(): string
            {
                return 'test';
            }
            public function step(): bool
            {
                throw new \RuntimeException('nope');
            }
        };

        $runner = new UpgradeRunner([$routine]);
        $runner->step();

        $this->assertTrue(
            $runner->hasPending(),
            'stamping a routine that failed would strand whatever it had not reached'
        );
    }

    public function test_routines_run_in_order_one_at_a_time(): void
    {
        $order = [];
        $make = function (string $id) use (&$order): UpgradeRoutine {
            return new class ($id, $order) implements UpgradeRoutine {
                public function __construct(private string $key, private array &$order)
                {
                }
                public function id(): string
                {
                    return $this->key;
                }
                public function description(): string
                {
                    return 'test';
                }
                public function step(): bool
                {
                    $this->order[] = $this->key;
                    return true;
                }
            };
        };

        $runner = new UpgradeRunner([$make('first'), $make('second')]);
        $this->drain($runner);

        $this->assertSame(['first', 'second'], $order, 'a later routine can depend on an earlier one');
    }

    /**
     * Crosses the 200-donor batch on purpose.
     *
     * The first version of this routine took "the first 200 erased donors"
     * every step. Clearing a donor's hashes does not un-erase them, so the
     * second step re-read the same 200, found nothing to do, and declared
     * itself finished with donor 201 onwards untouched. A test inside one batch
     * passes against that bug.
     */
    public function test_the_consent_routine_reaches_past_the_first_batch(): void
    {
        global $wpdb;

        $now    = gmdate('Y-m-d H:i:s');
        $donors = $wpdb->prefix . 'dono_donors';
        $rows   = [];
        for ($i = 0; $i < 205; $i++) {
            $rows[] = $wpdb->prepare('(%s, %s, %s, %s)', hash('sha256', uniqid('', true)), $now, $now, $now);
        }
        $wpdb->query(
            "INSERT INTO `{$donors}` (email_hash, redacted_at, created_at, updated_at) VALUES " . implode(',', $rows)
        );

        $ids = array_map('intval', (array) $wpdb->get_col(
            "SELECT id FROM `{$donors}` WHERE redacted_at IS NOT NULL ORDER BY id ASC"
        ));
        $this->assertGreaterThan(200, count($ids), 'precondition: more erased donors than one batch');

        $consents = $wpdb->prefix . 'dono_consents';
        $crows    = [];
        foreach ($ids as $id) {
            $crows[] = $wpdb->prepare(
                '(%d, %s, 1, %s, %s, %s, %s)',
                $id,
                'marketing',
                'donation_form',
                str_repeat('a', 64),
                str_repeat('b', 64),
                $now
            );
        }
        $wpdb->query(
            "INSERT INTO `{$consents}` (donor_id, purpose, granted, source, ip_hash, user_agent_hash, occurred_at) VALUES "
            . implode(',', $crows)
        );

        $this->drain(new UpgradeRunner([new ClearHashesOnAlreadyErasedConsents()]));

        $left = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `{$consents}` WHERE ip_hash IS NOT NULL OR user_agent_hash IS NOT NULL"
        );
        $this->assertSame(0, $left, 'every erased donor is reached, not just the first batch');

        $kept = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$consents}` WHERE purpose = 'marketing'");
        $this->assertSame(count($ids), $kept, 'and every consent fact survives');
    }

    public function test_the_consent_routine_leaves_a_live_donors_consent_alone(): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $live = Donor::make();
        $live->email_hash = hash('sha256', uniqid('', true));
        $live->created_at = $now;
        $live->updated_at = $now;
        $live->save();

        $consent = Consent::make();
        $consent->donor_id        = (int) $live->id;
        $consent->purpose         = 'marketing';
        $consent->granted         = true;
        $consent->source          = 'donation_form';
        $consent->ip_hash         = str_repeat('c', 64);
        $consent->user_agent_hash = str_repeat('d', 64);
        $consent->occurred_at     = $now;
        $consent->save();

        $this->drain(new UpgradeRunner([new ClearHashesOnAlreadyErasedConsents()]));

        $fresh = Consent::query()->where('donor_id', (int) $live->id)->get();
        $this->assertSame(str_repeat('c', 64), $fresh->ip_hash, 'nobody who was not erased is touched');
    }

    public function test_a_fresh_install_stamps_the_routines_instead_of_running_them(): void
    {
        $ran = false;
        $routine = new class ($ran) implements UpgradeRoutine {
            public function __construct(private bool &$ran)
            {
            }
            public function id(): string
            {
                return 'test_fresh_install';
            }
            public function description(): string
            {
                return 'test';
            }
            public function step(): bool
            {
                $this->ran = true;
                return true;
            }
        };

        $runner = new UpgradeRunner([$routine]);
        UpgradeRunner::markAllDone($runner);

        $this->assertFalse($runner->hasPending());
        $this->assertFalse($ran, 'a new site has nothing to migrate');
    }
}
