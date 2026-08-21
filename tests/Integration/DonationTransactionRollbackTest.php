<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\Donation;
use Dono\Donations\DonationRepository;
use Dono\Donations\DonationService;
use Dono\Donations\Refund;
use Dono\Donors\DonorService;
use Dono\Foundation\Plugin;
use Dono\Gateways\GatewayConfirmResult;
use Dono\Gateways\GatewayIntentResult;
use Dono\Gateways\GatewayManager;
use Dono\Gateways\PaymentGateway;
use Dono\Gateways\RefundResult;
use Dono\Gateways\WebhookOutcome;
use Dono\Vendor\Queryable\DB;
use Dono\Vendor\Queryable\QueryException;
use Throwable;
use WP_REST_Request;

/**
 * The money core of DonationService: every block that moves cents has to move
 * all of them or none.
 *
 * Each of these paths reserves against a counter first and writes the row that
 * explains the reservation second. Between the two the donation is briefly
 * describing money that has no record, and the only thing that closes that
 * window is the transaction. Asserted on the counter and the rows an org reads,
 * because that is what a half-applied block leaves wrong.
 *
 * The harness pins Queryable's nesting depth to 1, so each product transaction
 * here is a nested one taking a SAVEPOINT inside WP_UnitTestCase's wrapping
 * transaction.
 */
final class DonationTransactionRollbackTest extends IntegrationTestCase
{
    /** Read by the probe gateway below, so the fixture and the gateway cannot drift. */
    public const REFUND_ID = 'probe_refund_fixed';

    protected function setUp(): void
    {
        parent::setUp();

        // Snapshots the registry so tearDown puts it back without the probe.
        $this->deregisterGateway('dono_no_such_gateway');

        $manager = Plugin::instance()->container->get(GatewayManager::class);
        if (! $manager->get('refundprobe')) {
            $manager->register(new FixedRefundGateway());
        }
    }

    /**
     * refund() reserves the balance with a guarded increment and writes the
     * refund row after it. A failure on the row leaves the donation claiming
     * cents that no refund accounts for: the admin sees a balance already spent
     * and every later refund for the real remainder is refused, while nothing
     * on the record says why.
     */
    public function test_a_refund_row_that_cannot_be_written_gives_back_the_reserved_balance(): void
    {
        $donation = $this->seedPaidDonation(10000);

        // The gateway's unsettled charge.refunded landed first and left an
        // awaited row holding this refund id. The dedup ahead of the block
        // looks only at succeeded rows, so it misses this one and the INSERT
        // below collides on UNIQUE(gateway_refund_id) after the reservation.
        $this->seedRefundRow($donation, self::REFUND_ID, 'pending', 4000);

        $before = $this->refundRowCount();

        try {
            Plugin::instance()->container->get(DonationService::class)->refund($donation, 4000, 'donor asked');
            $this->fail('the duplicate gateway refund id should have failed the insert');
        } catch (QueryException $e) {
            // expected: the row the reservation was made for cannot be written
        }

        $row = $this->donationRow((int) $donation->id);

        $this->assertSame(0, (int) $row['refunded_cents'], 'the reserved cents are given back');
        $this->assertSame('paid', (string) $row['status'], 'the donation is not left half-refunded');
        $this->assertEmpty($row['refunded_at'], 'nothing dates a refund that was never recorded');
        $this->assertSame($before, $this->refundRowCount(), 'no refund row survives the failure');
    }

    /**
     * The same call with an id of its own, so the guard above cannot be
     * satisfied by a refund path that never reserves anything in the first
     * place.
     */
    public function test_the_same_refund_lands_whole_when_the_row_can_be_written(): void
    {
        $donation = $this->seedPaidDonation(10000);

        $refund = Plugin::instance()->container->get(DonationService::class)
            ->refund($donation, 4000, 'donor asked');

        $row = $this->donationRow((int) $donation->id);

        $this->assertSame(4000, (int) $row['refunded_cents'], 'the reservation stands when the block finishes');
        $this->assertSame('partial_refund', (string) $row['status']);
        $this->assertNotEmpty($row['refunded_at']);
        $this->assertSame('succeeded', (string) $refund->status);
        $this->assertSame(
            1,
            (int) Refund::query()->where('donation_id', (int) $donation->id)->count(),
            'exactly one refund row explains the reserved cents'
        );
    }

