<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Analytics\Event;
use FundKit\Analytics\EventRetention;
use FundKit\Async\AsyncDispatcher;
use FundKit\Donations\Donation;
use FundKit\Donors\Donor;
use FundKit\Donors\DonorService;
use FundKit\Foundation\Plugin;
use WP_REST_Request;

/**
 * The destructive donor operations leave a record, and the record has to
 * survive the erasure of its own subject or nobody can answer who removed
 * this donor.
 */
final class DonorAuditTrailTest extends IntegrationTestCase
{
    private function service(): DonorService
    {
        return Plugin::instance()->container->get(DonorService::class);
    }

    private function donor(): Donor
    {
        return $this->service()->findOrCreate('audit-' . uniqid() . '@example.test', ['first_name' => 'Aud']);
    }

    private function deadDonation(int $donorId): int
    {
        $old = gmdate('Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS);

        $d = Donation::make();
        $d->reference         = 'AUD-' . uniqid();
        $d->donor_id          = $donorId;
        $d->amount_cents      = 500;
        $d->base_amount_cents = 500;
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

        return (int) $d->id;
    }

    private function auditRows(): array
    {
        return Event::query()->whereLike('type', 'donor.%')->getAll();
    }

    public function test_a_delete_is_recorded_and_outlives_the_donor(): void
    {
        $donor = $this->donor();
        $this->deadDonation((int) $donor->id);

        $this->service()->delete($donor);

        $rows = array_values(array_filter($this->auditRows(), static fn ($e) => $e->type === 'donor.deleted'));
        $this->assertCount(1, $rows);

        $payload = (array) $rows[0]->payload;
        $this->assertSame(1, (int) $payload['donations_deleted']);
        $this->assertArrayHasKey('by', $payload);
        $this->assertNull(Donor::query()->find('id', (int) $donor->id));
    }

    public function test_a_rolled_back_delete_claims_nothing(): void
    {
        $donor = $this->donor();
        $this->deadDonation((int) $donor->id);

        add_action('fundkit.test_data.purge_donations', static function (): void {
            throw new \RuntimeException('an add-on refused');
        });

        try {
            $this->service()->delete($donor);
        } catch (\Throwable $e) {
            // expected
        }

        $this->assertSame([], $this->auditRows(), 'a delete that did not happen must not claim it did');
        $this->assertNotNull(Donor::query()->find('id', (int) $donor->id));
    }

    public function test_redaction_does_not_erase_its_own_record(): void
    {
        $donor = $this->donor();
        $this->service()->redact($donor);

        $rows = array_values(array_filter($this->auditRows(), static fn ($e) => $e->type === 'donor.redacted'));
        $this->assertCount(1, $rows, 'the redaction recorded itself');
        $this->assertNotNull($rows[0]->payload, 'and did not then clear its own payload');
    }

    public function test_a_later_delete_does_not_blank_the_earlier_redaction_record(): void
    {
        $donor = $this->donor();
        $this->service()->redact($donor);

        $before = Event::query()->whereLike('type', 'donor.redacted')->getAll();
        $this->assertCount(1, $before);
        $this->assertNotNull($before[0]->payload);

        $this->service()->delete(Donor::query()->find('id', (int) $donor->id));

        $after = Event::query()->whereLike('type', 'donor.redacted')->getAll();
        $this->assertCount(1, $after, 'the redaction record survives the delete');
        $this->assertNotNull($after[0]->payload, 'and keeps saying who did it');
    }

    public function test_the_retention_sweep_leaves_the_audit_alone(): void
    {
        $donor = $this->donor();
        $this->service()->redact($donor);

        $old = gmdate('Y-m-d H:i:s', time() - 4000 * DAY_IN_SECONDS);
        Event::query()->whereLike('type', 'donor.%')->update(['occurred_at' => $old]);

        $noise = Event::make();
        $noise->type        = 'donation.completed';
        $noise->occurred_at = $old;
        $noise->save();

        (new EventRetention(Plugin::instance()->container->get(AsyncDispatcher::class)))->run();

        $this->assertNotSame([], $this->auditRows(), 'the audit outlives the sweep');
        $this->assertNull(Event::query()->find('id', (int) $noise->id), 'and the sweep still works');
    }

    public function test_clear_log_cannot_delete_the_audit(): void
    {
        $donor = $this->donor();
        $this->service()->redact($donor);

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $noise = Event::make();
        $noise->type        = 'error.boom';
        $noise->occurred_at = gmdate('Y-m-d H:i:s');
        $noise->save();

        $req = new WP_REST_Request('DELETE', '/fundkit/v1/admin/tools/log');
        $req->set_param('source', 'donor.');
        $res = rest_do_request($req);

        $this->assertSame(200, $res->get_status(), 'the route exists, or this asserts nothing');
        $this->assertNotSame([], $this->auditRows(), 'Clear log must not reach the audit');
        // Nor may asking for the audit clear what the admin did not ask about.
        $this->assertNotNull(Event::query()->find('id', (int) $noise->id), 'a refused source deletes nothing');
        $this->assertSame(0, (int) $res->get_data()['deleted']);
    }
}
