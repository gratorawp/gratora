<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Analytics\Event;

use WP_REST_Request;

final class WebhookRouterTest extends IntegrationTestCase
{
    public function test_unknown_gateway_returns_404_with_no_log_row(): void
    {
        $req = new WP_REST_Request('POST', '/dono/v1/webhooks/nonexistent');
        $req->set_body('{}');
        $req->set_header('content-type', 'application/json');
        $res = rest_do_request($req);

        $this->assertSame(404, $res->get_status());
        $this->assertSame('dono_unknown_gateway', $res->get_data()['code']);

        $logCount = (int) Event::query()->whereLike('type', 'webhook.%')->count();
        $this->assertSame(0, $logCount, 'No log row when gateway is unknown - we never reached a handler');
    }

    public function test_offline_gateway_returns_405_and_persists_log_row(): void
    {
        $req = new WP_REST_Request('POST', '/dono/v1/webhooks/offline');
        $req->set_body('{"event":"fake"}');
        $req->set_header('content-type', 'application/json');
        $res = rest_do_request($req);

        $this->assertSame(405, $res->get_status());

        $rows = Event::query()->whereLike('type', 'webhook.%')->getAll();
        $this->assertCount(1, $rows);
        $this->assertSame('webhook.offline', $rows[0]->type);

        $payload = (array) $rows[0]->payload;
        $this->assertFalse((bool) $payload['verified']);
        $this->assertFalse((bool) $payload['processed']);
        $this->assertNotEmpty($payload['error']);
    }
}
