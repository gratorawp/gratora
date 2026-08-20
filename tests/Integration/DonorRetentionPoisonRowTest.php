<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Analytics\ErrorLog;
use Dono\Analytics\Event;
use Dono\Donors\Donor;
use Dono\Donors\DonorRetention;
use Dono\Donors\Erasure\ErasureHandler;
use Dono\Donors\Erasure\ErasureRequest;
use Dono\Foundation\Plugin;
use Dono\Settings\SettingsService;
use Dono\Vendor\Queryable\DB;
use ReflectionClassConstant;
use RuntimeException;

/**
 * The nightly sweep runs third-party code, through the documented
 * `dono.donor.erasure_handlers` filter, once per donor and inside the loop. A
 * handler that throws leaves that donor unredacted, so a sweep that always
 * reads the oldest matching rows would be handed the same donor every night
 * and never reach anyone behind them: an org that asked for automatic erasure
 * would keep the data of everyone in the queue while believing it was gone.
 *
 * So the sweep steps past a donor it cannot erase, says so where the operator
 * can see it, and starts again from the top on the next pass.
 */
final class DonorRetentionPoisonRowTest extends IntegrationTestCase
{
    private function retention(): DonorRetention
    {
        return Plugin::instance()->container->get(DonorRetention::class);
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

    /** A donor far enough past the window that the sweep must take them. */
    private function ancientDonor(string $first = 'Ancient'): Donor
    {
        $long = gmdate('Y-m-d H:i:s', time() - (20 * 365 * 86400));

        $d = Donor::make();
        $d->email_hash       = hash('sha256', uniqid('poison', true));
        $d->email_encrypted  = 'x';
        $d->first_name       = $first;
        $d->last_name        = 'Probe';
        $d->last_donation_at = $long;
        $d->created_at       = $long;
        $d->updated_at       = $long;
        $d->save();

        return $d;
    }

    /**
     * An add-on that cannot finish its part, for the given donors or for
     * everyone when the list is empty.
     *
     * The handler undoes the redaction stamp before throwing, which is also
     * what production gets for free: redact() runs the handlers inside
     * DB::transaction, so the throw rolls the stamp back and the donor stays in
     * the sweep's set. Undoing it in the handler keeps this test about the
     * sweep set rather than about transaction mechanics.
     *
     * @param list<int> $donorIds
     */
    private function handlerThatThrowsFor(array $donorIds = []): callable
    {
        $handler = new class ($donorIds) implements ErasureHandler {
            /** @param list<int> $donorIds */
            public function __construct(private array $donorIds)
            {
            }

            public function key(): string
            {
                return 'test.retention_poison';
            }

            public function erase(ErasureRequest $request): void
            {
                if ($this->donorIds !== [] && ! in_array($request->donorId, $this->donorIds, true)) {
                    return;
                }

                $prefix = DB::getPrefix();
                DB::raw(
                    "UPDATE {$prefix}dono_donors SET redacted_at = NULL WHERE id = %d",
                    [$request->donorId]
                );

                throw new RuntimeException('this add-on cannot complete its part');
            }
        };

        $add = static function (array $h) use ($handler): array {
            $h[] = $handler;
            return $h;
        };
        add_filter('dono.donor.erasure_handlers', $add);

        return $add;
    }

    private function sweepWith(callable $handler): void
    {
        try {
            $this->retention()->run();
        } finally {
            remove_filter('dono.donor.erasure_handlers', $handler);
        }
    }

    private function redactedAt(int $donorId): ?string
    {
        return Donor::query()->where('id', $donorId)->get()->redacted_at;
    }

    /** @return list<object> */
    private function retentionErrors(): array
    {
        return Event::query()
            ->where('type', ErrorLog::PREFIX . 'donor.retention')
            ->orderBy('id', 'DESC')
            ->getAll();
    }

    protected function tearDown(): void
    {
        delete_option('dono_privacy');
        delete_option(DonorRetention::STARTS_AT_OPTION);
        parent::tearDown();
    }

    public function test_a_donor_who_cannot_be_erased_does_not_hold_up_the_ones_behind_them(): void
    {
        $this->erasureIsRunning();

        $poison = $this->ancientDonor('Poison');
        $behind = $this->ancientDonor('Behind');

        $this->sweepWith($this->handlerThatThrowsFor([(int) $poison->id]));

        $this->assertNull($this->redactedAt((int) $poison->id), 'the failed erasure did not stick');
        $this->assertNotNull(
            $this->redactedAt((int) $behind->id),
            'and the donor queued behind it is still erased'
        );
    }

    /**
     * Erasure is a compliance action an org believes has happened. One that did
     * not has to be readable on the Tools screen, which is what ErrorLog feeds.
     */
    public function test_the_donor_the_sweep_could_not_erase_is_reported(): void
    {
        $this->erasureIsRunning();

        $poison = $this->ancientDonor('Reported');
        $this->ancientDonor('Behind');

        $this->sweepWith($this->handlerThatThrowsFor([(int) $poison->id]));

        $errors = $this->retentionErrors();

        $this->assertNotSame([], $errors, 'a failed erasure is not swallowed');
        $this->assertSame((int) $poison->id, (int) $errors[0]->donor_id, 'and it names the donor');
        $this->assertStringContainsString(
            'cannot complete its part',
            (string) ($errors[0]->payload['message'] ?? ''),
            'with what the handler said'
        );
    }

    /**
     * Stepping past is for one pass only: whatever broke may be fixed by
     * tomorrow, and a donor skipped forever is the same failure in slower
     * motion.
     */
    public function test_the_next_pass_starts_at_the_top_and_tries_again(): void
    {
        $this->erasureIsRunning();

        $poison = $this->ancientDonor('Retried');
        $this->ancientDonor('Behind');

        $this->sweepWith($this->handlerThatThrowsFor([(int) $poison->id]));
        $this->retention()->run();

        $this->assertNotNull(
            $this->redactedAt((int) $poison->id),
            'the donor the sweep stepped past is reached once the add-on works again'
        );
    }

    /**
     * A whole batch of failures is the case that costs money rather than data:
     * every donor still matches, so the pass looks full, re-enqueues itself and
     * spins on the same rows for as long as Action Scheduler will run it.
     */
    public function test_a_full_batch_of_failures_does_not_make_the_pass_spin_on_the_same_donors(): void
    {
        $this->erasureIsRunning();

        $batch = (int) (new ReflectionClassConstant(DonorRetention::class, 'BATCH'))->getValue();
        for ($i = 0; $i < $batch; $i++) {
            $this->ancientDonor();
        }

        $add = $this->handlerThatThrowsFor();

        try {
            $this->retention()->run();
            $afterFirstPass = count($this->retentionErrors());

            // What the re-enqueued continuation runs, with the add-on still broken.
            $this->retention()->run();
            $afterContinuation = count($this->retentionErrors());
        } finally {
            remove_filter('dono.donor.erasure_handlers', $add);
        }

        $this->assertSame($batch, $afterFirstPass, 'every donor in the batch was attempted once');
        $this->assertSame(
            $afterFirstPass,
            $afterContinuation,
            'and the continuation moves on rather than re-reading the same window'
        );
    }
}
