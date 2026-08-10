<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Campaigns\Campaign;
use Dono\Donors\Portal\PortalPage;
use Dono\Forms\Form;
use Dono\Forms\FormReadinessService;
use Dono\Foundation\Crypto\Crypto;
use Dono\Foundation\License\LicenseService;
use Dono\Gateways\GatewayManager;
use Dono\Gateways\PayPal\PayPalAccount;
use Dono\Gateways\Stripe\ApplePayDomain;
use Dono\Gateways\Stripe\StripeAccount;
use Dono\Gateways\Stripe\StripeApi;
use Dono\Gateways\TestMode;
use Dono\Forms\FormRepository;
use Dono\Settings\ReadinessService;
use Dono\Settings\SettingsService;
use WP_REST_Request;

/**
 * Setup answers "can this site take a donation today". Each of these is a way
 * it silently could not, with the old screen reporting green.
 */
final class ReadinessServiceTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        delete_option('dono_gateway_config');
        delete_option(PortalPage::OPTION_PAGE_ID);
    }

    private function service(): ReadinessService
    {
        $settings = new SettingsService();
        $crypto   = new Crypto();
        $stripe   = new StripeAccount($crypto);
        $api      = new StripeApi($stripe);

        return new ReadinessService(
            $settings,
            new FormReadinessService($settings, new GatewayManager(), $stripe, new TestMode(new FormRepository())),
            $stripe,
            $api,
            new ApplePayDomain($api, $stripe),
            new PayPalAccount($crypto),
            // A fresh registry: these assert what an unconfigured site is told,
            // and the shared one may hold whatever a sibling registered.
            new GatewayManager(),
            new PortalPage(),
            new LicenseService(),
        );
    }

    /** @return array<string,array<string,mixed>> keyed by check id */
    private function checks(): array
    {
        $out = [];
        foreach ($this->service()->check() as $check) {
            $out[$check['id']] = $check;
        }

        return $out;
    }

    private function enableOffline(string $instructions = 'Transfer within 7 days.'): void
    {
        update_option('dono_gateway_config', [
            'offline' => ['enabled' => true, 'instructions' => $instructions],
        ]);
    }

    public function test_a_site_with_no_way_to_charge_is_blocked(): void
    {
        $check = $this->checks()['gateway'];

        $this->assertSame(ReadinessService::FAIL, $check['status']);
        $this->assertTrue($check['blocker']);
    }

    /** Offline alone is a real answer: checks and transfers are donations. */
    public function test_offline_with_instructions_counts_as_a_way_to_charge(): void
    {
        $this->enableOffline();

        $this->assertSame(ReadinessService::PASS, $this->checks()['gateway']['status']);
    }

    /** Enabled but blank instructions leaves the donor a page telling them nothing. */
    public function test_offline_without_instructions_does_not_count(): void
    {
        $this->enableOffline('');

        $this->assertSame(ReadinessService::FAIL, $this->checks()['gateway']['status']);
    }

    public function test_test_mode_is_reported_as_a_warning_not_a_pass(): void
    {
        update_option('dono_gateway_config', ['test_mode' => true]);

        $check = $this->checks()['mode'];
        $this->assertSame(ReadinessService::WARN, $check['status']);
        $this->assertArrayNotHasKey('blocker', $check);
    }

    public function test_live_mode_with_no_gateway_keys_is_a_pass_not_a_false_alarm(): void
    {
        $this->assertSame(ReadinessService::PASS, $this->checks()['mode']['status']);
    }

    /**
     * The failure the old screen could not see: live mode reading a test key
     * charges nobody while the donor sees a success page.
     */
    public function test_live_mode_with_only_test_keys_is_a_blocker(): void
    {
        (new StripeAccount(new Crypto()))->saveKeys(true, 'sk_test_x', 'pk_test_x');

        $check = $this->checks()['mode'];
        $this->assertSame(ReadinessService::FAIL, $check['status']);
        $this->assertTrue($check['blocker']);
    }

    /** Nothing Stripe-shaped should appear when Stripe is not in play. */
    public function test_stripe_rows_are_absent_without_stripe_keys(): void
    {
        $checks = $this->checks();

        $this->assertArrayNotHasKey('stripe-webhook', $checks);
        $this->assertArrayNotHasKey('apple-pay', $checks);
    }

    public function test_stripe_without_a_webhook_secret_is_flagged(): void
    {
        (new StripeAccount(new Crypto()))->saveKeys(false, 'sk_live_x', 'pk_live_x');

        $this->assertSame(ReadinessService::WARN, $this->checks()['stripe-webhook']['status']);
    }

    /**
     * A gateway the org switched off is never offered to a donor, so its
     * credentials, webhook and domain verification are nobody's problem.
     *
     * Reporting them told an org it was blocked from going live by a processor
     * it had deliberately turned off, and counted that among the things
     * stopping donations.
     */
    public function test_a_switched_off_gateway_raises_nothing(): void
    {
        (new StripeAccount(new Crypto()))->saveKeys(true, 'sk_test_x', 'pk_test_x');
        $this->enableOffline();
        update_option('dono_gateway_config', array_merge(
            (array) get_option('dono_gateway_config', []),
            ['stripe' => ['enabled' => false]]
        ));

        $checks = $this->checks();

        $this->assertSame(
            ReadinessService::PASS,
            $checks['mode']['status'],
            'test keys on a gateway that is off do not block going live'
        );
        $this->assertArrayNotHasKey('stripe-webhook', $checks);
        $this->assertArrayNotHasKey('apple-pay', $checks);
        $this->assertStringNotContainsString(
            'Stripe',
            (string) $checks['gateway']['label'],
            'a gateway that is off is not named as a way money can arrive'
        );
    }

    public function test_a_switched_on_gateway_still_raises_its_gaps(): void
    {
        (new StripeAccount(new Crypto()))->saveKeys(true, 'sk_test_x', 'pk_test_x');
        update_option('dono_gateway_config', ['stripe' => ['enabled' => true]]);

        $checks = $this->checks();

        $this->assertSame(ReadinessService::FAIL, $checks['mode']['status']);
        $this->assertArrayHasKey('stripe-webhook', $checks);
    }

    public function test_a_published_campaign_whose_form_is_a_draft_is_not_a_live_page(): void
    {
        $this->publishedCampaign(publishForm: false);

        $check = $this->checks()['donation-page'];
        $this->assertSame(ReadinessService::FAIL, $check['status']);
        $this->assertTrue($check['blocker']);
    }

    public function test_a_published_campaign_with_a_published_form_is_a_live_page(): void
    {
        $this->publishedCampaign(publishForm: true);

        $this->assertSame(ReadinessService::PASS, $this->checks()['donation-page']['status']);
    }

    /** Receipt and sign-in emails already link here, so a missing page is a 404 generator. */
    public function test_a_missing_donor_portal_page_is_a_blocker(): void
    {
        $check = $this->checks()['donor-portal'];

        $this->assertSame(ReadinessService::FAIL, $check['status']);
        $this->assertTrue($check['blocker']);
    }

    public function test_a_published_donor_portal_page_passes(): void
    {
        (new PortalPage())->ensure();

        $this->assertSame(ReadinessService::PASS, $this->checks()['donor-portal']['status']);
    }

    public function test_an_org_with_no_address_is_flagged_on_receipts(): void
    {
        update_option('dono_org_profile', ['name' => 'Test Org', 'address_lines' => [], 'tax_id' => '']);

        $this->assertSame(ReadinessService::WARN, $this->checks()['org-identity']['status']);
    }

    public function test_a_complete_org_passes(): void
    {
        update_option('dono_org_profile', [
            'name'          => 'Test Org',
            'legal_name'    => 'Test Org e.V.',
            'address_lines' => ['1 Example Street', 'Berlin'],
            'tax_id'        => 'DE123',
        ]);

        $this->assertSame(ReadinessService::PASS, $this->checks()['org-identity']['status']);
    }

    /** With no paid add-on installed there is nothing to license, so no row. */
    public function test_licenses_are_absent_when_no_addon_is_installed(): void
    {
        $this->assertArrayNotHasKey('licenses', $this->checks());
    }

    /** Warnings never block; only a failed blocker does. */
    public function test_only_a_failed_blocker_makes_the_site_not_live(): void
    {
        $service = $this->service();

        $this->assertTrue($service->isLive([
            ['id' => 'a', 'status' => ReadinessService::WARN],
            ['id' => 'b', 'status' => ReadinessService::FAIL],
        ]));
        $this->assertFalse($service->isLive([
            ['id' => 'a', 'status' => ReadinessService::FAIL, 'blocker' => true],
        ]));
    }

    public function test_the_endpoint_reports_the_blocker_count(): void
    {
        $data = (array) rest_do_request(new WP_REST_Request('GET', '/dono/v1/admin/readiness'))->get_data();

        $this->assertArrayHasKey('checks', $data);
        $this->assertGreaterThan(0, $data['blockers']);
        $this->assertFalse($data['live']);
    }

    private function publishedCampaign(bool $publishForm): void
    {
        $req = new WP_REST_Request('POST', '/dono/v1/admin/campaigns');
        $req->set_header('content-type', 'application/json');
        $req->set_body(json_encode(['title' => 'Readiness campaign', 'status' => 'published']));
        $campaignId = (int) rest_do_request($req)->get_data()['id'];

        $req = new WP_REST_Request('POST', '/dono/v1/admin/forms');
        $req->set_header('content-type', 'application/json');
        $req->set_body(json_encode([
            'title'       => 'Readiness form',
            'campaign_id' => $campaignId,
            'blocks'      => '<!-- wp:dono/donation-amount /-->',
        ]));
        $formId = (int) rest_do_request($req)->get_data()['id'];

        if ($publishForm) {
            $form = Form::query()->find('id', $formId);
            $form->status = 'published';
            $form->save();
        }

        $campaign = Campaign::query()->find('id', $campaignId);
        $campaign->default_form_id = $formId;
        $campaign->save();
    }
}
