<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Analytics\Event;
use Gratora\Analytics\EventRecorder;
use Gratora\Async\AsyncDispatcher;
use Gratora\Core\Commands\CoreCommandProvider;
use Gratora\Donations\Donation;
use Gratora\Donations\DonationIntent;
use Gratora\Donations\DonationRepository;
use Gratora\Donations\DonationService;
use Gratora\Foundation\Commands\CommandContext;
use Gratora\Foundation\Commands\CommandRegistry;
use Gratora\Foundation\Maintenance\AbandonedPendingReaper;
use Gratora\Foundation\Plugin;
use Gratora\Foundation\Time\Clock;
use WP_REST_Request;

/**
 * A trashed row can still be settled, and money that lands has to land
 * somewhere visible.
 *
 * The webhook path never reads the trash, so without this a late settlement
 * would pay a row that is in no list, issue a receipt for it and move a
 * campaign total nobody can reconcile. Two different answers: the gateway
 * untrashes, and an admin is asked to restore it first.
 */
final class TrashedSettlementTest extends IntegrationTestCase
{
    private const TRASHED_BY = 7;

    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    private function service(): DonationService
    {
        return Plugin::instance()->container->get(DonationService::class);
    }

    private function pending(string $gateway = 'stripe'): Donation
    {
        return $this->service()->createPending(new DonationIntent(
            email:        'settle-' . uniqid() . '@example.test',
            amount_cents: 2500,
            currency:     'USD',
            gateway:      $gateway,
            frequency:    'one_time',
        ))['donation'];
    }

    /** updateColumns syncs the caller's model, so the row handed on still knows it is trashed. */
    private function trash(Donation $donation): string
    {
        $at = gmdate('Y-m-d H:i:s');
        $donation->updateColumns(['trashed_at' => $at, 'trashed_by' => self::TRASHED_BY]);

        return $at;
    }

    private function fresh(Donation $donation): Donation
    {
        return Plugin::instance()->container
            ->get(DonationRepository::class)
            ->findById((int) $donation->id);
    }

    /** @return list<Event> */
    private function untrashRows(): array
    {
        return Event::query()->where('type', 'donation.untrashed_by_settlement')->getAll();
    }

    public function test_a_settled_payment_takes_its_row_out_of_the_trash(): void
    {
        $donation = $this->pending();
        $this->trash($donation);

        $this->service()->confirm($donation, ['gateway_txn_id' => 'ch_settled']);

        $fresh = $this->fresh($donation);
        $this->assertSame('paid', (string) $fresh->status);
        $this->assertNull($fresh->trashed_at, 'a donation that took money is not in the bin');
        $this->assertNull($fresh->trashed_by);
    }

    public function test_the_untrash_says_what_it_cleared_and_is_not_donor_activity(): void
    {
        $donation = $this->pending();
        $at       = $this->trash($donation);

        $this->service()->confirm($donation, ['gateway_txn_id' => 'ch_settled']);

        $rows = $this->untrashRows();
        $this->assertCount(1, $rows);

        $row = $rows[0];
        $this->assertSame((int) $donation->id, (int) $row->donation_id);

        $payload = (array) $row->payload;
        $this->assertSame($at, $payload['trashed_at'], 'the row reappearing is explained by what it cleared');
        $this->assertSame(self::TRASHED_BY, (int) $payload['trashed_by']);
        $this->assertSame((string) $donation->reference, $payload['reference']);

        // An admin action rendered on the donor's own timeline would read as
        // something the donor did.
        $this->assertNull($row->donor_id, 'the audit row stays off the donor timeline');
    }

    public function test_a_settlement_that_was_never_trashed_records_no_untrash(): void
    {
        $donation = $this->pending();

        $this->service()->confirm($donation, ['gateway_txn_id' => 'ch_plain']);

        $this->assertSame('paid', (string) $this->fresh($donation)->status);
        $this->assertSame([], $this->untrashRows(), 'nothing was cleared, so nothing is claimed');
    }

    /** A bank debit that starts settling is money on its way, by the same rule. */
    public function test_a_row_moving_to_processing_also_leaves_the_trash(): void
    {
        $donation = $this->pending();
        $this->trash($donation);

        $this->service()->markProcessing($donation, 'settling');

        $fresh = $this->fresh($donation);
        $this->assertSame('processing', (string) $fresh->status);
        $this->assertNull($fresh->trashed_at);
        $this->assertCount(1, $this->untrashRows());
    }

    public function test_marking_a_trashed_row_paid_is_refused(): void
    {
        $donation = $this->pending();
        $this->trash($donation);

        $res = rest_do_request(new WP_REST_Request(
            'POST',
            '/gratora/v1/admin/donations/' . $donation->reference . '/mark-paid'
        ));

        $this->assertGreaterThanOrEqual(400, $res->get_status());

        // Untouched, not half-settled: the admin is asked to restore it first
        // precisely so the decision is visible on the list afterwards.
        $fresh = $this->fresh($donation);
        $this->assertSame('pending', (string) $fresh->status);
        $this->assertNotNull($fresh->trashed_at);
        $this->assertSame([], $this->untrashRows());
    }

    /**
     * The sweep would flip it to failed under "Abandoned before payment.",
     * which is untrue of a row an admin stopped, and would overwrite the
     * status a restore has to put back.
     */
    public function test_the_abandon_sweep_leaves_a_trashed_row_alone(): void
    {
        $donation = $this->pending();
        $this->trash($donation);

        Donation::query()
            ->where('id', (int) $donation->id)
            ->update(['created_at' => gmdate('Y-m-d H:i:s', time() - 60 * DAY_IN_SECONDS)]);

        $c = Plugin::instance()->container;
        (new AbandonedPendingReaper($c->get(AsyncDispatcher::class), $c->get(Clock::class)))->run();

        $fresh = $this->fresh($donation);
        $this->assertSame('pending', (string) $fresh->status, 'an exact restore needs the status it had');
        $this->assertNull($fresh->failure_reason, 'and no reason invented for it');
    }

    /**
     * The same refusal on the command surface, which the assistant and any MCP
     * client reach. Guarding the REST route alone would leave the other door
     * open onto the same write.
     */
    public function test_the_confirm_command_refuses_a_trashed_row(): void
    {
        $donation = $this->pending();
        $this->trash($donation);

        get_role('administrator')->add_cap('gratora_refund_donations');
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $c        = Plugin::instance()->container;
        $registry = new CommandRegistry($c->get(EventRecorder::class));
        (new CoreCommandProvider())->register($registry, $c);

        $res = $registry->dispatch(
            'donation.confirm',
            ['donation_reference' => (string) $donation->reference],
            new CommandContext(1, 'rest', 'req-' . uniqid())
        );

        $this->assertFalse($res->ok);
        $this->assertStringContainsString('trash', strtolower((string) $res->error));

        $fresh = $this->fresh($donation);
        $this->assertSame('pending', (string) $fresh->status);
        $this->assertNotNull($fresh->trashed_at);
        $this->assertSame([], $this->untrashRows());
    }
}
