<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Donations\Donation;
use Gratora\Donors\Donor;
use Gratora\Donors\DonorService;
use Gratora\Donors\Erasure\ErasureHandler;
use Gratora\Donors\Erasure\ErasureRequest;
use Gratora\Foundation\Plugin;
use InvalidArgumentException;
use RuntimeException;
use WP_REST_Request;

/**
 * Redaction runs every handler registered on gratora.donor.erasure_handlers,
 * which is how an add-on clears the copy it holds. Deletion destroys strictly
 * more than redaction does, so it cannot erase less.
 *
 * The copies are real: Connect keeps the decrypted address in an event
 * payload, the outbound body in a delivery snapshot and a contact id in the
 * org's CRM; the assistant keeps whatever a transcript quoted. Deleting the
 * donor row takes away the only thing that named them, and leaves all of it.
 */
final class DeleteRunsErasureHandlersTest extends IntegrationTestCase
{
    private function service(): DonorService
    {
        return Plugin::instance()->container->get(DonorService::class);
    }

    private function donor(): Donor
    {
        return $this->service()->findOrCreate(
            'erase-' . uniqid() . '@example.test',
            ['first_name' => 'Cas', 'last_name' => 'Reader']
        );
    }

    private function deadDonation(int $donorId): Donation
    {
        $old = gmdate('Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS);

        $d = Donation::make();
        $d->reference         = 'ERH-' . uniqid();
        $d->donor_id          = $donorId;
        $d->amount_cents      = 2500;
        $d->base_amount_cents = 2500;
        $d->currency          = 'EUR';
        $d->base_currency     = 'EUR';
        $d->status            = 'failed';
        $d->gateway           = 'stripe';
        $d->frequency         = 'one_time';
        $d->kind              = 'donation';
        $d->is_test           = false;
        $d->created_at        = $old;
        $d->updated_at        = $old;
        $d->save();

        return $d;
    }

    /** @param callable(ErasureRequest):void $onErase */
    private function register(callable $onErase): callable
    {
        $handler = new class ($onErase) implements ErasureHandler {
            /** @param callable(ErasureRequest):void $onErase */
            public function __construct(private $onErase)
            {
            }

            public function key(): string
            {
                return 'test.spy';
            }

            public function erase(ErasureRequest $request): void
            {
                ($this->onErase)($request);
            }
        };

        $filter = static function (array $handlers) use ($handler): array {
            $handlers[] = $handler;

            return $handlers;
        };
        add_filter('gratora.donor.erasure_handlers', $filter);

        return static function () use ($filter): void {
            remove_filter('gratora.donor.erasure_handlers', $filter);
        };
    }

    public function test_deleting_a_donor_runs_the_add_on_handlers(): void
    {
        $seen = [];
        $off  = $this->register( function (ErasureRequest $r) use (&$seen): void {
            $seen[] = $r;
        } );

        try {
            $donor = $this->donor();
            $id    = (int) $donor->id;
            $did   = (int) $this->deadDonation($id)->id;

            $this->service()->delete($donor);

            $this->assertCount(1, $seen, 'an add-on holding this donor was never told they were deleted');
            $this->assertSame($id, $seen[0]->donorId);
            $this->assertSame([$did], $seen[0]->donationIds, 'and it is told which donations went with them');
            $this->assertNotSame([], $seen[0]->needles, 'with something to search its own tables for');
        } finally {
            $off();
        }
    }

    public function test_a_handler_runs_while_the_rows_it_needs_are_still_there(): void
    {
        $found = null;
        $off   = $this->register( function (ErasureRequest $r) use (&$found): void {
            $found = [
                'donor'     => (int) Donor::query()->where('id', $r->donorId)->count(),
                'donations' => $r->donationIds === []
                    ? 0
                    : (int) Donation::query()->whereIn('id', $r->donationIds)->count(),
            ];
        } );

        try {
            $donor = $this->donor();
            $this->deadDonation((int) $donor->id);

            $this->service()->delete($donor);

            $this->assertSame(
                [ 'donor' => 1, 'donations' => 1 ],
                $found,
                'a handler that cannot resolve the donor cannot clear what it copied'
            );
        } finally {
            $off();
        }
    }

    public function test_a_handler_that_cannot_finish_stops_the_delete(): void
    {
        $off = $this->register( static function (): void {
            throw new RuntimeException('the add-on could not reach its own store');
        } );

        try {
            $donor = $this->donor();
            $id    = (int) $donor->id;
            $did   = (int) $this->deadDonation($id)->id;

            try {
                $this->service()->delete($donor);
                $this->fail('the delete reported success while an add-on had not finished');
            } catch ( RuntimeException $e ) {
                $this->assertStringContainsString('could not reach', $e->getMessage());
            }

            $this->assertSame(1, (int) Donor::query()->where('id', $id)->count(), 'the donor is still here');
            $this->assertSame(1, (int) Donation::query()->where('id', $did)->count(), 'and so is their donation');
        } finally {
            $off();
        }
    }

    public function test_redaction_and_deletion_reach_the_same_handlers(): void
    {
        $calls = [];
        $off   = $this->register( static function (ErasureRequest $r) use (&$calls): void {
            $calls[] = $r->donorId;
        } );

        try {
            $redacted = $this->donor();
            $this->service()->redact($redacted);

            $deleted = $this->donor();
            $this->service()->delete($deleted);

            $this->assertSame(
                [ (int) $redacted->id, (int) $deleted->id ],
                $calls,
                'a delete that erases less than a redaction is not a delete'
            );
        } finally {
            $off();
        }
    }

    /**
     * An add-on handler that cannot finish is a refusal the operator has to be
     * able to read. Unwrapped it escaped the route as a critical-error page,
     * which the screen cannot show and which says nothing about why.
     */
    public function test_a_handler_that_cannot_finish_answers_the_route_instead_of_fataling(): void
    {
        $off = $this->register(static function (): void {
            throw new RuntimeException('the add-on could not reach its own store');
        });

        try {
            $id = (int) $this->donor()->id;
            $this->deadDonation($id);

            $request = new WP_REST_Request('DELETE', '/gratora/v1/admin/donors/' . $id);
            $request->set_param('confirmation', 'DELETE');
            $res = rest_do_request($request);

            $this->assertSame(500, $res->get_status());
            $this->assertSame(1, Donor::query()->where('id', $id)->count());
        } finally {
            $off();
        }
    }

    public function test_a_refusing_handler_is_reported_as_a_refusal(): void
    {
        $off = $this->register(static function (): void {
            throw new InvalidArgumentException('this donor is on a legal hold');
        });

        try {
            $id = (int) $this->donor()->id;
            $this->deadDonation($id);

            $request = new WP_REST_Request('DELETE', '/gratora/v1/admin/donors/' . $id);
            $request->set_param('confirmation', 'DELETE');
            $res = rest_do_request($request);

            $this->assertSame(409, $res->get_status());
            $this->assertSame('this donor is on a legal hold', (string) $res->as_error()->get_error_message());
        } finally {
            $off();
        }
    }
}
