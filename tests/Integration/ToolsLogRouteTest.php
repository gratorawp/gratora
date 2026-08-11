<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Analytics\ErrorLog;
use Dono\Analytics\Event;
use WP_REST_Request;

/**
 * One log, two kinds of entry. What Dono could not finish and what a gateway
 * sent this site are the same question asked twice, and an org chasing a payment
 * that never arrived should not have to know which screen holds which half.
 */
final class ToolsLogRouteTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    /** Drive a real delivery through the router so the row is written the way production writes it. */
    private function deliver(string $body = '{"event":"fake"}'): void
    {
        $req = new WP_REST_Request('POST', '/dono/v1/webhooks/offline');
        $req->set_header('content-type', 'application/json');
        $req->set_body($body);
        rest_do_request($req);
    }

    /** @return array<string,mixed> */
    private function fetch(array $params = []): array
    {
        $req = new WP_REST_Request('GET', '/dono/v1/admin/tools/log');
        foreach ($params as $k => $v) {
            $req->set_param($k, $v);
        }
        $res = rest_do_request($req);

        $this->assertSame(200, $res->get_status(), (string) wp_json_encode($res->get_data()));

        return (array) $res->get_data();
    }

    public function test_failures_and_deliveries_arrive_in_the_same_list(): void
    {
        ErrorLog::record('gateway.intent', 'PayPal has no live credentials.');
        $this->deliver();

        $items = (array) $this->fetch()['items'];
        $kinds = array_column($items, 'kind');

        $this->assertContains('error', $kinds);
        $this->assertContains('webhook', $kinds);
    }

    public function test_a_delivery_never_carries_the_request_body_out_of_the_database(): void
    {
        $this->deliver((string) wp_json_encode([
            'payer' => ['email_address' => 'donor@example.test', 'name' => 'A Donor'],
        ]));

        $encoded = (string) wp_json_encode($this->fetch());

        // The body a gateway posts is the donor's details in someone else's
        // format. It is not stored, so it cannot be served.
        $this->assertStringNotContainsString('donor@example.test', $encoded);
        $this->assertStringNotContainsString('A Donor', $encoded);
        $this->assertStringNotContainsString('payer', $encoded);
    }

    public function test_a_verified_delivery_with_no_handler_is_not_reported_as_a_failure(): void
    {
        // Written straight in: a gateway that accepts webhooks at all is needed
        // to produce this outcome through the router, and the state under test
        // is how the filter reads a row, not how the row got written.
        $e = Event::make();
        $e->type        = 'webhook.stripe';
        $e->payload     = [
            'event_type' => 'customer.updated',
            'verified'   => true,
            'processed'  => false,
            'error'      => null,
        ];
        $e->occurred_at = gmdate('Y-m-d H:i:s');
        $e->save();

        $all      = $this->fetch();
        $failures = $this->fetch(['status' => 'failed']);

        // A gateway sends every event it has and Dono acts on the few it needs.
        // Counting the rest as faults would make a healthy site look broken.
        $this->assertSame(1, (int) $all['total']);
        $this->assertSame(0, (int) $failures['total']);
    }

    public function test_the_source_filter_reaches_both_families(): void
    {
        ErrorLog::record('gateway.intent', 'A recorded failure.');
        $this->deliver();

        $sources = (array) $this->fetch()['sources'];

        // The dropdown offers whole types, so an org can narrow to one gateway's
        // deliveries or to one subsystem's failures from the same control.
        $this->assertContains('error.gateway.intent', $sources);
        $this->assertContains('webhook.offline', $sources);
    }

    public function test_the_route_is_closed_to_a_subscriber(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));

        $res = rest_do_request(new WP_REST_Request('GET', '/dono/v1/admin/tools/log'));

        $this->assertGreaterThanOrEqual(400, $res->get_status());
    }
}
