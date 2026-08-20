<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\Donation;
use Dono\Donations\DonationIntent;
use Dono\Donations\DonationService;
use Dono\Donors\Donor;
use Dono\Donors\DonorNote;
use Dono\Donors\DonorRetention;
use Dono\Donors\DonorService;
use Dono\Donors\Erasure\ErasureHandler;
use Dono\Donors\Erasure\ErasureRequest;
use Dono\Foundation\Batch\BatchProcessor;
use Dono\Foundation\Plugin;
use Dono\Foundation\References\ReferenceGenerator;
use Dono\Settings\SettingsService;
use RuntimeException;
use Throwable;

/**
 * The one property DB::transaction exists to provide: a block that cannot
 * finish leaves nothing of itself behind.
 *
 * Every case here goes through a product path that already promises this in
 * its own comments - the donation sequence never spending a number on a
 * donation that was not written, an erasure that half-happened rolling back
 * rather than reporting a compliance action as done, the nightly sweep leaving
 * a donor it could not erase whole. The promise is what is asserted, on the
 * rows and counters an org would be looking at.
 *
 * The harness pins Queryable's nesting depth to 1 (see IntegrationTestCase),
 * so every product transaction below is a nested one riding WP_UnitTestCase's
 * wrapping transaction. That is the shape production runs in whenever a
 * transaction encloses another, and it is the only shape a test can observe.
 */
final class TransactionRollbackTest extends IntegrationTestCase
{
    public const SEAM_FAILURE    = 'the add-on could not write its row';
    public const HANDLER_FAILURE = 'this add-on cannot complete its part';

    private const NOTE_BODY = 'a staff note that predates the erasure';

    /**
     * The donation sequence is gap-free because an auditor reads it that way,
     * and next() spends its number inside the donation's own transaction. A
     * creation that fails after the number is drawn therefore has to give it
     * back, or the ledger carries a hole nobody can account for.
     */
    public function test_a_failed_creation_does_not_spend_a_number_from_the_donation_sequence(): void
    {
        $references = Plugin::instance()->container->get(ReferenceGenerator::class);
        $expected   = $references->peekNext('donation');

        $this->whileTheSeamThrows(function (): void {
            try {
                $this->donations()->createPending($this->intent('sequence-probe@example.test'));
                $this->fail('the subscriber exception should reach the caller');
            } catch (RuntimeException $e) {
                $this->assertSame(self::SEAM_FAILURE, $e->getMessage());
            }
        });

        $this->assertSame(
            $expected,
            $references->peekNext('donation'),
            'the failed creation kept the number it drew'
        );

        $created = $this->donations()->createPending($this->intent('sequence-next@example.test'));

        $this->assertStringContainsString(
            str_pad((string) $expected, 5, '0', STR_PAD_LEFT),
            (string) $created['donation']->reference,
            'the next real donation did not get the number the failed one drew'
        );
    }

    /** The rows themselves, on the same path: neither the donation nor the donor it made. */
    public function test_a_failed_creation_leaves_neither_the_donation_nor_the_donor_it_made(): void
    {
        $email = 'rollback-nobody@example.test';

        $donationsBefore = (int) Donation::query()->count();
        $donorsBefore    = (int) Donor::query()->count();

        $this->whileTheSeamThrows(function () use ($email): void {
            try {
                $this->donations()->createPending($this->intent($email));
                $this->fail('the subscriber exception should reach the caller');
            } catch (RuntimeException $e) {
                $this->assertSame(self::SEAM_FAILURE, $e->getMessage());
            }
        });

        $this->assertSame($donationsBefore, (int) Donation::query()->count(), 'the donation outlived the failure');
        $this->assertSame($donorsBefore, (int) Donor::query()->count(), 'the donor outlived the failure');
    }

