<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Webhooks\WebhookLog;
use WP_REST_Request;

/**
 * The delivery log is the only place that can answer "did my gateway reach this
 * site", so it needs a reader. It also holds raw request bodies and transmission
 * headers, which carry payer names, emails and addresses, and those are kept out
 * of the export on purpose. A read route must not become the way around that.
 */
final class WebhookDeliveriesRouteTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    private function record(string $externalId, bool $verified, bool $processed, ?string $error = null): void
    {
        $log = WebhookLog::make();
        $log->gateway      = 'paypal';
        $log->external_id  = $externalId;
        $log->event_type   = 'PAYMENT.CAPTURE.COMPLETED';
        $log->signature_ok = $verified;
        $log->payload      = (string) wp_json_encode([
            'payer' => ['email_address' => 'donor@example.test', 'name' => 'A Donor'],
        ]);
        $log->headers      = ['paypal_transmission_sig' => 'secret-looking-value'];
        $log->processed    = $processed;
        $log->processed_at = $processed ? '2026-08-11 12:00:00' : null;
        $log->error        = $error;
        $log->received_at  = '2026-08-11 12:00:00';
        $log->save();
    }

    /** @return array<string,mixed> */
    private function fetch(array $params = []): array
    {
        $req = new WP_REST_Request('GET', '/dono/v1/admin/tools/webhooks');
        foreach ($params as $k => $v) {
            $req->set_param($k, $v);
        }
        $res = rest_do_request($req);

        $this->assertSame(200, $res->get_status(), (string) wp_json_encode($res->get_data()));

        return (array) $res->get_data();
    }

    public function test_deliveries_are_listed_newest_first(): void
    {
        $this->record('WH-A', true, true);
        $this->record('WH-B', false, false, 'Signature verification failed.');

        $data = $this->fetch();

        $this->assertSame(2, (int) $data['total']);
        $this->assertCount(2, $data['items']);
        $this->assertContains('paypal', (array) $data['gateways']);
    }

    public function test_the_payload_and_headers_never_leave_the_database(): void
    {
        $this->record('WH-PRIVATE', true, true);

        $encoded = (string) wp_json_encode($this->fetch());

        // dono_webhooks_log is excluded from the full data export because these
        // bodies are other people's personal data. A list route that returned
        // them would be the way around that refusal.
        $this->assertStringNotContainsString('donor@example.test', $encoded);
        $this->assertStringNotContainsString('secret-looking-value', $encoded);
        $this->assertStringNotContainsString('payer', $encoded);
    }

    public function test_a_verified_delivery_with_no_handler_is_not_reported_as_a_failure(): void
    {
        $this->record('WH-UNHANDLED', true, false);
        $this->record('WH-REFUSED', false, false, 'Signature verification failed.');

        $failures = $this->fetch(['status' => 'failed']);

        // An event type Dono has no branch for is normal traffic, not a fault.
        $ids = array_map(static fn (array $r): string => (string) ($r['event_type'] ?? ''), (array) $failures['items']);
        $this->assertSame(1, (int) $failures['total'], (string) wp_json_encode($ids));
    }

    public function test_the_route_is_closed_to_a_subscriber(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));

        $res = rest_do_request(new WP_REST_Request('GET', '/dono/v1/admin/tools/webhooks'));

        $this->assertGreaterThanOrEqual(400, $res->get_status());
    }
}
