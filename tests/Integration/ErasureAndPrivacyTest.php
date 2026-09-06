<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Analytics\Event;
use FundKit\Donations\Donation;
use FundKit\Donors\DonorRetention;
use FundKit\Donors\DonorService;
use FundKit\Foundation\Plugin;
use FundKit\Recurring\RecurringPlan;
use WP_REST_Request;

/**
 * Erasing one donor must not reach another donor's records, and the record of
 * who erased them has to name whoever actually did it.
 */
final class ErasureAndPrivacyTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    private function donors(): DonorService
    {
        return Plugin::instance()->container->get(DonorService::class);
    }

    private function paidDonation(int $donorId, string $reference): Donation
    {
        $now = gmdate('Y-m-d H:i:s');

        $d = Donation::make();
        $d->donor_id          = $donorId;
        $d->reference         = $reference;
        $d->amount_cents      = 2500;
        $d->base_amount_cents = 2500;
        $d->fx_rate           = '1';
        $d->currency          = 'USD';
        $d->status            = 'paid';
        $d->gateway           = 'offline';
        $d->is_test           = false;
        $d->paid_at           = $now;
        $d->created_at        = $now;
        $d->updated_at        = $now;
        $d->save();

        return $d;
    }

    private function loggedEvent(string $type, string $payload): Event
    {
        $e = Event::make();
        $e->type        = $type;
        $e->payload     = ['note' => $payload];
        $e->occurred_at = gmdate('Y-m-d H:i:s');
        $e->save();

        return $e;
    }

    /**
     * A reference is unique but not substring-unique: DON-1 is inside DON-10,
     * and the scan searches loose text.
     */
    public function test_erasing_one_donor_leaves_another_donors_log_rows_alone(): void
    {
        $mine   = $this->donors()->findOrCreate('short-ref@example.test');
        $theirs = $this->donors()->findOrCreate('long-ref@example.test');

        $this->paidDonation((int) $mine->id, 'DON-1');
        $this->paidDonation((int) $theirs->id, 'DON-10');

        $ours   = $this->loggedEvent('gateway.webhook', 'about DON-1');
        $others = $this->loggedEvent('gateway.webhook', 'about DON-10');

        $this->donors()->redact($mine);

        $this->assertNotNull(
            Event::query()->find('id', (int) $others->id)->payload,
            "another donor's log row is not the erased donor's data"
        );
        // The donation row itself is still erased, by id.
        $this->assertNotNull(Event::query()->find('id', (int) $ours->id));
    }

    public function test_a_reference_nothing_extends_is_still_scrubbed(): void
    {
        $donor = $this->donors()->findOrCreate('unique-ref@example.test');
        $this->paidDonation((int) $donor->id, 'DON-777');

        $row = $this->loggedEvent('gateway.webhook', 'about DON-777');

        $this->donors()->redact($donor);

        $this->assertNull(Event::query()->find('id', (int) $row->id)->payload);
    }

    public function test_the_nightly_sweep_does_not_sign_a_staff_members_name(): void
    {
        $donor = $this->donors()->findOrCreate('swept@example.test');

        $this->donors()->redact($donor, 'retention');

        $audit = Event::query()->where('type', 'donor.redacted')->orderBy('id', 'desc')->get();
        $this->assertSame('retention', $audit['payload']['by'] ?? null);
        $this->assertSame('', $audit['payload']['actor_name'] ?? null);
    }

    /**
     * Action Scheduler runs the sweep on whatever request happens to trip it,
     * which is usually an admin's own page load, so wp_doing_cron() is false
     * and the name of whoever was browsing ended up on the audit row.
     */
    public function test_the_sweep_itself_signs_itself_even_on_an_admin_request(): void
    {
        Plugin::instance()->container->get(\FundKit\Settings\SettingsService::class)
            ->update('privacy', ['erase_inactive_donors' => true, 'donor_retention_years' => 1]);
        update_option(DonorRetention::STARTS_AT_OPTION, time() - 86400, false);

        $long = gmdate('Y-m-d H:i:s', time() - (20 * 365 * 86400));
        $d = \FundKit\Donors\Donor::make();
        $d->email_hash       = hash('sha256', uniqid('sweep', true));
        $d->email_encrypted  = 'x';
        $d->first_name       = 'Ancient';
        $d->last_name        = 'Probe';
        $d->last_donation_at = $long;
        $d->created_at       = $long;
        $d->updated_at       = $long;
        $d->save();

        $this->assertFalse(wp_doing_cron(), 'the sweep is running on a normal request');
        Plugin::instance()->container->get(DonorRetention::class)->run();

        $audit = Event::query()->where('type', 'donor.redacted')->orderBy('id', 'desc')->get();
        $this->assertSame('retention', $audit['payload']['by'] ?? null);
        $this->assertSame('', $audit['payload']['actor_name'] ?? null);
    }

    public function test_an_admin_erasure_still_names_the_admin(): void
    {
        $donor = $this->donors()->findOrCreate('by-hand@example.test');

        $this->donors()->redact($donor);

        $audit = Event::query()->where('type', 'donor.redacted')->orderBy('id', 'desc')->get();
        $this->assertSame('admin', $audit['payload']['by'] ?? null);
        $this->assertNotSame('', $audit['payload']['actor_name'] ?? '');
    }

    /**
     * The cancellations happen before the erasure's transaction, so a gateway
     * that refuses half way leaves the earlier plans genuinely stopped and the
     * request has to say so rather than dying as a fatal.
     */
    public function test_a_gateway_that_refuses_answers_instead_of_fatalling(): void
    {
        $donor = $this->donors()->findOrCreate('stuck-plans@example.test');

        $now = gmdate('Y-m-d H:i:s');
        $p = RecurringPlan::make();
        $p->donor_id                = (int) $donor->id;
        $p->gateway                 = 'no-such-gateway';
        $p->gateway_subscription_id = 'sub_' . uniqid();
        $p->amount_cents            = 2500;
        $p->currency                = 'USD';
        $p->interval_unit           = 'month';
        $p->interval_count          = 1;
        $p->status                  = 'active';
        $p->is_test                 = false;
        $p->started_at              = $now;
        $p->created_at              = $now;
        $p->updated_at              = $now;
        $p->save();

        $req = new WP_REST_Request('POST', '/fundkit/v1/admin/donors/' . (int) $donor->id . '/redact');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode(['confirmation' => 'stuck-plans@example.test']));

        $res = rest_do_request($req);

        $this->assertSame(502, $res->get_status(), 'a refusal is an answer, not a fatal');
        $this->assertSame('fundkit_redact_failed', $res->as_error()->get_error_code());
        $this->assertNull(
            \FundKit\Donors\Donor::query()->find('id', (int) $donor->id)->redacted_at,
            'nothing was erased'
        );
    }
}