    /**
     * confirm() flips the donation to paid with a conditional UPDATE that lets
     * exactly one caller win, then writes everything the completion means:
     * paid_at, the fee split, the txn id, the completion event, the aggregates.
     * A failure after the flip and before the rest is the worst state this path
     * can reach, because the flip is also the race guard: the donation reads as
     * paid with no paid_at and no completion event, and every later caller -
     * the redelivered webhook, the redirect return - takes the "lost the race"
     * branch and fires nothing. No receipt, no thank-you email, and no path
     * back except an admin noticing.
     */
    public function test_a_completion_that_fails_after_the_flip_leaves_the_donation_pending(): void
    {
        $donation = $this->seedDonation(5000, 'pending');
        $stop     = $this->breakFirstQueryMatching('paid_at');

        try {
            Plugin::instance()->container->get(DonationService::class)
                ->confirm($donation, ['gateway_txn_id' => 'probe_txn_1', 'fee_cents' => 150]);
            $this->fail('the broken write should have reached the caller');
        } catch (QueryException $e) {
            // expected: the completion cannot be written out
        } finally {
            $this->assertTrue($stop(), 'the injected failure has to have fired, or this proves nothing');
        }

        $row = $this->donationRow((int) $donation->id);

        $this->assertSame('pending', (string) $row['status'], 'the winning flip is undone with the rest');
        $this->assertEmpty($row['paid_at'], 'nothing dates a completion that did not happen');
        $this->assertSame(
            0,
            $this->eventCount('donation.completed', (int) $donation->id),
            'no completion event for a donation that is still pending'
        );
    }

    /** The same confirmation, unbroken, so the guard above cannot pass on a no-op. */
    public function test_the_same_confirmation_lands_whole_when_nothing_fails(): void
    {
        $donation = $this->seedDonation(5000, 'pending');

        Plugin::instance()->container->get(DonationService::class)
            ->confirm($donation, ['gateway_txn_id' => 'probe_txn_1', 'fee_cents' => 150]);

        $row = $this->donationRow((int) $donation->id);

        $this->assertSame('paid', (string) $row['status']);
        $this->assertNotEmpty($row['paid_at']);
        $this->assertSame(
            1,
            $this->eventCount('donation.completed', (int) $donation->id),
            'the completion is on the event log exactly once'
        );
    }

    /**
     * recordExternalRefund settles an awaited refund by deleting the row that
     * was holding the gateway id and writing the settled one in its place. The
     * delete is inside the transaction and after every refusal, so a failure on
     * the replacement has to put the awaited row back: it is the only record
     * the org has that the gateway ever took this refund on.
     */
    public function test_a_failed_settlement_puts_the_awaited_refund_row_back(): void
    {
        $donation = $this->seedPaidDonation(10000);
        $awaited  = $this->seedRefundRow($donation, 'settle_me', 'pending', 4000);

        $stop   = $this->breakFirstQueryMatching('INSERT INTO ' . self::$prefix . 'dono_refunds');
        $thrown = null;
        $returned = null;

        try {
            $returned = Plugin::instance()->container->get(DonationService::class)
                ->recordExternalRefund($donation, 4000, 'settle_me', 'requested_by_customer');
        } catch (Throwable $e) {
            $thrown = $e;
        } finally {
            $this->assertTrue($stop(), 'the injected failure has to have fired, or this proves nothing');
        }

        $row = $this->donationRow((int) $donation->id);
        $this->assertSame(0, (int) $row['refunded_cents'], 'the reserved cents are given back');
        $this->assertSame('paid', (string) $row['status'], 'the donation is not left refunded');

        $still = Refund::query()->where('gateway_refund_id', 'settle_me')->get();
        $this->assertNotNull($still, 'the awaited row is back');
        $this->assertSame((int) $awaited->id, (int) $still->id, 'it is the same row, not a replacement');
        $this->assertSame('pending', (string) $still->status, 'still awaiting settlement');

        // The caller has to be able to tell this apart from a settled refund,
        // or the gateway is acknowledged for money that never came off the books.
        $this->assertNotNull(
            $thrown,
            'a settlement that could not be written must reach the caller, not return the awaited row as the answer'
        );
    }

