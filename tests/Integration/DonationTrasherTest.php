<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Analytics\Event;
use Gratora\Donations\Donation;
use Gratora\Donations\DonationIntent;
use Gratora\Donations\DonationRepository;
use Gratora\Donations\DonationService;
use Gratora\Donations\DonationTrasher;
use Gratora\Donations\TrashOutcome;
use Gratora\Foundation\Plugin;
use Gratora\Gateways\GatewayManager;
use Gratora\Recurring\RecurringPlan;
use Gratora\Gateways\Stripe\StripeAccount;
use Gratora\Gateways\Stripe\StripeGateway;

/**
 * Taking a spam attempt off the working list.
 *
 * The order is the whole thing: stop the payment, then hide the row. A row
 * hidden while its charge is still open is the failure this exists to prevent,
 * and it is invisible afterwards precisely because the row is no longer on any
 * screen that would show it.
 */
final class DonationTrasherTest extends IntegrationTestCase
{
    private string $intentStatus = 'requires_payment_method';

    /** @var list<string> "METHOD path" the Stripe stub was asked for. */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        update_option('gratora_gateway_config', [
            'test_mode' => true,
            'offline'   => ['instructions' => 'Transfer the amount quoting your reference.'],
            'stripe'    => ['webhook_secret_test' => 'whsec_trash'],
        ]);

        $c    = Plugin::instance()->container;
        $acct = $c->get(StripeAccount::class);
        $acct->saveKeys(true, 'sk_test_trash', 'pk_test_seed');
        $acct->refresh(['id' => 'acct_trash', 'charges_enabled' => true]);

        $manager = $c->get(GatewayManager::class);
        if (! $manager->get('stripe')) {
            $manager->register(new StripeGateway(
                $c->get(\Gratora\Gateways\Stripe\StripeApi::class),
                $c->get(DonationRepository::class),
                $c->get(DonationService::class),
                $acct,
                $c->get(\Gratora\Donors\DonorRepository::class),
                $c->get(\Gratora\Donors\DonorService::class),
                $c->get(\Gratora\Foundation\Time\Clock::class),
                $c->get(\Gratora\Recurring\RecurringPlanRepository::class),
            ));
        }