    /**
     * Erasure is reported to the org as done and cannot be repeated, so a
     * handler that cannot finish its part has to undo the rest. What is left
     * otherwise is a donor marked erased with only some of their data gone,
     * and nothing that will ever come back for the remainder.
     */
    public function test_a_failed_erasure_leaves_the_donor_and_everything_hanging_off_them_whole(): void
    {
        $donor    = $this->donorWithHistory('erasure-rollback@example.test');
        $donorId  = (int) $donor->id;
        $donation = Donation::query()->where('donor_id', $donorId)->get();

        $this->whileAHandlerThrows([], function () use ($donorId): void {
            try {
                Plugin::instance()->container->get(DonorService::class)
                    ->redact(Donor::query()->find('id', $donorId));
                $this->fail('the handler exception should reach the caller');
            } catch (RuntimeException $e) {
                $this->assertSame(self::HANDLER_FAILURE, $e->getMessage());
            }
        });

        $after = Donor::query()->find('id', $donorId);

        $this->assertNull($after->redacted_at, 'the donor is marked erased after an erasure that failed');
        $this->assertSame('Wilhelmina', $after->first_name, 'the donor lost their name to an erasure that failed');
        $this->assertSame($donor->email_encrypted, $after->email_encrypted, 'the donor lost their email to an erasure that failed');

        $donationAfter = Donation::query()->find('id', (int) $donation->id);

        $this->assertSame('Wilhelmina', $donationAfter->donor_first_name, 'the donation lost its payer name');
        $this->assertSame('please use this where it is needed most', $donationAfter->note_to_org, 'the donation lost the donor message');

        $this->assertSame(
            1,
            (int) DonorNote::query()->where('donor_id', $donorId)->count(),
            'the staff note the erasure deleted did not come back'
        );
    }

    /**
     * The nightly sweep runs third-party handlers once per donor and steps past
     * anyone it cannot erase. Stepping past is only safe if the attempt left
     * nothing behind: a donor whose erasure half-ran is neither erased nor
     * whole, and the sweep has already moved on.
     */
    public function test_a_sweep_that_cannot_erase_one_donor_leaves_no_half_erased_record(): void
    {
        $this->erasureIsRunning();

        $poison = $this->donorWithHistory('sweep-poison@example.test', 20);
        $behind = $this->donorWithHistory('sweep-behind@example.test', 20);

        $this->whileAHandlerThrows([(int) $poison->id], function (): void {
            Plugin::instance()->container->get(DonorRetention::class)->run();
        });

        $poisonAfter = Donor::query()->find('id', (int) $poison->id);

        $this->assertNull($poisonAfter->redacted_at, 'the failed erasure stuck');
        $this->assertSame('Wilhelmina', $poisonAfter->first_name, 'the donor the sweep stepped past lost their name anyway');
        $this->assertSame(
            'Wilhelmina',
            Donation::query()->where('donor_id', (int) $poison->id)->get()->donor_first_name,
            'their donation was stripped by an erasure that never completed'
        );
        $this->assertSame(
            1,
            (int) DonorNote::query()->where('donor_id', (int) $poison->id)->count(),
            'their staff note was deleted by an erasure that never completed'
        );

        $this->assertNotNull(
            Donor::query()->find('id', (int) $behind->id)->redacted_at,
            'the donor queued behind them was not erased'
        );
    }

    /**
     * A batch is transactional so one bad item does not commit half a pass, and
     * the per-item work is transactional in its own right. The inner failure
     * has to undo its own item only: taking the outer block with it would
     * discard every item already handled.
     */
    public function test_an_item_that_fails_inside_a_transactional_batch_does_not_take_the_batch_with_it(): void
    {
        $good   = $this->donorWithHistory('batch-good@example.test');
        $poison = $this->donorWithHistory('batch-poison@example.test');

        $this->whileAHandlerThrows([(int) $poison->id], function () use ($good, $poison): void {
            BatchProcessor::step(
                static fn (int $n): array => [(int) $good->id, (int) $poison->id],
                function (array $ids): void {
                    $service = Plugin::instance()->container->get(DonorService::class);
                    foreach ($ids as $id) {
                        try {
                            $service->redact(Donor::query()->find('id', $id));
                        } catch (Throwable $e) {
                            // What the sweep does with a donor it cannot erase.
                            continue;
                        }
                    }
                },
                10
            );
        });

        $this->assertNotNull(
            Donor::query()->find('id', (int) $good->id)->redacted_at,
            'the item handled before the failure was rolled back with it'
        );
        $this->assertNull(
            Donor::query()->find('id', (int) $poison->id)->redacted_at,
            'the item that failed was left half erased'
        );
    }

