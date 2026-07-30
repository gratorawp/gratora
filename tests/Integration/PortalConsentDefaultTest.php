<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donors\DonorService;
use Dono\Foundation\Plugin;
use WP_REST_Request;

/**
 * A consent purpose whose form default is "on" (campaign_updates) must not show
 * as granted in the portal until the donor actually grants it. The delivery
 * gate and the admin consent view both treat "no record" as "not consented";
 * the portal has to agree, or a donor sees a ticked box for a subscription
 * nothing will honour.
 */
final class PortalConsentDefaultTest extends IntegrationTestCase
{
    public function test_a_default_on_purpose_is_not_granted_without_a_record(): void
    {
        $donor = Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate('no-consent@example.test', ['first_name' => 'No', 'last_name' => 'Record']);

        $rows = $this->portalConsents((int) $donor->id);
        $byKey = [];
        foreach ($rows as $r) {
            $byKey[$r['key']] = $r;
        }

        $this->assertArrayHasKey('campaign_updates', $byKey, 'the default-on purpose is present');
        $this->assertFalse($byKey['campaign_updates']['granted'], 'no record means not granted, whatever the form default');
        $this->assertFalse($byKey['campaign_updates']['has_record']);

        $this->assertArrayHasKey('newsletter', $byKey);
        $this->assertFalse($byKey['newsletter']['granted']);
    }

    /** @return list<array<string,mixed>> */
    private function portalConsents(int $donorId): array
    {
        $sid = bin2hex(random_bytes(32));
        set_transient(
            'dono_portal_' . hash('sha256', $sid),
            ['donor_id' => $donorId, 'csrf' => bin2hex(random_bytes(8))],
            HOUR_IN_SECONDS
        );
        $_COOKIE['dono_donor_session'] = $sid;

        try {
            $res = rest_do_request(new WP_REST_Request('GET', '/dono/v1/portal/consents'));
            $this->assertSame(200, $res->get_status());

            return (array) $res->get_data();
        } finally {
            unset($_COOKIE['dono_donor_session']);
        }
    }
}
