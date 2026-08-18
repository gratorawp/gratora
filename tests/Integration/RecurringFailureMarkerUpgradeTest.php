<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

/**
 * What the failed-renewal marker is worth on a site that already has plans.
 *
 * The marker is read by a conditional update, `last_failed_event_id <> ?`, and
 * every comparison with NULL is false in SQL. So the column being added is not
 * the whole question: if an update backfilled the existing rows with NULL
 * rather than the empty string, no delivery would ever match for a plan that
 * predates the update, and those plans would stop counting failed renewals
 * entirely, which is the sole gate on the donor's decline notice.
 */
final class RecurringFailureMarkerUpgradeTest extends UpgradeTestCase
{
    private const TABLE = 'dono_recurring_plans';

    private const SUB_ID = 'sub_before_the_update';

    public function test_an_existing_plan_gets_a_claimable_marker_on_update(): void
    {
        global $wpdb;

        $this->installCurrentSchema();
        $table = $this->scratch(self::TABLE);

        // An older release did not carry the marker at all.
        $this->alterScratch('ALTER TABLE `' . $table . '` DROP COLUMN `last_failed_event_id`');
        $this->assertNotContains('last_failed_event_id', $this->columns(self::TABLE));

        $now = gmdate('Y-m-d H:i:s');
        $wpdb->insert($table, [
            'donor_id'                => 1,
            'gateway'                 => 'stripe',
            'gateway_subscription_id' => self::SUB_ID,
            'amount_cents'            => 2500,
            'currency'                => 'USD',
            'status'                  => 'active',
            'started_at'              => $now,
            'created_at'              => $now,
            'updated_at'              => $now,
        ]);
        $this->assertSame(1, $this->rowCount(self::TABLE), 'the plan that predates the update');

        $this->runTheRealUpdate();

        $this->assertSame(
            'varchar(191) NOT NULL',
            $this->columnType(self::TABLE, 'last_failed_event_id'),
            'nullable would leave every existing plan permanently unclaimable'
        );
        // get_row, not get_var: WordPress's get_var runs the value through
        // empty(), so it answers null for a column that legitimately holds an
        // empty string, which is exactly the value under test here.
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT last_failed_event_id AS marker FROM `' . $table . '` WHERE gateway_subscription_id = %s',
            self::SUB_ID
        ));
        $this->assertSame(
            '',
            $row->marker ?? null,
            'the existing row is backfilled with a value a claim can compare against'
        );

        // The claim itself, run against the upgraded row: the condition the
        // repository uses has to match a plan that predates the column.
        $claim = 'UPDATE `' . $table . '` SET last_failed_event_id = %s
                  WHERE gateway_subscription_id = %s AND last_failed_event_id <> %s';

        $this->assertSame(
            1,
            (int) $wpdb->query($wpdb->prepare($claim, 'evt_first', self::SUB_ID, 'evt_first')),
            'the first delivery counts'
        );
        $this->assertSame(
            0,
            (int) $wpdb->query($wpdb->prepare($claim, 'evt_first', self::SUB_ID, 'evt_first')),
            'and its redelivery does not'
        );
    }
}