    /** The settlement this call is for, so the guard above is not satisfied by a no-op. */
    public function test_the_same_settlement_replaces_the_awaited_row_when_nothing_fails(): void
    {
        $donation = $this->seedPaidDonation(10000);
        $awaited  = $this->seedRefundRow($donation, 'settle_me', 'pending', 4000);

        $refund = Plugin::instance()->container->get(DonationService::class)
            ->recordExternalRefund($donation, 4000, 'settle_me', 'requested_by_customer');

        $this->assertSame('succeeded', (string) $refund->status);
        $this->assertNotSame((int) $awaited->id, (int) $refund->id, 'the awaited row was replaced');
        $this->assertSame(
            1,
            (int) Refund::query()->where('gateway_refund_id', 'settle_me')->count(),
            'one row holds the gateway id'
        );

        $row = $this->donationRow((int) $donation->id);
        $this->assertSame(4000, (int) $row['refunded_cents']);
        $this->assertSame('partial_refund', (string) $row['status']);
    }

    /**
     * reverseExternalRefund puts money a won dispute returned back on the
     * books: it releases the reserved cents, marks the refund reversed and
     * un-voids the receipt. A failure partway leaves the donation still short
     * the money while the refund row says it was given back, which is the one
     * state no later reinstatement can repair - the guarded release refuses a
     * second run once the counter no longer covers it.
     */
    public function test_a_failed_reinstatement_leaves_the_refund_standing(): void
    {
        $donation = $this->seedPaidDonation(10000);
        $service  = Plugin::instance()->container->get(DonationService::class);
        $service->recordExternalRefund($donation, 4000, 'disputed_1', 'lost dispute');

        $donation = Plugin::instance()->container->get(DonationRepository::class)
            ->findByReference($donation->reference);

        $stop = $this->breakFirstQueryMatching('refunded_at');

        try {
            $service->reverseExternalRefund($donation, 'disputed_1');
            $this->fail('the broken write should have reached the caller');
        } catch (QueryException $e) {
            // expected: the reinstatement cannot be written out
        } finally {
            $this->assertTrue($stop(), 'the injected failure has to have fired, or this proves nothing');
        }

        $row = $this->donationRow((int) $donation->id);
        $this->assertSame(4000, (int) $row['refunded_cents'], 'the released cents are taken back');
        $this->assertSame('partial_refund', (string) $row['status'], 'the donation is not half-reinstated');

        $refund = Refund::query()->where('gateway_refund_id', 'disputed_1')->get();
        $this->assertSame(
            'succeeded',
            (string) $refund->status,
            'the refund is not marked reversed by a reinstatement that did not finish'
        );
    }

    /** The reinstatement itself, so the guard above is not satisfied by a no-op. */
    public function test_the_same_reinstatement_lands_whole_when_nothing_fails(): void
    {
        $donation = $this->seedPaidDonation(10000);
        $service  = Plugin::instance()->container->get(DonationService::class);
        $service->recordExternalRefund($donation, 4000, 'disputed_1', 'lost dispute');

        $donation = Plugin::instance()->container->get(DonationRepository::class)
            ->findByReference($donation->reference);

        $service->reverseExternalRefund($donation, 'disputed_1');

        $row = $this->donationRow((int) $donation->id);
        $this->assertSame(0, (int) $row['refunded_cents']);
        $this->assertSame('paid', (string) $row['status']);
        $this->assertSame(
            'reversed',
            (string) Refund::query()->where('gateway_refund_id', 'disputed_1')->get()->status
        );
    }

    private function seedPaidDonation(int $cents): Donation
    {
        return $this->seedDonation($cents, 'paid');
    }

