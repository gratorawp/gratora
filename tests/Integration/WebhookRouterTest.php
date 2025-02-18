<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

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

        $logCount = (int) self::$wpdb->get_var("SELECT COUNT(*) FROM " . self::$prefix . "dono_webhooks_log");
        $this->assertSame(0, $logCount, 'No log row when gateway is unknown - we never reached a handler');
    }

    public function test_offline_gateway_returns_405_and_persists_log_row(): void
    {
        $req = new WP_REST_Request('POST', '/dono/v1/webhooks/offline');
        $req->set_body('{"event":"fake"}');
        $req->set_header('content-type', 'application/json');
        $res = rest_do_request($req);

        $this->assertSame(405, $res->get_status());

        $log = self::$wpdb->get_row("SELECT * FROM " . self::$prefix . "dono_webhooks_log");
        $this->assertNotNull($log);
        $this->assertSame('offline', $log->gateway);
        $this->assertFalse((bool) $log->signature_ok);
        $this->assertFalse((bool) $log->processed);
        $this->assertNotEmpty($log->error);
    }
}
