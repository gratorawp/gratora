<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Analytics\DonationAudit;
use Gratora\Analytics\ErrorLog;
use Gratora\Analytics\Event;
use Gratora\Analytics\EventRetention;
use Gratora\Async\AsyncDispatcher;
use Gratora\Donations\Donation;
use Gratora\Donors\Donor;
use Gratora\Donors\DonorService;
use Gratora\Foundation\Maintenance\TestDataPurger;
use Gratora\Foundation\Plugin;
use WP_REST_Request;

/**
 * What an admin did to a donation has to outlive the donation, the donor, and
 * every sweep that clears the events table.
 *
 * A spam donation is removed precisely because nobody wants it, which makes the
 * record of the removal the only thing left saying it was ever there. Every
 * sweep below deletes events by a key the audit row also carries, so each one
 * is a way for that record to disappear without anyone choosing to drop it.
 */
final class DonationAuditTrailTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    private function seed(string $type, array $cols = [], string $occurredAt = ''): int
    {
        $e              = Event::make();
        $e->type        = $type;
        $e->payload     = ['by' => 'An Admin'];
        $e->occurred_at = $occurredAt !== '' ? $occurredAt : gmdate('Y-m-d H:i:s');

        foreach ($cols as $col => $value) {
            $e->{$col} = $value;
        }

        $e->save();

        return (int) $e->id;
    }

    private function exists(int $id): bool
    {
        return Event::query()->where('id', $id)->get() !== null;
    }

    /** @return array<string,mixed> */
    private function fetch(array $params = []): array
    {
        $req = new WP_REST_Request('GET', '/gratora/v1/admin/tools/log');
        foreach ($params as $k => $v) {
            $req->set_param($k, $v);
        }
        $res = rest_do_request($req);

        $this->assertSame(200, $res->get_status(), (string) wp_json_encode($res->get_data()));

        return (array) $res->get_data();
    }

    public function test_every_audit_type_survives_the_retention_sweep(): void
    {
        update_option('gratora_privacy', ['event_retention_days' => 730]);
        $ancient = gmdate('Y-m-d H:i:s', time() - 4000 * DAY_IN_SECONDS);

        $kept = [];
        foreach (DonationAudit::TYPES as $type) {
            $kept[$type] = $this->seed($type, [], $ancient);
        }
        $swept = $this->seed('donation.completed', [], $ancient);

        (new EventRetention(Plugin::instance()->container->get(AsyncDispatcher::class)))->run();

        foreach ($kept as $type => $id) {
            $this->assertTrue($this->exists($id), "{$type} outlives the retention window");
        }
        $this->assertFalse($this->exists($swept), 'and the sweep still prunes the history it is there to prune');
    }

    public function test_an_audit_row_reads_as_an_audit_rather_than_an_error(): void
    {
        $this->seed('donation.trashed', ['donation_id' => 4242]);

        $items = (array) $this->fetch()['items'];
        $this->assertCount(1, $items);

        // The error branch would stamp it kind 'error' and then cut ErrorLog's
        // prefix off a type that never carried it, leaving a nameless source.
        $this->assertSame('audit', $items[0]['kind']);
        $this->assertSame('donation.trashed', $items[0]['source']);
        $this->assertSame(4242, $items[0]['context']['donation_id']);
    }

    public function test_the_history_of_a_donation_is_still_not_listed(): void
    {
        $this->seed('donation.trashed', ['donation_id' => 11]);
        $this->seed('donation.completed', ['donation_id' => 11]);

        $sources = array_column((array) $this->fetch()['items'], 'source');

        // Matched by exact type, never by the donation. prefix: the analytics
        // history under that prefix carries donor detail this screen must not
        // serve, and one prefix match would hand over all of it.
        $this->assertSame(['donation.trashed'], $sources);
    }

    public function test_an_audit_type_is_offered_as_a_filter_and_narrows_to_itself(): void
    {
        $this->seed('donation.trashed', ['donation_id' => 11]);
        $this->seed('donation.deleted', ['donation_id' => 12]);
        ErrorLog::record('gateway.intent', 'Something broke.');

        $this->assertContains('donation.trashed', (array) $this->fetch()['sources']);

        $narrowed = array_column((array) $this->fetch(['source' => 'donation.trashed'])['items'], 'source');
        $this->assertSame(['donation.trashed'], $narrowed);
    }

    public function test_clearing_the_log_cannot_reach_an_audit_row(): void
    {
        $audit = $this->seed('donation.trashed', ['donation_id' => 11]);
        ErrorLog::record('gateway.intent', 'Something broke.');

        $res = rest_do_request(new WP_REST_Request('DELETE', '/gratora/v1/admin/tools/log'));
        $this->assertSame(200, $res->get_status(), (string) wp_json_encode($res->get_data()));

        $this->assertTrue($this->exists($audit), 'Clear log is for diagnostics, not for the record of what was removed');
        $this->assertSame(0, (int) Event::query()->whereLike('type', ErrorLog::PREFIX . '%')->count());
    }

    public function test_clearing_one_audit_source_deletes_nothing(): void
    {
        $audit = $this->seed('donation.trashed', ['donation_id' => 11]);

        // The screen can filter to this source, and the same normaliser feeds
        // the delete. Being readable must not make it clearable one type at a
        // time.
        $req = new WP_REST_Request('DELETE', '/gratora/v1/admin/tools/log');
        $req->set_param('source', 'donation.trashed');
        $res = rest_do_request($req);

        $this->assertSame(409, $res->get_status(), (string) wp_json_encode($res->get_data()));
        $this->assertSame('gratora_log_not_clearable', ((array) $res->get_data())['code'] ?? '');
        $this->assertTrue($this->exists($audit));
    }

    public function test_the_record_outlives_the_donor_it_points_at(): void
    {
        $donor      = $this->donor();
        $donationId = $this->deadDonation((int) $donor->id);

        // Both keys, because the donor delete sweeps by each of them in turn
        // and an audit row is expected to carry both.
        $audit   = $this->seed('donation.trashed', ['donation_id' => $donationId, 'donor_id' => (int) $donor->id]);
        $history = $this->seed('donation.completed', ['donation_id' => $donationId, 'donor_id' => (int) $donor->id]);

        Plugin::instance()->container->get(DonorService::class)->delete($donor);

        $this->assertNull(Donor::query()->find('id', (int) $donor->id), 'the donor is gone');
        $this->assertTrue($this->exists($audit), 'and the record of what was done to their donation is not');
        $this->assertFalse($this->exists($history), 'while their donation history is erased with them');
    }

    public function test_the_record_outlives_a_test_data_purge(): void
    {
        $donor      = $this->donor();
        $donationId = $this->deadDonation((int) $donor->id, true);

        $audit   = $this->seed('donation.trashed', ['donation_id' => $donationId, 'donor_id' => (int) $donor->id]);
        $history = $this->seed('donation.completed', ['donation_id' => $donationId, 'donor_id' => (int) $donor->id]);

        (new TestDataPurger(Plugin::instance()->container->get(DonorService::class)))->purge();

        $this->assertNull(Donation::query()->find('id', $donationId), 'the test donation is gone');
        $this->assertTrue($this->exists($audit), 'and what an admin did to it is still on record');
        $this->assertFalse($this->exists($history), 'while its history goes with it');
    }

    private function donor(): Donor
    {
        return Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate('audit-' . uniqid() . '@example.test', ['first_name' => 'Aud']);
    }

    private function deadDonation(int $donorId, bool $isTest = false): int
    {
        $old = gmdate('Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS);

        $d                    = Donation::make();
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
        $d->is_test           = $isTest;
        $d->created_at        = $old;
        $d->updated_at        = $old;
        $d->save();

        return (int) $d->id;
    }
}
