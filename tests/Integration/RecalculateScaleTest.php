<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donors\Donor;
use Dono\Foundation\Plugin;
use WP_REST_Request;

/**
 * Recalculate walks whole tables. It used to hydrate each one into model
 * objects before the first sync ran, so the memory it needed grew with the org
 * and a real donor list exhausted it inside the request, leaving some totals
 * rebuilt and the rest as wrong as they were.
 */
final class RecalculateScaleTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    public function test_it_rebuilds_every_donor_without_holding_them_all(): void
    {
        // More than one chunk, so the paging is actually exercised rather than
        // the whole table happening to fit in the first read.
        $seeded = [];
        for ($i = 0; $i < 12; $i++) {
            $d = Donor::make();
            $d->email_hash = hash('sha256', 'recalc-' . $i . '-' . uniqid());
            $d->created_at = gmdate('Y-m-d H:i:s');
            $d->updated_at = $d->created_at;
            $d->save();
            $seeded[] = (int) $d->id;
        }

        $req = new WP_REST_Request('POST', '/dono/v1/admin/tools/recalculate');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode(['scope' => 'donors']));

        $res = rest_do_request($req);

        $this->assertSame(200, $res->get_status());
        $this->assertGreaterThanOrEqual(
            count($seeded),
            (int) ($res->get_data()['counts']['donors'] ?? 0),
            'every donor is reached, across chunk boundaries'
        );
    }
}