        add_filter('pre_http_request', function ($pre, $args, $url) {
            if (! is_string($url) || ! str_starts_with($url, 'https://api.stripe.com/')) return $pre;

            $path          = (string) (parse_url($url)['path'] ?? '');
            $this->calls[] = strtoupper((string) ($args['method'] ?? 'POST')) . ' ' . $path;
            $status        = str_contains($path, '/cancel') ? 'canceled' : $this->intentStatus;

            return [
                'headers'  => [],
                'body'     => (string) wp_json_encode(['id' => 'pi_trash', 'status' => $status]),
                'response' => ['code' => 200, 'message' => 'OK'],
                'cookies'  => [], 'filename' => null,
            ];
        }, 10, 3);
    }

    private function trasher(): DonationTrasher
    {
        return Plugin::instance()->container->get(DonationTrasher::class);
    }

    /** @param array<string,mixed> $columns */
    private function pending(string $gateway, array $columns = []): Donation
    {
        $donation = Plugin::instance()->container->get(DonationService::class)->createPending(new DonationIntent(
            email:        'trash-' . uniqid() . '@example.test',
            amount_cents: 2500,
            currency:     'USD',
            gateway:      $gateway,
            frequency:    'one_time',
        ))['donation'];

        if ($columns !== []) {
            $donation->updateColumns($columns);
        }

        return $donation;
    }

    private function fresh(Donation $donation): Donation
    {
        return Plugin::instance()->container
            ->get(DonationRepository::class)
            ->findById((int) $donation->id);
    }

    /** @return list<Event> */
    private function auditRows(string $type): array
    {
        return Event::query()->where('type', $type)->getAll();
    }

    private function cancelled(): bool
    {
        foreach ($this->calls as $call) {
            if (str_contains($call, '/cancel')) return true;
        }

        return false;
    }

    public function test_an_abandoned_attempt_is_stopped_at_the_gateway_and_then_hidden(): void
    {
        $donation = $this->pending('stripe', ['gateway_intent_id' => 'pi_trash', 'is_test' => true]);

        $outcome = $this->trasher()->trash($donation);

        $this->assertSame(TrashOutcome::TRASHED, $outcome->outcome, (string) $outcome->reason);
        $this->assertTrue($this->cancelled(), 'the charge was actually closed, not merely hidden');

        $fresh = $this->fresh($donation);
        $this->assertNotNull($fresh->trashed_at);
        $this->assertNotNull($fresh->payment_stopped_at);

        // failed is not a stopped state: confirm() accepts it as a source
        // status, so expressing the stop there would lose the exact restore.
        $this->assertSame('pending', (string) $fresh->status, 'the status is the one a restore has to put back');
    }

    public function test_a_payment_that_already_completed_is_refused_and_left_alone(): void
    {
        $this->intentStatus = 'succeeded';
        $donation = $this->pending('stripe', ['gateway_intent_id' => 'pi_trash', 'is_test' => true]);

        $outcome = $this->trasher()->trash($donation);

        $this->assertSame(TrashOutcome::REFUSED, $outcome->outcome);
        $this->assertStringContainsString('succeeded', (string) $outcome->reason);
        $this->assertFalse($this->cancelled(), 'a settled payment is never cancelled');
        $this->assertNull($this->fresh($donation)->trashed_at, 'and the row is untouched');
    }

    /**
     * The headline case of the whole feature: an out-of-band pledge is the one
     * row the abandon sweep never touches, so without its own stop record the
     * donor gate would hold it forever.
     */
    public function test_an_offline_pledge_is_trashed_at_once_with_its_own_stop_reason(): void
    {
        $donation = $this->pending('offline');

        $outcome = $this->trasher()->trash($donation);

        $this->assertSame(TrashOutcome::TRASHED, $outcome->outcome, (string) $outcome->reason);
        $this->assertFalse($outcome->paymentStopped, 'there was nothing open to close, and the notice says so');

        $fresh = $this->fresh($donation);
        $this->assertNotNull($fresh->payment_stopped_at);
        $this->assertSame('nothing_was_open_to_close', (string) $fresh->payment_stopped_reason);
    }

    /**
     * Core has no opinion about a kind it does not own. It used to refuse a
     * ticket order payment and say to manage it from the order, and the
     * add-on that owns orders has no way to remove one, so the refusal named
     * nothing anybody could do. An add-on with a reason refuses through its
     * own filter.
     */
    public function test_a_ticket_order_payment_is_not_refused_by_core(): void
    {
        $donation = $this->pending('offline', ['kind' => 'order']);

        $this->assertSame(TrashOutcome::TRASHED, $this->trasher()->trash($donation)->outcome);
    }

    public function test_an_add_on_can_still_refuse_a_kind_it_owns(): void
    {
        $donation = $this->pending('offline', ['kind' => 'order']);

        add_filter('gratora.donation.untrashable_reason', static fn () => 'Manage it from the order.');

        try {
            $outcome = $this->trasher()->trash($donation);
            $this->assertSame(TrashOutcome::REFUSED, $outcome->outcome);
            $this->assertStringContainsString('order', strtolower((string) $outcome->reason));
            $this->assertNull($this->fresh($donation)->trashed_at);
        } finally {
            remove_all_filters('gratora.donation.untrashable_reason');
        }
    }

    public function test_a_row_on_a_plan_that_is_still_billing_is_refused(): void
    {
        $donation = $this->pending('offline', ['recurring_plan_id' => (int) $this->plan('active')->id]);

        $outcome = $this->trasher()->trash($donation);

        $this->assertSame(TrashOutcome::REFUSED, $outcome->outcome);
        $this->assertStringContainsString('subscription', strtolower((string) $outcome->reason));
    }

    /**
     * A plan id pointing at nothing is not a mandate. The row survived its
     * plan, so the billing was stopped when the plan went, and holding it for
     * a cancel nobody can perform refuses forever.
     */
    public function test_a_row_whose_plan_no_longer_exists_is_not_refused_for_it(): void
    {
        $donation = $this->pending('offline', ['recurring_plan_id' => 999999]);

        $this->assertSame(TrashOutcome::TRASHED, $this->trasher()->trash($donation)->outcome);
    }

    private function plan(string $status): RecurringPlan
    {
        $now = gmdate('Y-m-d H:i:s');
        $p = RecurringPlan::make();
        $p->donor_id                = 1;
        $p->gateway                 = 'offline';
        $p->gateway_subscription_id = 'sub_' . bin2hex(random_bytes(3));
        $p->status                  = $status;
        $p->amount_cents            = 2500;
        $p->currency                = 'USD';
        $p->interval_unit           = 'month';
        $p->interval_count          = 1;
        $p->started_at              = $now;
        $p->created_at              = $now;
        $p->updated_at              = $now;
        $p->save();

        return $p;
    }

    /**
     * The bin takes money now. It used to refuse a paid row and point at
     * Refund, which left the reversible action unavailable on exactly the rows
     * the irreversible one was offered for. Nothing moves: the aggregates
     * filter on is_test and never on trashed_at, so a row keeps counting from
     * inside the bin.
     */
    public function test_a_paid_row_is_taken_and_nothing_is_stopped(): void
    {
        $donation = $this->pending('offline', ['status' => 'paid', 'paid_at' => gmdate('Y-m-d H:i:s')]);

        $outcome = $this->trasher()->trash($donation);

        $this->assertSame(TrashOutcome::TRASHED, $outcome->outcome, (string) $outcome->reason);

        $fresh = $this->fresh($donation);
        $this->assertNotNull($fresh->trashed_at);
        $this->assertNull($fresh->payment_stopped_at, 'there was no open payment to stop');
        $this->assertNull($fresh->payment_stopped_reason);
    }

    /**
     * A transaction id on a row that never reached paid used to be its own
     * refusal. It is not one any more, and the close still runs: what the row
     * saw is a reconciliation question, and the bin is not where that is
     * answered either way.
     */
    public function test_a_pending_row_that_saw_money_is_taken_and_still_closed(): void
    {
        $donation = $this->pending('offline', ['gateway_txn_id' => 'ch_seen']);

        $outcome = $this->trasher()->trash($donation);

        $this->assertSame(TrashOutcome::TRASHED, $outcome->outcome, (string) $outcome->reason);
        $this->assertNotNull(
            $this->fresh($donation)->payment_stopped_at,
            'it never settled, so the payment was still the bin\'s to close'
        );
    }

    public function test_trashing_a_retry_child_returns_its_parent_to_the_list(): void
    {
        $parent = $this->pending('offline');
        $child  = $this->pending('offline');

        Plugin::instance()->container->get(DonationService::class)
            ->recordRetriedBy($parent, (string) $child->reference);

        $this->assertArrayHasKey('retried_by', (array) $this->fresh($parent)->flags);

        $this->assertSame(TrashOutcome::TRASHED, $this->trasher()->trash($child)->outcome);

        $flags = (array) ($this->fresh($parent)->flags ?? []);
        $this->assertArrayNotHasKey('retried_by', $flags, 'the parent is a real attempt and belongs back in the list');
        $this->assertNull($this->fresh($parent)->trashed_at, 'clearing the marker does not trash the parent too');
    }

    public function test_the_trash_is_recorded_and_is_not_donor_activity(): void
    {
        $donation = $this->pending('offline');

        $this->trasher()->trash($donation, 'obvious card testing');

        $rows = $this->auditRows('donation.trashed');
        $this->assertCount(1, $rows);

        $row = $rows[0];
        $this->assertSame((int) $donation->id, (int) $row->donation_id);
        $this->assertNull($row->donor_id, 'an admin action must not render as the donor doing something');

        $payload = (array) $row->payload;
        $this->assertSame((string) $donation->reference, $payload['reference']);
        $this->assertSame('obvious card testing', $payload['note']);
        $this->assertNotSame('', (string) $payload['actor_name']);
    }

    public function test_restore_puts_it_back_and_the_payment_stays_stopped(): void
    {
        $donation = $this->pending('offline');
        $this->trasher()->trash($donation);

        $this->trasher()->restore($donation);

        $fresh = $this->fresh($donation);
        $this->assertNull($fresh->trashed_at);
        $this->assertSame('pending', (string) $fresh->status, 'restored to the status it had');

        // Untouched on purpose: that is what lets the restored row say its
        // payment is still stopped without a field of its own.
        $this->assertNotNull($fresh->payment_stopped_at);
        $this->assertCount(1, $this->auditRows('donation.restored'));
    }

    public function test_restoring_twice_records_one_audit_row(): void
    {
        $donation = $this->pending('offline');
        $this->trasher()->trash($donation);

        $this->trasher()->restore($donation);
        $this->trasher()->restore($this->fresh($donation));

        $this->assertCount(1, $this->auditRows('donation.restored'), 'only the request that moved it announces anything');
    }

    public function test_trashing_a_row_already_in_the_trash_is_not_an_error(): void
    {
        $donation = $this->pending('offline');
        $this->trasher()->trash($donation);

        $outcome = $this->trasher()->trash($donation);

        $this->assertSame(TrashOutcome::ALREADY, $outcome->outcome);
        $this->assertTrue($outcome->ok());
        $this->assertCount(1, $this->auditRows('donation.trashed'), 'and records nothing the second time');
    }
}
