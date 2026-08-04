<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donors\ConsentService;
use Dono\Donors\DonorService;
use Dono\Foundation\Plugin;
use WP_REST_Request;

/**
 * A donation form can define its own consent purposes and the create path
 * records them. The portal listed only the org registry, so a purpose that
 * lives on a form was one the donor agreed to and could never see or withdraw.
 */
final class PortalFormConsentTest extends IntegrationTestCase
{
    private const FORM_PURPOSE = 'newsletter_from_form';
    private const CSRF         = 'test-csrf-token';

    private object $donor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->donor = Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate('consent-' . uniqid() . '@example.test');

        $sid = bin2hex(random_bytes(32));
        set_transient(
            'dono_portal_' . hash('sha256', $sid),
            ['donor_id' => (int) $this->donor->id, 'csrf' => self::CSRF],
            3600
        );
        $_COOKIE['dono_donor_session'] = $sid;
    }

    protected function tearDown(): void
    {
        unset($_COOKIE['dono_donor_session']);
        parent::tearDown();
    }

    /** Record consent for a purpose the org registry does not define. */
    private function grantFromForm(bool $granted = true): void
    {
        Plugin::instance()->container->get(ConsentService::class)->record(
            (int) $this->donor->id,
            self::FORM_PURPOSE,
            $granted,
            ['source' => 'donation']
        );
    }

    private function listed(): array
    {
        $res = rest_do_request(new WP_REST_Request('GET', '/dono/v1/portal/consents'));

        return (array) $res->get_data();
    }

    public function test_a_form_defined_consent_is_visible_in_the_portal(): void
    {
        $this->grantFromForm();

        $keys = array_column($this->listed(), 'key');

        $this->assertContains(self::FORM_PURPOSE, $keys, 'the donor can see what they agreed to');
    }

    public function test_it_is_never_marked_required(): void
    {
        $this->grantFromForm();

        foreach ($this->listed() as $row) {
            if ($row['key'] === self::FORM_PURPOSE) {
                $this->assertFalse($row['required'], 'nothing off the registry can be unwithdrawable');
            }
        }
    }

    public function test_the_donor_can_withdraw_it(): void
    {
        $this->grantFromForm();

        $req = new WP_REST_Request('POST', '/dono/v1/portal/consents');
        $req->set_header('content-type', 'application/json');
        $req->set_header('X-Dono-Csrf', self::CSRF);
        $req->set_body((string) wp_json_encode([
            'items' => [[ 'key' => self::FORM_PURPOSE, 'granted' => false ]],
        ]));
        rest_do_request($req);

        foreach ($this->listed() as $row) {
            if ($row['key'] === self::FORM_PURPOSE) {
                $this->assertFalse($row['granted'], 'withdrawal is recorded');
                return;
            }
        }

        $this->fail('the purpose vanished from the portal');
    }

    public function test_a_key_the_donor_has_no_record_for_is_still_refused(): void
    {
        // Widening withdrawal must not widen granting: a crafted payload
        // cannot mint consent for an arbitrary purpose.
        $req = new WP_REST_Request('POST', '/dono/v1/portal/consents');
        $req->set_header('content-type', 'application/json');
        $req->set_header('X-Dono-Csrf', self::CSRF);
        $req->set_body((string) wp_json_encode([
            'items' => [[ 'key' => 'invented_purpose', 'granted' => true ]],
        ]));
        rest_do_request($req);

        $this->assertNotContains('invented_purpose', array_column($this->listed(), 'key'));
    }
}
