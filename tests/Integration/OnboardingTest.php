<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Campaigns\Campaign;
use Dono\Foundation\Plugin;
use Dono\Settings\SettingsService;
use WP_REST_Request;

final class OnboardingTest extends IntegrationTestCase
{
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

    public function test_just_exploring_turns_on_global_test_mode(): void
    {
        $this->assertFalse($this->testModeOn(), 'test mode off by default');

        $res = $this->finalize([
            'campaign_title' => 'Explorer campaign',
            'user_type'      => 'exploring',
        ]);

        $this->assertSame(200, $res->get_status());
        $this->assertTrue($res->get_data()['ok'] ?? false);
        $this->assertTrue($this->testModeOn(), 'exploring path enabled global test mode');
    }

    public function test_non_exploring_does_not_touch_test_mode(): void
    {
        $res = $this->finalize([
            'campaign_title' => 'Real campaign',
            'user_type'      => 'nonprofit',
        ]);

        $this->assertSame(200, $res->get_status());
        $this->assertFalse($this->testModeOn(), 'non-exploring left test mode off');
    }

    public function test_target_goal_is_written_to_the_campaign(): void
    {
        $res = $this->finalize([
            'campaign_title' => 'Goal campaign',
            'goal_mode'      => 'target',
            'goal_amount'    => 25000,
        ]);

        $this->assertSame(200, $res->get_status());
        $campaign = Campaign::query()->find('id', (int) $res->get_data()['campaign_id']);
        $this->assertNotNull($campaign);
        $this->assertSame(2500000, (int) $campaign->goal_cents, 'major amount stored as minor units');
    }

    public function test_ongoing_goal_leaves_the_campaign_without_a_target(): void
    {
        $res = $this->finalize([
            'campaign_title' => 'Ongoing campaign',
            'goal_mode'      => 'ongoing',
            'goal_amount'    => 0,
        ]);

        $this->assertSame(200, $res->get_status());
        $campaign = Campaign::query()->find('id', (int) $res->get_data()['campaign_id']);
        $this->assertNotNull($campaign);
        $this->assertNull($campaign->goal_cents, 'ongoing collection has no goal');
    }

    public function test_finalize_is_idempotent_across_reruns(): void
    {
        $first = $this->finalize(['campaign_title' => 'First run']);
        $this->assertSame(200, $first->get_status());
        $firstId = (int) $first->get_data()['campaign_id'];

        $second = $this->finalize(['campaign_title' => 'Second run']);
        $this->assertSame(200, $second->get_status());

        $this->assertSame(
            $firstId,
            (int) $second->get_data()['campaign_id'],
            're-running the wizard returns the existing campaign, not a duplicate'
        );
        $this->assertCount(1, Campaign::query()->getAll(), 'no duplicate campaign was published');
    }
}
