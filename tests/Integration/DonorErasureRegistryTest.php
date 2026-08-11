<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Analytics\Event;
use Dono\Donations\Donation;
use Dono\Donors\Donor;
use Dono\Donors\DonorService;
use Dono\Donors\Erasure\ErasureHandler;
use Dono\Donors\Erasure\ErasureRequest;
use Dono\Foundation\Plugin;
use RuntimeException;

/**
 * The two tables the QA sweep found that erasure could not reach at all.
 *
 * Neither has a donor_id, so the old `dono.donor.redacted` action was
 * structurally unable to help: it fired after email_encrypted was already ''
 * and the names were null, so a listener had nothing left to search for. The
 * registry captures the identifiers first and hands them over.
 */
final class DonorErasureRegistryTest extends IntegrationTestCase
{
    private const NEEDLE = 'registry-needle@example.test';

    private int $donorId;
    private int $donationId;

    protected function setUp(): void
    {
        parent::setUp();
        $now = gmdate('Y-m-d H:i:s');

        $svc   = Plugin::instance()->container->get(DonorService::class);
        $donor = $svc->findOrCreate(self::NEEDLE, ['first_name' => 'Wilhelmina', 'last_name' => 'Bletchley']);
        $this->donorId = (int) $donor->id;

        $d = Donation::make();
        $d->reference         = 'DONO-REG-1';
        $d->donor_id          = $this->donorId;
        $d->amount_cents      = 5000;
        $d->base_amount_cents = 5000;
        $d->currency          = 'EUR';
        $d->base_currency     = 'EUR';
        $d->status            = 'paid';
        $d->gateway           = 'stripe';
        $d->gateway_intent_id = 'pi_registry_needle';
        $d->frequency         = 'one_time';
        $d->kind              = 'donation';
        $d->is_test           = false;
        $d->paid_at           = $now;
        $d->created_at        = $now;
        $d->updated_at        = $now;
        $d->save();
        $this->donationId = (int) $d->id;
    }

    private function erase(): void
    {
        $svc = Plugin::instance()->container->get(DonorService::class);
        $svc->redact(Donor::query()->find('id', $this->donorId));
    }

    private function event(array $attrs): int
    {
        $e = Event::make();
        $e->type        = $attrs['type'] ?? 'donation.completed';
        $e->donor_id    = $attrs['donor_id'] ?? null;
        $e->donation_id = $attrs['donation_id'] ?? null;
        $e->payload     = $attrs['payload'] ?? null;
        $e->session_hash    = $attrs['session_hash'] ?? null;
        $e->ip_hash         = $attrs['ip_hash'] ?? null;
        $e->user_agent_hash = $attrs['user_agent_hash'] ?? null;
        $e->occurred_at = gmdate('Y-m-d H:i:s');
        $e->save();
        return (int) $e->id;
    }


    public function test_the_donors_analytics_payload_and_hashes_are_cleared(): void
    {
        $id = $this->event([
            'donor_id'        => $this->donorId,
            'payload'         => ['email' => self::NEEDLE, 'gateway' => 'stripe'],
            'session_hash'    => str_repeat('a', 64),
            'ip_hash'         => str_repeat('b', 64),
            'user_agent_hash' => str_repeat('c', 64),
        ]);

        $this->erase();

        $e = Event::query()->find('id', $id);
        $this->assertNotNull($e, 'the row survives so campaign totals do not move');
        $this->assertNull($e->payload);
        $this->assertNull($e->session_hash, 'a session hash re-links the row to a person');
        $this->assertNull($e->ip_hash);
        $this->assertNull($e->user_agent_hash);
    }

    public function test_an_event_known_only_by_its_donation_is_reached(): void
    {
        $id = $this->event(['donation_id' => $this->donationId, 'payload' => ['email' => self::NEEDLE]]);

        $this->erase();

        $this->assertNull(Event::query()->find('id', $id)->payload);
    }

