<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Analytics\EventRecorder;
use Dono\Donations\Donation;
use Dono\Donations\DonationRepository;
use Dono\Donations\DonationService;
use Dono\Donors\Donor;
use Dono\Donors\DonorMetricsService;
use Dono\Foundation\Plugin;
use WP_REST_Request;

/**
 * A donor who reaches the payment step, backs out and picks another gateway
 * leaves a pending row behind on every hop, each with its own reference and its
 * own "Donation started" event. Only the last one can ever take money.
 *
 * Every admin read of pending donations therefore has to stop counting the
 * replaced ones, without any of them changing status: Cancel is reachable while
 * an approval is genuinely in flight, and four paths guard on pending.
 */
final class SupersededAttemptReadsTest extends IntegrationTestCase
{
    private int $donorId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->donorId = $this->makeDonor();
    }

    // ---------------------------------------------------------------- fixtures

    private function makeDonor(): int
    {
        $now   = gmdate('Y-m-d H:i:s');
        $email = 'switcher-' . uniqid() . '@example.test';

        $d = Donor::make();
        $d->email_hash      = hash('sha256', $email);
        $d->email_encrypted = 'enc:' . $email;
        $d->first_name      = 'Rea';
        $d->last_name       = 'Try';
        $d->created_at      = $now;
        $d->updated_at      = $now;
        $d->save();

        return (int) $d->id;
    }

    private function attempt(string $gateway, string $status = 'pending'): Donation
    {
        $now = gmdate('Y-m-d H:i:s');

        $d = Donation::make();
        $d->reference    = 'DONO-SUP-' . strtoupper(substr(md5(uniqid('', true)), 0, 10));
        $d->donor_id     = $this->donorId;
        $d->status       = $status;
        $d->kind         = 'donation';
        $d->gateway      = $gateway;
        $d->amount_cents = 5000;
        $d->net_cents    = 5000;
        $d->currency     = 'USD';
        $d->base_amount_cents = 5000;
        $d->base_currency     = 'USD';
        $d->fx_rate      = '1.00000000';
        $d->paid_at      = $status === 'paid' ? $now : null;
        $d->created_at   = $now;
        $d->updated_at   = $now;
        $d->save();

        Plugin::instance()->container->get(EventRecorder::class)->record('donation.intent_created', [
            'donor_id'     => $this->donorId,
            'donation_id'  => (int) $d->id,
            'amount_cents' => 5000,
            'currency'     => 'USD',
        ]);

        return $d;
    }

    /**
     * One donor decision, three submissions: Stripe, cancelled for PayPal,
     * cancelled for Stripe again. Only the last is live.
     *
     * @return array{0:Donation,1:Donation,2:Donation}
     */
    private function tree(): array
    {
        $first  = $this->attempt('stripe');
        $second = $this->attempt('paypal');
        $third  = $this->attempt('stripe');

        $service = Plugin::instance()->container->get(DonationService::class);
        $service->recordRetriedBy($first, (string) $second->reference);
        $service->recordRetriedBy($second, (string) $third->reference);

        return [$first, $second, $third];
    }

    private function fresh(Donation $d): Donation
    {
        return Plugin::instance()->container->get(DonationRepository::class)
            ->findByReference((string) $d->reference);
    }

    // ------------------------------------------------------------- admin list

    private function listRequest(array $params = []): \WP_REST_Response
    {
        $req = new WP_REST_Request('GET', '/dono/v1/admin/donations');
        $req->set_query_params(array_merge(['page' => 1, 'per_page' => 25], $params));

        return rest_do_request($req);
    }

    private function statsRequest(array $params = []): array
    {
        $req = new WP_REST_Request('GET', '/dono/v1/admin/donations/stats');
        $req->set_query_params($params);

        return (array) rest_do_request($req)->get_data();
    }

    /**
     * A search names one row. The term matches reference, gateway_intent_id
     * and gateway_txn_id, so it is how a support question arrives: from the
     * reference a donor was emailed, or from a gateway dashboard. That row can
     * still settle, so answering with nothing answers a question about real
     * money in flight.
     */
    public function test_a_search_finds_a_replaced_attempt(): void
    {
        [$first, , $third] = $this->tree();

        $refs = array_column(
            (array) $this->listRequest(['search' => (string) $first->reference])->get_data(),
            'reference'
        );

        $this->assertSame([$first->reference], $refs, 'the replaced attempt answers to its own reference');

        $live = array_column(
            (array) $this->listRequest(['search' => (string) $third->reference])->get_data(),
            'reference'
        );

        $this->assertSame([$third->reference], $live, 'and a search for the live one is unaffected');
    }

    public function test_the_list_shows_one_row_per_donor_decision(): void
    {
        [$first, $second, $third] = $this->tree();

        $res  = $this->listRequest();
        $refs = array_column((array) $res->get_data(), 'reference');

        $this->assertSame([$third->reference], $refs, 'only the live attempt is listed');
        $this->assertSame('1', $res->get_headers()['X-WP-Total'] ?? null);
        $this->assertNotContains($first->reference, $refs);
        $this->assertNotContains($second->reference, $refs);
    }

    public function test_the_pending_facet_is_not_inflated(): void
    {
        $this->tree();

        $res = $this->listRequest(['status' => 'pending']);

        $this->assertSame('1', $res->get_headers()['X-WP-Total'] ?? null);
        $this->assertCount(1, (array) $res->get_data());
    }

    public function test_the_headline_count_matches_the_rows(): void
    {
        $this->tree();

        $stats = $this->statsRequest();

        $this->assertSame(1, (int) $stats['total_count']);
        $this->assertSame(0, (int) $stats['paid_count'], 'nothing was collected');
        $this->assertSame(0, (int) $stats['raised_cents']);
    }

    public function test_no_status_is_moved(): void
    {
        [$first, $second] = $this->tree();

        $this->assertSame('pending', $this->fresh($first)->status);
        $this->assertSame('pending', $this->fresh($second)->status);
    }

    public function test_asking_for_replaced_attempts_returns_them(): void
    {
        [$first, $second, $third] = $this->tree();

        $rows = (array) $this->listRequest(['superseded' => 1])->get_data();
        $refs = array_column($rows, 'reference');

        sort($refs);
        $expected = [$first->reference, $second->reference];
        sort($expected);

        $this->assertSame($expected, $refs);
        $this->assertNotContains($third->reference, $refs);
        $this->assertTrue((bool) $rows[0]['superseded'], 'the row says why it is unusual');
    }

    public function test_widening_the_scope_shows_the_whole_tree(): void
    {
        [$first, $second, $third] = $this->tree();

        $res  = $this->listRequest(['include_superseded' => 1]);
        $refs = array_column((array) $res->get_data(), 'reference');

        $this->assertSame('3', $res->get_headers()['X-WP-Total'] ?? null);
        $this->assertContains($first->reference, $refs);
        $this->assertContains($second->reference, $refs);
        $this->assertContains($third->reference, $refs);
    }

    public function test_the_kpi_strip_follows_the_replaced_filter(): void
    {
        $this->tree();

        $this->assertSame(2, (int) $this->statsRequest(['superseded' => 1])['total_count']);
        $this->assertSame(3, (int) $this->statsRequest(['include_superseded' => 1])['total_count']);
    }

    public function test_the_row_that_can_still_take_money_says_it_is_live(): void
    {
        [, , $third] = $this->tree();

        $rows = (array) $this->listRequest()->get_data();

        $this->assertSame($third->reference, $rows[0]['reference']);
        $this->assertFalse((bool) $rows[0]['superseded']);
    }

    /**
     * The property the whole read-side approach rests on. Cancel is reachable
     * while an approval is genuinely in flight, so a replaced row can settle;
     * the moment it does it is real money and must be counted everywhere.
     */
    public function test_a_replaced_attempt_that_settles_is_counted_again(): void
    {
        [$first, , $third] = $this->tree();

        $settled = $this->fresh($first);
        $settled->status  = 'paid';
        $settled->paid_at = gmdate('Y-m-d H:i:s');
        $settled->save();

        $refs = array_column((array) $this->listRequest()->get_data(), 'reference');

        $this->assertContains($first->reference, $refs, 'money that arrived is listed');
        $this->assertContains($third->reference, $refs);
        $this->assertSame(2, (int) $this->statsRequest()['total_count']);
    }

    public function test_the_settled_row_keeps_its_breadcrumb(): void
    {
        [$first, $second] = $this->tree();

        $settled = $this->fresh($first);
        $settled->status = 'paid';
        $settled->save();

        $this->assertSame(
            (string) $second->reference,
            (string) ($this->fresh($first)->flags['retried_by'] ?? ''),
            'nothing is erased, the predicate simply stops matching'
        );
    }

    public function test_the_csv_export_leaves_replaced_attempts_out(): void
    {
        [$first, $second, $third] = $this->tree();

        $csv = $this->serveBody('/dono/v1/admin/donations/export.csv');

        $this->assertStringContainsString((string) $third->reference, $csv);
        $this->assertStringNotContainsString((string) $first->reference, $csv);
        $this->assertStringNotContainsString((string) $second->reference, $csv);
    }

    // ---------------------------------------------------------- donor profile

    private function profile(): array
    {
        return (array) Plugin::instance()->container
            ->get(DonorMetricsService::class)
            ->profile($this->donorId);
    }

    public function test_the_profile_card_does_not_lose_real_history_to_replaced_attempts(): void
    {
        $paid = $this->attempt('stripe', 'paid');
        [$first, $second, $third] = $this->tree();

        $profile = $this->profile();
        $refs    = array_column($profile['donations'], 'reference');

        $this->assertContains($paid->reference, $refs);
        $this->assertContains($third->reference, $refs);
        $this->assertNotContains($first->reference, $refs);
        $this->assertNotContains($second->reference, $refs);
    }

    public function test_the_donations_tab_badge_counts_one_per_decision(): void
    {
        $this->attempt('stripe', 'paid');
        $this->tree();

        $this->assertSame(2, (int) $this->profile()['donations_total']);
    }

    public function test_the_timeline_reports_one_started_donation(): void
    {
        $this->tree();

        $types = array_column($this->profile()['events'], 'type');

        $this->assertSame(
            1,
            count(array_filter($types, static fn ($t): bool => $t === 'donation.intent_created')),
            'three submissions of one donation read as three abandonments'
        );
        $this->assertSame(1, (int) $this->profile()['events_total']);
    }

    public function test_the_paged_activity_log_agrees_with_its_own_total(): void
    {
        $this->tree();

        $req = new WP_REST_Request('GET', '/dono/v1/admin/donors/' . $this->donorId . '/events');
        $req->set_query_params(['page' => 1, 'per_page' => 25, 'order' => 'desc']);
        $res = rest_do_request($req);

        $this->assertSame('1', $res->get_headers()['X-WP-Total'] ?? null);
        $this->assertCount(1, (array) $res->get_data());
    }

    /**
     * dono_events.donation_id is nullable, and most of a donor's timeline is
     * these: magic links, consents, portal sign-ins. They point at no donation
     * and cannot be a replaced attempt.
     */
    public function test_the_timeline_keeps_events_that_are_about_no_donation(): void
    {
        $this->tree();

        Plugin::instance()->container->get(EventRecorder::class)
            ->record('donor.magic_link_sent', ['donor_id' => $this->donorId]);

        $types = array_column($this->profile()['events'], 'type');

        $this->assertContains('donor.magic_link_sent', $types);
        $this->assertSame(2, (int) $this->profile()['events_total']);
    }

    public function test_a_settled_attempt_gets_its_timeline_entry_back(): void
    {
        [$first] = $this->tree();

        $settled = $this->fresh($first);
        $settled->status = 'paid';
        $settled->save();

        $types = array_column($this->profile()['events'], 'type');

        $this->assertSame(
            2,
            count(array_filter($types, static fn ($t): bool => $t === 'donation.intent_created'))
        );
    }

    // --------------------------------------------------------- donation detail

    private function detail(Donation $d): array
    {
        $req = new WP_REST_Request('GET', '/dono/v1/admin/donations/' . $d->reference);
        $req->set_url_params(['reference' => (string) $d->reference]);

        return (array) rest_do_request($req)->get_data();
    }

    public function test_a_replaced_attempt_is_still_reachable_by_reference(): void
    {
        [$first, $second] = $this->tree();

        $payload = $this->detail($first);

        $this->assertSame((string) $first->reference, (string) $payload['donation']['reference']);
        $this->assertTrue((bool) $payload['donation']['superseded']);
        $this->assertSame(
            (string) $second->reference,
            (string) ($payload['donation']['flags']['retried_by'] ?? ''),
            'the screen can name the donation that replaced this one'
        );
    }

    public function test_the_sidebar_shows_giving_history_not_the_same_donation_again(): void
    {
        $paid = $this->attempt('stripe', 'paid');
        [$first, $second, $third] = $this->tree();

        $related = array_column($this->detail($third)['related'], 'reference');

        $this->assertContains($paid->reference, $related);
        $this->assertContains($third->reference, $related, 'the row being looked at is in its own list');
        $this->assertNotContains($first->reference, $related);
        $this->assertNotContains($second->reference, $related);
    }

    public function test_a_replaced_attempt_appears_in_its_own_sidebar(): void
    {
        [$first] = $this->tree();

        $related = $this->detail($first)['related'];
        $self    = array_values(array_filter($related, static fn ($r): bool => ! empty($r['is_self'])));

        $this->assertCount(1, $self);
        $this->assertSame((string) $first->reference, (string) $self[0]['reference']);
    }

    // ------------------------------------------------------- untouchable paths

    /**
     * Four paths claim a row by status = 'pending': DonationService::confirm,
     * markProcessing, GatewayReconciler::stranded and the PayPal recurring
     * signup claim. A donor can cancel while an approval is genuinely in
     * flight, so all four have to keep seeing replaced attempts.
     */
    public function test_a_plain_pending_query_still_sees_every_attempt(): void
    {
        $this->tree();

        $this->assertSame(3, (int) Donation::query()->where('status', 'pending')->count());
    }

    /**
     * The subject access request is a record of what the site holds about the
     * person, not a curated ledger, so nothing is filtered out of it.
     */
    public function test_the_donor_data_export_still_carries_every_attempt(): void
    {
        [$first, $second, $third] = $this->tree();

        $export = (array) Plugin::instance()->container
            ->get(DonorMetricsService::class)
            ->exportData($this->donorId);
        $refs = array_column($export['donations'], 'reference');

        $this->assertContains($first->reference, $refs);
        $this->assertContains($second->reference, $refs);
        $this->assertContains($third->reference, $refs);
    }

    public function test_the_hidden_test_notice_counts_what_this_view_would_show(): void
    {
        [$first, $second, $third] = $this->tree();

        foreach ([$first, $second, $third] as $d) {
            $row = $this->fresh($d);
            $row->is_test = true;
            $row->save();
        }

        $res = $this->listRequest();

        $this->assertSame('1', $res->get_headers()['X-Dono-Test-Hidden'] ?? null);
    }
}
