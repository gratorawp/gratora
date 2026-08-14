<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Async\AsyncDispatcher;
use Dono\Donors\DonorEmailRehasher;
use Dono\Foundation\Plugin;
use Dono\Vendor\Queryable\DB;

/**
 * The rehash walks the donor table a page at a time and reconcile() runs on
 * every request, so the two have to agree on what "already in flight" means.
 * Action Scheduler matches job arguments exactly, so a walk that carried its
 * cursor in them was invisible to its own guard: every request queued another
 * walk from the top of the table, each one re-decrypting and re-hashing every
 * donor, ahead of the receipts and mail waiting in the same group.
 */
final class DonorEmailRehasherQueueTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Activation generates the identity pepper, which queues one of these
        // before any test runs. Counting from zero is what makes "did this
        // request queue another walk" answerable.
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}actionscheduler_actions WHERE hook = %s",
            DonorEmailRehasher::HOOK
        ));
    }

    protected function tearDown(): void
    {
        delete_option(DonorEmailRehasher::PENDING_OPTION);
        delete_option('dono_donor_rehash_after_id');

        parent::tearDown();
    }

    private function rehasher(): DonorEmailRehasher
    {
        return Plugin::instance()->container->get(DonorEmailRehasher::class);
    }

    /** Pending rehash jobs on the queue, whatever arguments they carry. */
    private function queued(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions WHERE hook = %s AND status = 'pending'",
            DonorEmailRehasher::HOOK
        ));
    }

    /**
     * More donors than one batch holds, so the first tick has to hand over to
     * a second. The blobs are not real ciphertext: decrypt returns null and
     * the row is skipped, which is the walk this test is about and not the
     * hashing.
     */
    private function seedDonors(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            DB::table('dono_donors')->insert([
                'email_hash'      => str_pad((string) $i, 64, 'a', STR_PAD_LEFT),
                'email_encrypted' => 'not-really-ciphertext-' . $i,
                'created_at'      => '2026-01-01 00:00:00',
                'updated_at'      => '2026-01-01 00:00:00',
            ]);
        }
    }

    public function test_an_owed_rehash_is_queued(): void
    {
        DonorEmailRehasher::markPending();

        $this->rehasher()->reconcile();

        $this->assertSame(1, $this->queued());
    }

    public function test_a_walk_in_flight_stops_the_next_request_queueing_another(): void
    {
        $this->seedDonors(201);
        DonorEmailRehasher::markPending();

        $rehasher = $this->rehasher();

        // The first tick reads a full batch, so it hands over to a
        // continuation and leaves it waiting on the queue.
        $rehasher->run();
        $this->assertSame(1, $this->queued(), 'precondition: a continuation is waiting');

        // Every request after that reconciles again while it waits.
        $rehasher->reconcile();
        $rehasher->reconcile();

        $this->assertSame(1, $this->queued(), 'reconcile must not fork a second walk from the top');
    }

    /** The continuation has to resume, not restart, or the walk never ends. */
    public function test_the_walk_resumes_where_it_left_off(): void
    {
        $this->seedDonors(201);
        DonorEmailRehasher::markPending();

        $rehasher = $this->rehasher();
        $rehasher->run();

        $this->assertGreaterThan(
            0,
            (int) get_option('dono_donor_rehash_after_id', 0),
            'the first batch records how far it got'
        );

        // The last row is inside this batch, so the walk is done.
        $rehasher->run();

        $this->assertFalse(get_option(DonorEmailRehasher::PENDING_OPTION), 'nothing is owed any more');
        $this->assertFalse(get_option('dono_donor_rehash_after_id'), 'and no cursor is left behind');
    }

    /**
     * A new pepper invalidates every hash on the table, including the ones a
     * half-finished walk has already rewritten. Resuming from the old cursor
     * would leave every donor below it hashed under a pepper nothing looks
     * them up by any more: they stay in the table and stop being findable by
     * their own address, so a receipt or a portal sign-in creates a duplicate.
     */
    public function test_a_new_pepper_sends_an_unfinished_walk_back_to_the_top(): void
    {
        $this->seedDonors(201);
        DonorEmailRehasher::markPending();

        $rehasher = $this->rehasher();
        $rehasher->run();

        $cursor = (int) get_option('dono_donor_rehash_after_id', 0);
        $this->assertGreaterThan(0, $cursor, 'precondition: the walk is part way down the table');

        DonorEmailRehasher::markPending();

        $this->assertFalse(
            get_option('dono_donor_rehash_after_id'),
            'the rows already rewritten are stale too, so the walk starts again'
        );
    }

    /** A table smaller than one batch finishes on the first tick. */
    public function test_a_short_table_finishes_without_a_continuation(): void
    {
        $this->seedDonors(3);
        DonorEmailRehasher::markPending();

        $this->rehasher()->run();

        $this->assertSame(0, $this->queued());
        $this->assertFalse(get_option(DonorEmailRehasher::PENDING_OPTION));
    }

    /** Nothing owed, nothing queued, on every request of a healthy site. */
    public function test_nothing_owed_queues_nothing(): void
    {
        delete_option(DonorEmailRehasher::PENDING_OPTION);

        $this->rehasher()->reconcile();

        $this->assertSame(0, $this->queued());
    }
}