    private function seedDonation(int $cents, string $status): Donation
    {
        $donor = Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate('rollback@example.test', ['first_name' => 'R', 'last_name' => 'B']);

        $now = gmdate('Y-m-d H:i:s');
        $d   = Donation::make();
        $d->reference         = 'DONO-TXR-' . substr(md5(uniqid('', true)), 0, 10);
        $d->donor_id          = (int) $donor->id;
        $d->amount_cents      = $cents;
        $d->net_cents         = $cents;
        $d->currency          = 'USD';
        $d->base_amount_cents = $cents;
        $d->base_currency     = 'USD';
        $d->fx_rate           = '1.00000000';
        $d->gateway           = 'refundprobe';
        $d->status            = $status;
        $d->is_test           = false;
        $d->paid_at           = $status === 'paid' ? $now : null;
        $d->created_at        = $now;
        $d->updated_at        = $now;
        $d->save();

        return $d;
    }

    private function seedRefundRow(Donation $donation, string $gatewayRefundId, string $status, int $cents): Refund
    {
        $r = Refund::make();
        $r->donation_id       = (int) $donation->id;
        $r->amount_cents      = $cents;
        $r->currency          = 'USD';
        $r->initiated_by      = 'gateway';
        $r->gateway_refund_id = $gatewayRefundId;
        $r->status            = $status;
        $r->occurred_at       = gmdate('Y-m-d H:i:s');
        $r->save();

        return $r;
    }

    /**
     * Break the first statement carrying $needle, once, by swapping it for one
     * the database refuses. Stands in for the mid-transaction write failure a
     * lock-wait timeout or a lost connection produces on a row this path has
     * already locked. Returns a callable that removes the filter and reports
     * whether it fired.
     */
    private function breakFirstQueryMatching(string $needle): callable
    {
        $fired  = false;
        $filter = function (string $sql) use ($needle, &$fired): string {
            if (! $fired && str_contains($sql, $needle)) {
                $fired = true;

                return 'UPDATE ' . self::$prefix . 'dono_donations SET dono_no_such_column = 1 WHERE id = 0';
            }

            return $sql;
        };

        add_filter('query', $filter);
        self::$wpdb->suppress_errors(true);

        return function () use ($filter, &$fired): bool {
            remove_filter('query', $filter);
            self::$wpdb->suppress_errors(false);

            return $fired;
        };
    }

    private function eventCount(string $type, int $donationId): int
    {
        return (int) DB::table('dono_events')
            ->where('type', $type)
            ->where('donation_id', $donationId)
            ->count();
    }

    /** @return array<string,mixed> */
    private function donationRow(int $id): array
    {
        return (array) DB::table('dono_donations')
            ->where('id', $id)
            ->selectRaw('status, refunded_cents, refunded_at, paid_at')
            ->get();
    }

    private function refundRowCount(): int
    {
        return (int) Refund::query()->count();
    }
}

/** A gateway whose refund id never varies, so a collision can be arranged. */
final class FixedRefundGateway implements PaymentGateway
{
    public function id(): string { return 'refundprobe'; }
    public function label(): string { return 'Refund Probe'; }
    public function description(): string { return 'Refunds with a fixed id.'; }
    public function frequencies(): array { return ['one_time']; }
    public function paymentMethods(): array { return ['card']; }
    public function countries(): array { return ['*']; }
    public function currencies(): array { return ['*']; }
    public function canCharge(): bool { return true; }

    public function createIntent(Donation $donation): GatewayIntentResult
    {
        return new GatewayIntentResult(intent_id: 'probe_intent_1');
    }

    public function confirm(Donation $donation, array $payload = []): GatewayConfirmResult
    {
        return new GatewayConfirmResult(success: true, gateway_txn_id: 'probe_txn_1');
    }

    public function handleWebhook(WP_REST_Request $request): WebhookOutcome
    {
        return WebhookOutcome::notSupported('refundprobe');
    }

    public function refund(Donation $donation, int $amountCents, ?string $reason = null): RefundResult
    {
        return new RefundResult(
            success:           true,
            gateway_refund_id: DonationTransactionRollbackTest::REFUND_ID,
            amount_cents:      $amountCents,
        );
    }

    public function publicConfig(bool $test, string $currency): array
    {
        return [];
    }
}