    /**
     * A rolled-back block leaves the transaction it was nested in open and
     * writable. Ending it instead would take the harness's per-test isolation
     * with it, and in production the enclosing block's work.
     */
    public function test_a_rolled_back_block_leaves_the_enclosing_transaction_usable(): void
    {
        $donor      = $this->donorWithHistory('still-writable@example.test');
        $donationId = (int) Donation::query()->where('donor_id', (int) $donor->id)->get()->id;

        $this->whileAHandlerThrows([], function () use ($donor): void {
            try {
                Plugin::instance()->container->get(DonorService::class)
                    ->redact(Donor::query()->find('id', (int) $donor->id));
            } catch (RuntimeException $e) {
                // The rollback is the setup; what follows it is the claim.
            }
        });

        $this->assertNotNull(
            Donation::query()->find('id', $donationId),
            'the rollback reached past its own block and took the enclosing transaction with it'
        );

        $created = $this->donations()->createPending($this->intent('after-rollback@example.test'));

        $this->assertNotNull(
            Donation::query()->find('id', (int) $created['donation']->id),
            'a write after a rolled-back block did not land'
        );
    }

    /**
     * A TypeError, a failed type coercion, an assertion: none of them is an
     * Exception. A catch that names anything narrower than Throwable lets them
     * past, and by then the block has already written, so the erasure that was
     * supposed to be atomic leaves a half erased donor behind.
     */
    public function test_an_error_rather_than_an_exception_still_rolls_the_block_back(): void
    {
        $donor   = $this->donorWithHistory('error-not-exception@example.test');
        $donorId = (int) $donor->id;

        $this->whileAHandlerThrowsAnError(function () use ($donorId): void {
            try {
                Plugin::instance()->container->get(DonorService::class)
                    ->redact(Donor::query()->find('id', $donorId));
            } catch (\Throwable $e) {
                // The throw is the setup; what it left standing is the claim.
            }
        });

        $after = Donor::query()->find('id', $donorId);

        $this->assertNull(
            $after->redacted_at,
            'an Error left the donor marked erased, so the rollback never ran for it'
        );
        $this->assertSame(
            'Wilhelmina',
            (string) $after->first_name,
            'the donor lost their name to an erasure an Error should have undone'
        );
    }

    /**
     * Isolation, in the two halves it takes to prove it. This one runs a
     * product transaction that fails and one that succeeds, because it is the
     * successful block that decides whether the wrapping transaction is still
     * open at the end of the test: released, it survives to be rolled back;
     * committed, everything this test wrote is in the database for good.
     *
     * @return array{marker: string, donation: int, transient: string}
     */
    public function test_it_leaves_rows_behind_for_the_next_test_to_look_for(): array
    {
        $marker    = 'Rollback' . strtoupper(bin2hex(random_bytes(5)));
        $transient = 'dono_isolation_probe_' . strtolower($marker);

        $donor = $this->donorWithHistory('isolation-before@example.test');
        $donor->last_name = $marker;
        $donor->save();

        $this->whileAHandlerThrows([], function () use ($donor): void {
            try {
                Plugin::instance()->container->get(DonorService::class)
                    ->redact(Donor::query()->find('id', (int) $donor->id));
            } catch (RuntimeException $e) {
                // Deliberate: the point is what survives the test, not the throw.
            }
        });

        $donationId = (int) $this->donations()
            ->createPending($this->intent('isolation-after@example.test'))['donation']->id;

        set_transient($transient, 'still here', HOUR_IN_SECONDS);

        $this->assertSame(1, (int) Donor::query()->where('last_name', $marker)->count());
        $this->assertNotNull(Donation::query()->find('id', $donationId));
        $this->assertSame('still here', get_transient($transient));

        return ['marker' => $marker, 'donation' => $donationId, 'transient' => $transient];
    }

    /**
     * @depends test_it_leaves_rows_behind_for_the_next_test_to_look_for
     *
     * @param array{marker: string, donation: int, transient: string} $left
     */
    public function test_nothing_the_previous_test_wrote_survived_into_this_one(array $left): void
    {
        $this->assertSame(
            0,
            (int) Donor::query()->where('last_name', $left['marker'])->count(),
            'donor rows leaked out of the previous test'
        );
        $this->assertNull(
            Donation::query()->find('id', $left['donation']),
            'a donation from a committed product transaction leaked out of the previous test'
        );
        $this->assertFalse(
            get_transient($left['transient']),
            'a transient leaked out of the previous test'
        );
    }

    private function donations(): DonationService
    {
        return Plugin::instance()->container->get(DonationService::class);
    }

    private function intent(string $email): DonationIntent
    {
        return new DonationIntent(
            email: $email,
            amount_cents: 2500,
            currency: 'USD',
            gateway: 'offline',
            profile: ['first_name' => 'Iris', 'last_name' => 'Ledger'],
        );
    }

