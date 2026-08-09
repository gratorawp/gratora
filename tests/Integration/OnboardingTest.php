<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Campaigns\Campaign;
use Dono\Foundation\Plugin;
use Dono\Settings\SettingsService;
use WP_REST_Request;

/**
 * Finishing the wizard settles the organization and nothing else.
 *
 * It used to publish a campaign, which left every install with one whether or
 * not it was wanted. The last screen now links to the campaigns page with its
 * create drawer open, so the first campaign is built with the same form as
 * every other one.
 */
final class OnboardingTest extends IntegrationTestCase
{
    /** @param array<string,mixed> $body */
    private function finalize(array $body): \WP_REST_Response
    {
        $req = new WP_REST_Request('POST', '/dono/v1/admin/onboarding/finalize');
        $req->set_header('content-type', 'application/json');
        $req->set_body(json_encode($body));
        return rest_do_request($req);
    }

    private function testModeOn(): bool
    {
        return ! empty(
            Plugin::instance()->container->get(SettingsService::class)->get('gateways')['test_mode']
        );
    }

    public function test_finishing_the_wizard_publishes_no_campaign(): void
    {
        $res = $this->finalize(['user_type' => 'nonprofit']);

        $this->assertSame(200, $res->get_status());
        $this->assertTrue($res->get_data()['ok'] ?? false);
        $this->assertCount(0, Campaign::query()->getAll(), 'finishing setup must not publish anything');
    }

    public function test_just_exploring_turns_on_global_test_mode(): void
    {
        $this->assertFalse($this->testModeOn(), 'test mode off by default');

        $res = $this->finalize(['user_type' => 'exploring']);

        $this->assertSame(200, $res->get_status());
        $this->assertTrue($this->testModeOn(), 'exploring path enabled global test mode');
    }

    public function test_non_exploring_does_not_touch_test_mode(): void
    {
        $res = $this->finalize(['user_type' => 'nonprofit']);

        $this->assertSame(200, $res->get_status());
        $this->assertFalse($this->testModeOn(), 'non-exploring left test mode off');
    }

    public function test_finishing_marks_onboarding_complete(): void
    {
        $this->finalize(['user_type' => 'nonprofit']);

        $this->assertSame('completed', get_option('dono_onboarding_status'));
    }
}
