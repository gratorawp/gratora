<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Foundation\Auth\Capabilities;
use FundKit\Foundation\Plugin;
use FundKit\Settings\SettingsService;
use WP_REST_Request;

/**
 * Arming the nightly sweep clears name, email, address, phone, tax id and notes
 * off every donor it reaches, on a schedule and irreversibly. Redacting one
 * donor by hand costs fundkit_redact_donors, so this cannot cost less.
 */
final class ErasureSettingGateTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        delete_option('fundkit_roles');
        Capabilities::applyMapping([]);
    }

    protected function tearDown(): void
    {
        Capabilities::applyMapping([]);
        parent::tearDown();
    }

    private function asSettingsManager(array $extraCaps = []): void
    {
        Capabilities::applyMapping([
            'editor' => array_merge(['fundkit_manage_settings'], $extraCaps),
        ]);
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
    }

    /** @param array<string, mixed> $body */
    private function savePrivacy(array $body): \WP_REST_Response
    {
        $req = new WP_REST_Request('PUT', '/fundkit/v1/admin/settings/privacy');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode($body));

        return rest_do_request($req);
    }

    private function privacy(): array
    {
        return Plugin::instance()->container->get(SettingsService::class)->get('privacy');
    }

    public function test_a_settings_manager_cannot_arm_the_sweep(): void
    {
        $this->asSettingsManager();

        $res = $this->savePrivacy(['erase_inactive_donors' => true]);

        $this->assertSame(403, $res->get_status());
        $this->assertFalse((bool) $this->privacy()['erase_inactive_donors']);
    }

    public function test_someone_who_may_redact_donors_can(): void
    {
        $this->asSettingsManager(['fundkit_redact_donors']);

        $res = $this->savePrivacy(['erase_inactive_donors' => true]);

        $this->assertSame(200, $res->get_status(), (string) wp_json_encode($res->get_data()));
        $this->assertTrue((bool) $this->privacy()['erase_inactive_donors']);
    }

    public function test_a_settings_manager_cannot_shorten_the_window_either(): void
    {
        $this->asSettingsManager(['fundkit_redact_donors']);
        $this->assertSame(200, $this->savePrivacy(['erase_inactive_donors' => true])->get_status());

        $this->asSettingsManager();
        $res = $this->savePrivacy(['donor_retention_years' => 1]);

        $this->assertSame(403, $res->get_status());
        $this->assertSame(7, (int) $this->privacy()['donor_retention_years']);
    }

    public function test_turning_the_sweep_off_is_not_a_widening(): void
    {
        $this->asSettingsManager(['fundkit_redact_donors']);
        $this->savePrivacy(['erase_inactive_donors' => true]);

        $this->asSettingsManager();
        $res = $this->savePrivacy(['erase_inactive_donors' => false]);

        $this->assertSame(200, $res->get_status());
        $this->assertFalse((bool) $this->privacy()['erase_inactive_donors']);
    }

    /**
     * Its only caller is the Receipts tab on Settings, which a donations
     * capability does not open, and it renders a made-up donor.
     */
    public function test_receipt_preview_follows_the_screen_it_lives_on(): void
    {
        Capabilities::applyMapping(['editor' => ['fundkit_manage_settings']]);
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        $this->assertSame(
            200,
            rest_do_request(new WP_REST_Request('GET', '/fundkit/v1/admin/receipts/preview'))->get_status()
        );

        Capabilities::applyMapping(['author' => ['fundkit_view_donations']]);
        wp_set_current_user(self::factory()->user->create(['role' => 'author']));
        $this->assertSame(
            403,
            rest_do_request(new WP_REST_Request('GET', '/fundkit/v1/admin/receipts/preview'))->get_status()
        );
    }

    public function test_the_rest_of_the_privacy_group_stays_delegatable(): void
    {
        $this->asSettingsManager();

        $res = $this->savePrivacy(['donor_retention_years' => 9]);

        $this->assertSame(200, $res->get_status(), (string) wp_json_encode($res->get_data()));
        $this->assertSame(9, (int) $this->privacy()['donor_retention_years']);
    }
}
