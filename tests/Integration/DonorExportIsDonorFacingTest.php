<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Analytics\ErrorLog;
use Gratora\Donors\Donor;
use Gratora\Donors\DonorService;
use Gratora\Foundation\Plugin;
use WP_REST_Request;

/**
 * The donor's right of access is to their own file, which is not the same set
 * as the organization's working record: the staff notes about them, and the
 * error log, whose rows carry raw gateway text, upstream response bodies and
 * prefixed table names. The portal's own failure responses are worded to keep
 * exactly that back.
 */
final class DonorExportIsDonorFacingTest extends IntegrationTestCase
{
    private string $csrf = '';

    private function signedInDonor(): Donor
    {
        $donor = Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate('export-' . uniqid() . '@example.test', ['first_name' => 'Nadia']);

        $this->csrf = bin2hex(random_bytes(8));
        $_COOKIE['gratora_donor_session'] = $this->portalSession((int) $donor->id, $this->csrf);

        return $donor;
    }

    protected function tearDown(): void
    {
        unset($_COOKIE['gratora_donor_session']);
        parent::tearDown();
    }

    /**
     * The file itself. The route streams it through rest_pre_serve_request, so
     * the response body is where it lands and rest_do_request never sees it.
     *
     * @return array<string,mixed>
     */
    private function export(): array
    {
        remove_all_filters('rest_pre_serve_request');

        $req = new WP_REST_Request('POST', '/gratora/v1/portal/data-export');
        $req->set_header('X-Gratora-Csrf', $this->csrf);

        $res = rest_do_request($req);
        $this->assertSame(200, $res->get_status(), (string) wp_json_encode($res->get_data()));

        ob_start();
        apply_filters('rest_pre_serve_request', false, $res, $req, rest_get_server());
        $body = (string) ob_get_clean();

        $bundle = json_decode($body, true);
        $this->assertIsArray($bundle, 'the export did not stream a JSON file: ' . $body);
        $this->assertArrayHasKey('exported_at', $bundle, 'the export is not the donor bundle: ' . $body);

        return $bundle;
    }

    public function test_the_error_log_message_is_not_in_the_donors_file(): void
    {
        $donor  = $this->signedInDonor();
        $secret = 'SQLSTATE[42S02] wptests_gratora_recurring_plans does not exist';

        ErrorLog::record('portal.recurring', $secret, ['donor_id' => (int) $donor->id]);

        $json = (string) wp_json_encode($this->export());

        $this->assertStringNotContainsString($secret, $json);
        $this->assertStringNotContainsString('wptests_', $json);
    }

    public function test_the_event_is_still_listed(): void
    {
        $donor = $this->signedInDonor();
        ErrorLog::record('portal.recurring', 'raw gateway text', ['donor_id' => (int) $donor->id]);

        $types = array_map(
            static fn (array $e): string => (string) ($e['type'] ?? ''),
            (array) ($this->export()['events'] ?? [])
        );

        $this->assertContains(ErrorLog::PREFIX . 'portal.recurring', $types);
    }
}