    /** Runs $body with a subscriber on the in-transaction creation seam that throws. */
    private function whileTheSeamThrows(callable $body): void
    {
        $throw = static function (): void {
            throw new RuntimeException(self::SEAM_FAILURE);
        };
        add_action('dono.donation.creating', $throw);

        try {
            $body();
        } finally {
            remove_action('dono.donation.creating', $throw);
        }
    }

    /**
     * Runs $body with an erasure handler that throws for the given donors, or
     * for everyone when the list is empty. It undoes nothing on the way out:
     * whatever the erasure had already written is left for the rollback.
     *
     * @param list<int> $donorIds
     */
    private function whileAHandlerThrows(array $donorIds, callable $body): void
    {
        $handler = new class ($donorIds) implements ErasureHandler {
            /** @param list<int> $donorIds */
            public function __construct(private array $donorIds)
            {
            }

            public function key(): string
            {
                return 'test.rollback';
            }

            public function erase(ErasureRequest $request): void
            {
                if ($this->donorIds !== [] && ! in_array($request->donorId, $this->donorIds, true)) {
                    return;
                }

                throw new RuntimeException(TransactionRollbackTest::HANDLER_FAILURE);
            }
        };

        // Appended after core's own handler, so core has already cleared the
        // donor's data by the time this throws.
        $add = static function (array $h) use ($handler): array {
            $h[] = $handler;
            return $h;
        };
        add_filter('dono.donor.erasure_handlers', $add);

        try {
            $body();
        } finally {
            remove_filter('dono.donor.erasure_handlers', $add);
        }
    }

    /** The same seam as whileAHandlerThrows, throwing what a catch(Exception) misses. */
    private function whileAHandlerThrowsAnError(callable $body): void
    {
        $handler = new class implements ErasureHandler {
            public function key(): string
            {
                return 'test.rollback.error';
            }

            public function erase(ErasureRequest $request): void
            {
                throw new \Error(TransactionRollbackTest::HANDLER_FAILURE);
            }
        };

        $add = static function (array $h) use ($handler): array {
            $h[] = $handler;
            return $h;
        };
        add_filter('dono.donor.erasure_handlers', $add);

        try {
            $body();
        } finally {
            remove_filter('dono.donor.erasure_handlers', $add);
        }
    }

    /**
     * A donor with the three kinds of row an erasure reaches: their own record,
     * the payer details on a donation, and a staff note that gets deleted
     * outright.
     */
    private function donorWithHistory(string $email, int $yearsIdle = 0): Donor
    {
        $now  = gmdate('Y-m-d H:i:s');
        $then = $yearsIdle > 0 ? gmdate('Y-m-d H:i:s', time() - ($yearsIdle * 365 * 86400)) : $now;

        $donor = Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate($email, ['first_name' => 'Wilhelmina', 'last_name' => 'Bletchley']);

        $donor->last_donation_at = $then;
        $donor->created_at       = $then;
        $donor->updated_at       = $then;
        $donor->save();

        $donation = Donation::make();
        $donation->reference         = 'ROLL-' . strtoupper(bin2hex(random_bytes(4)));
        $donation->donor_id          = (int) $donor->id;
        $donation->donor_first_name  = 'Wilhelmina';
        $donation->donor_last_name   = 'Bletchley';
        $donation->note_to_org       = 'please use this where it is needed most';
        $donation->amount_cents      = 5000;
        $donation->net_cents         = 5000;
        $donation->base_amount_cents = 5000;
        $donation->base_currency     = 'USD';
        $donation->currency          = 'USD';
        $donation->gateway           = 'offline';
        $donation->status            = 'paid';
        $donation->frequency         = 'one_time';
        $donation->kind              = 'donation';
        $donation->is_test           = false;
        $donation->paid_at           = $then;
        $donation->created_at        = $then;
        $donation->updated_at        = $then;
        $donation->save();

        $note = DonorNote::make();
        $note->donor_id       = (int) $donor->id;
        $note->body_encrypted = self::NOTE_BODY;
        $note->created_at     = $now;
        $note->updated_at     = $now;
        $note->save();

        return $donor;
    }

    /** Erasure switched on, and the grace period already behind us. */
    private function erasureIsRunning(): void
    {
        Plugin::instance()->container->get(SettingsService::class)
            ->update('privacy', [
                'erase_inactive_donors' => true,
                'donor_retention_years' => 1,
            ]);

        update_option(DonorRetention::STARTS_AT_OPTION, time() - 86400, false);
    }

    protected function tearDown(): void
    {
        delete_option('dono_privacy');
        delete_option(DonorRetention::STARTS_AT_OPTION);
        parent::tearDown();
    }
}
