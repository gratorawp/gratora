<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Campaigns\Campaign;
use FundKit\Campaigns\CampaignService;
use FundKit\Donations\Donation;
use FundKit\Donors\Consent;
use FundKit\Donors\Donor;
use FundKit\Donors\DonorService;
use FundKit\Foundation\Plugin;
use InvalidArgumentException;
use WP_REST_Request;

final class ConfirmedMediumFixesTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    private function campaigns(): CampaignService
    {
        return Plugin::instance()->container->get(CampaignService::class);
    }


    public function test_a_campaign_cannot_be_created_ending_before_it_starts(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->campaigns()->create([
            'title'     => 'Backwards',
            'starts_at' => '2026-06-01',
            'ends_at'   => '2026-05-01',
        ]);
    }

    public function test_a_campaign_cannot_be_created_with_a_date_nothing_can_read(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->campaigns()->create(['title' => 'Nonsense', 'ends_at' => 'next harvest']);
    }

    public function test_an_update_refuses_the_same_unreadable_date(): void
    {
        $campaign = $this->campaigns()->create(['title' => 'Editable']);

        $this->expectException(InvalidArgumentException::class);
        $this->campaigns()->update($campaign, ['ends_at' => 'whenever']);
    }

    public function test_a_created_window_is_stored_as_a_real_datetime(): void
    {
        $campaign = $this->campaigns()->create([
            'title'     => 'Windowed',
            'starts_at' => '2026-05-01',
            'ends_at'   => '2026-06-01',
        ]);

        $stored = Campaign::query()->where('id', (int) $campaign->id)->get();
        $this->assertSame('2026-05-01 00:00:00', (string) $stored->starts_at);
        $this->assertNotFalse(strtotime((string) $stored->ends_at));
    }

    public function test_a_campaign_with_no_dates_is_still_fine(): void
    {
        $campaign = $this->campaigns()->create(['title' => 'Open ended']);

        $this->assertNull($campaign->starts_at);
        $this->assertNull($campaign->ends_at);
    }


    private function erasedDonor(): Donor
    {
        $donors = Plugin::instance()->container->get(DonorService::class);
        $donor  = $donors->findOrCreate('erased-' . uniqid() . '@example.test', ['first_name' => 'Ada']);
        $donors->redact($donor);

        return $donor;
    }

    public function test_no_new_text_can_be_recorded_against_an_erased_donor(): void
    {
        $donor = $this->erasedDonor();

        $req = new WP_REST_Request('POST', '/fundkit/v1/admin/donors/' . (int) $donor->id . '/notes');
        $req->set_param('id', (int) $donor->id);
        $req->set_header('content-type', 'application/json');
        $req->set_body('{"body":"Called her about the refund, number is 07700 900222"}');

        $res = rest_do_request($req);

        $this->assertSame(422, $res->get_status(), 'that text could never be erased again');
        $this->assertSame(
            0,
            (int) \FundKit\Donors\DonorNote::query()->where('donor_id', (int) $donor->id)->count()
        );
    }

    public function test_a_note_on_a_living_donor_is_unaffected(): void
    {
        $donor = Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate('living-' . uniqid() . '@example.test', ['first_name' => 'Grace']);

        $req = new WP_REST_Request('POST', '/fundkit/v1/admin/donors/' . (int) $donor->id . '/notes');
        $req->set_param('id', (int) $donor->id);
        $req->set_header('content-type', 'application/json');
        $req->set_body('{"body":"Prefers a call before noon."}');

        $this->assertSame(201, rest_do_request($req)->get_status());
    }


    public function test_a_repeated_consent_key_writes_one_row_not_many(): void
    {
        update_option('fundkit_consents', ['purposes' => [
            ['key' => 'newsletter', 'label' => 'Newsletter', 'required' => false, 'default' => false, 'version' => 1],
        ]]);

        $donor = Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate('flood-' . uniqid() . '@example.test', ['first_name' => 'Ada']);

        $items = array_fill(0, 200, ['key' => 'newsletter', 'granted' => true]);

        $_COOKIE['fundkit_donor_session'] = $this->portalSession((int) $donor->id, 'tok');
        try {
            $req = new WP_REST_Request('POST', '/fundkit/v1/portal/consents');
            $req->set_header('content-type', 'application/json');
            $req->set_header('X-FundKit-Csrf', 'tok');
            $req->set_body((string) wp_json_encode(['items' => $items]));
            $this->assertSame(200, rest_do_request($req)->get_status());
        } finally {
            unset($_COOKIE['fundkit_donor_session']);
        }

        $this->assertSame(
            1,
            (int) Consent::query()->where('donor_id', (int) $donor->id)->count(),
            'an append-only log took two hundred rows from one request'
        );
    }


    /** A handler that fails after the plans have already been cancelled. */
    private function breakTheErasure(): void
    {
        add_filter('fundkit.donor.erasure_handlers', static function (array $handlers): array {
            $handlers[] = new class implements \FundKit\Donors\Erasure\ErasureHandler {
                public function key(): string { return 'test.explodes'; }

                public function erase(\FundKit\Donors\Erasure\ErasureRequest $request): void
                {
                    throw new \RuntimeException('the erasure itself failed');
                }
            };

            return $handlers;
        }, 99);
    }

    /** @return array{status:int, code:string, message:string} */
    private function portalForget(int $donorId): array
    {
        $_COOKIE['fundkit_donor_session'] = $this->portalSession($donorId, 'tok');

        try {
            $req = new WP_REST_Request('POST', '/fundkit/v1/portal/forget');
            $req->set_header('content-type', 'application/json');
            $req->set_header('X-FundKit-Csrf', 'tok');
            $req->set_body('{"confirm":"DELETE"}');

            $res  = rest_do_request($req);
            $data = (array) $res->get_data();

            return [
                'status'  => $res->get_status(),
                'code'    => (string) ($data['code'] ?? ''),
                'message' => (string) ($data['message'] ?? ''),
            ];
        } finally {
            unset($_COOKIE['fundkit_donor_session']);
        }
    }

    public function test_a_failed_erasure_does_not_claim_the_subscription_is_still_running(): void
    {
        $donor = Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate('forget-' . uniqid() . '@example.test', ['first_name' => 'Ada']);

        $now  = gmdate('Y-m-d H:i:s');
        $plan = \FundKit\Recurring\RecurringPlan::make();
        $plan->donor_id                = (int) $donor->id;
        $plan->gateway                 = 'offline';
        $plan->gateway_subscription_id = 'sub_forget_' . uniqid();
        $plan->amount_cents            = 2_000;
        $plan->currency                = 'USD';
        $plan->interval_unit           = 'month';
        $plan->interval_count          = 1;
        $plan->status                  = 'active';
        $plan->started_at              = $now;
        $plan->created_at              = $now;
        $plan->updated_at              = $now;
        $plan->save();

        $this->breakTheErasure();
        $out = $this->portalForget((int) $donor->id);

        $this->assertSame(
            'cancelled',
            (string) \FundKit\Recurring\RecurringPlan::query()->where('id', (int) $plan->id)->get()->status,
            'fixture: the plan is stopped before the erasure runs'
        );
        $this->assertSame(
            'fundkit_erasure_failed',
            $out['code'],
            'the donor was told their subscription was still running after it had been stopped'
        );
        $this->assertStringNotContainsString('could not stop your recurring donation', $out['message']);
    }

    public function test_a_plan_that_could_not_be_stopped_still_says_so(): void
    {
        $donor = Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate('forget-blocked-' . uniqid() . '@example.test', ['first_name' => 'Ada']);

        $now  = gmdate('Y-m-d H:i:s');
        $plan = \FundKit\Recurring\RecurringPlan::make();
        $plan->donor_id                = (int) $donor->id;
        $plan->gateway                 = 'nowhere';
        $plan->gateway_subscription_id = 'sub_blocked_' . uniqid();
        $plan->amount_cents            = 2_000;
        $plan->currency                = 'USD';
        $plan->interval_unit           = 'month';
        $plan->interval_count          = 1;
        $plan->status                  = 'active';
        $plan->started_at              = $now;
        $plan->created_at              = $now;
        $plan->updated_at              = $now;
        $plan->save();

        $out = $this->portalForget((int) $donor->id);

        $this->assertSame('fundkit_erasure_blocked', $out['code']);
        $this->assertSame(409, $out['status']);
    }


    private function wall(): \FundKit\Campaigns\Blocks\SupporterWallBlock
    {
        $c = Plugin::instance()->container;

        return new \FundKit\Campaigns\Blocks\SupporterWallBlock(
            $c->get(\FundKit\Campaigns\CampaignRepository::class),
            $c->get(\FundKit\Donors\DonorAvatars::class),
        );
    }

    public function test_a_ticket_buyer_is_not_listed_as_a_supporter(): void
    {
        $campaign = $this->campaigns()->create(['title' => 'Gala ' . uniqid(), 'status' => 'published']);

        $donor = Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate('ticket-' . uniqid() . '@example.test', ['first_name' => 'Ticket', 'last_name' => 'Buyer']);

        $now = gmdate('Y-m-d H:i:s');
        $d   = Donation::make();
        $d->reference         = 'FUNDKIT-ORDER-' . uniqid();
        $d->donor_id          = (int) $donor->id;
        $d->campaign_id       = (int) $campaign->id;
        $d->kind              = 'order';
        $d->amount_cents      = 5_000;
        $d->net_cents         = 5_000;
        $d->currency          = 'USD';
        $d->base_amount_cents = 5_000;
        $d->base_currency     = 'USD';
        $d->fx_rate           = '1.00000000';
        $d->gateway           = 'offline';
        $d->status            = 'paid';
        $d->is_test           = false;
        $d->is_anonymous      = false;
        $d->donor_first_name  = 'Ticket';
        $d->donor_last_name   = 'Buyer';
        $d->paid_at           = $now;
        $d->created_at        = $now;
        $d->updated_at        = $now;
        $d->save();

        $html = $this->wall()->render(['campaignId' => (int) $campaign->id, 'columns' => 'auto'], '');

        $this->assertStringNotContainsString(
            'Ticket',
            $html,
            'a purchase is not a donation, and the counter beside the wall already excludes it'
        );
    }

    public function test_a_donor_is_still_listed(): void
    {
        $campaign = $this->campaigns()->create(['title' => 'Appeal ' . uniqid(), 'status' => 'published']);

        $donor = Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate('supporter-' . uniqid() . '@example.test', ['first_name' => 'Real', 'last_name' => 'Donor']);

        $now = gmdate('Y-m-d H:i:s');
        $d   = Donation::make();
        $d->reference         = 'FUNDKIT-DON-' . uniqid();
        $d->donor_id          = (int) $donor->id;
        $d->campaign_id       = (int) $campaign->id;
        $d->kind              = 'donation';
        $d->amount_cents      = 5_000;
        $d->net_cents         = 5_000;
        $d->currency          = 'USD';
        $d->base_amount_cents = 5_000;
        $d->base_currency     = 'USD';
        $d->fx_rate           = '1.00000000';
        $d->gateway           = 'offline';
        $d->status            = 'paid';
        $d->is_test           = false;
        $d->is_anonymous      = false;
        $d->donor_first_name  = 'Real';
        $d->donor_last_name   = 'Donor';
        $d->paid_at           = $now;
        $d->created_at        = $now;
        $d->updated_at        = $now;
        $d->save();

        $html = $this->wall()->render(['campaignId' => (int) $campaign->id, 'columns' => 'auto'], '');

        $this->assertStringContainsString('Real', $html);
    }
}