    /** The case donor_id and donation_id both miss: an abandoned checkout. */
    public function test_an_orphan_event_is_found_by_the_captured_identifiers(): void
    {
        $id = $this->event(['type' => 'checkout.abandoned', 'payload' => ['email' => self::NEEDLE]]);

        $this->erase();

        $this->assertNull(Event::query()->find('id', $id)->payload);
    }

    public function test_someone_elses_event_is_left_alone(): void
    {
        $id = $this->event(['type' => 'checkout.abandoned', 'payload' => ['email' => 'other@example.test']]);

        $this->erase();

        $this->assertNotNull(Event::query()->find('id', $id)->payload, 'erasure is not a table wipe');
    }

    /**
     * The strongest form of erasure is never holding the data. A delivery is
     * recorded as what arrived and what Dono did about it, and the gateway's own
     * copy of the payer is not part of that, so there is nothing here to erase.
     */
    public function test_a_delivery_records_nothing_about_the_payer(): void
    {
        $req = new \WP_REST_Request('POST', '/dono/v1/webhooks/offline');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode([
            'id'   => 'evt_registry_1',
            'data' => ['object' => [
                'receipt_email'   => self::NEEDLE,
                'billing_details' => ['name' => 'Wilhelmina Bletchley'],
            ]],
        ]));
        rest_do_request($req);

        $rows = Event::query()->whereLike('type', 'webhook.%')->getAll();
        $this->assertNotEmpty($rows, 'the delivery is recorded');

        $stored = (string) wp_json_encode(array_map(
            static fn ($e): array => ['type' => $e->type, 'payload' => $e->payload],
            $rows
        ));

        $this->assertStringNotContainsString(self::NEEDLE, $stored);
        $this->assertStringNotContainsString('Wilhelmina', $stored);
        $this->assertStringNotContainsString('billing_details', $stored);
    }

    /**
     * A donor whose own data contains a LIKE wildcard must not widen the search
     * to every row in the table. Erasing one person by destroying everyone
     * else's records is its own breach.
     */
    public function test_a_wildcard_in_the_donors_data_does_not_match_everything(): void
    {
        $svc     = Plugin::instance()->container->get(DonorService::class);
        $wildcard = $svc->findOrCreate('percent@example.test', ['first_name' => '%', 'last_name' => '_ %_%']);

        $keep = $this->event(['type' => 'checkout.abandoned', 'payload' => ['email' => 'bystander@example.test']]);

        $svc->redact(Donor::query()->find('id', (int) $wildcard->id));

        $this->assertNotNull(
            Event::query()->find('id', $keep)->payload,
            'a bystander row survives a donor whose name is all wildcards'
        );
    }

    /**
     * Erasure is reported to the admin as done and cannot be repeated, so an
     * add-on that cannot finish its part must not be swallowed: the caller has
     * to hear about it.
     *
     * Only the propagation is asserted. redact() wraps the handlers in
     * DB::transaction() so the failure also rolls back, but this harness pins
     * Queryable's transaction depth to 1 (see IntegrationTestCase) to keep
     * product transactions inside WP_UnitTestCase's wrapper, which makes a real
     * rollback unobservable from here.
     */
    public function test_a_failing_handler_is_not_swallowed(): void
    {
        $exploder = new class implements ErasureHandler {
            public function key(): string
            {
                return 'test.explodes';
            }

            public function erase(ErasureRequest $request): void
            {
                throw new RuntimeException('this add-on cannot complete its part');
            }
        };
        $add = static function (array $h) use ($exploder): array {
            $h[] = $exploder;
            return $h;
        };
        add_filter('dono.donor.erasure_handlers', $add);

        try {
            $this->erase();
            $this->fail('the failure should surface, not be swallowed');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('cannot complete', $e->getMessage());
        } finally {
            remove_filter('dono.donor.erasure_handlers', $add);
        }
    }
}
