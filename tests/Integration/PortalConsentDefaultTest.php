<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donors\DonorService;
use Dono\Foundation\Plugin;
use Dono\Settings\SettingsService;
use WP_REST_Request;

/**
 * A consent purpose whose form default is "on" must not show as granted in the
 * portal until the donor actually grants it. The delivery gate and the admin
 * consent view both treat "no record" as "not consented"; the portal has to
 * agree, or a donor sees a ticked box for a subscription nothing will honour.
 *
 * The purposes are registered here rather than relied upon: core ships none,
 * because a purpose names something a particular organisation does.
 */
final class PortalConsentDefaultTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        delete_option('dono_consents');
        parent::tearDown();
    }

    private function registerPurposes(): void
    {
        Plugin::instance()->container->get(SettingsService::class)->update('consents', [
            'purposes' => [
                ['key' => 'newsletter', 'label' => 'Newsletter', 'description' => '', 'required' => false, 'default' => false, 'version' => 1],
                ['key' => 'campaign_updates', 'label' => 'Campaign updates', 'description' => '', 'required' => false, 'default' => true, 'version' => 1],
            ],
        ]);
    }

    public function test_core_ships_no_consent_purposes(): void
    {
        $this->assertSame(
            [],
            Plugin::instance()->container->get(SettingsService::class)->get('consents')['purposes'],
            'a purpose names something the org does, so core cannot guess one'
        );
    }

    public function test_a_default_on_purpose_is_not_granted_without_a_record(): void
    {
        $this->registerPurposes();

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
