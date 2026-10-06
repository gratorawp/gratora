<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Campaigns\Campaign;
use Gratora\Foundation\Plugin;
use Gratora\Settings\SettingsService;
use WP_REST_Request;

final class OnboardingTest extends IntegrationTestCase
{
    /** @param array<string,mixed> $body */
    private function finalize(array $body): \WP_REST_Response
    {
        $req = new WP_REST_Request('POST', '/gratora/v1/admin/onboarding/finalize');
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

    /** @dataProvider answersAndModes */
    public function test_finishing_changes_test_mode_for_no_answer(string $answer, bool $before): void
    {
        Plugin::instance()->container->get(SettingsService::class)
            ->update('gateways', ['test_mode' => $before]);

        $res = $this->finalize(['user_type' => $answer]);

        $this->assertSame(200, $res->get_status());
        $this->assertSame($before, $this->testModeOn());
    }

    /** @return array<string,array{string,bool}> */
    public static function answersAndModes(): array
    {
        return [
            'just exploring, test mode off' => ['exploring', false],
            'just exploring, test mode on'  => ['exploring', true],
            'a nonprofit, test mode off'    => ['nonprofit', false],
            'a nonprofit, test mode on'     => ['nonprofit', true],
        ];
    }

    /**
     * The wizard can be reopened from Tools long after the site went live.
     * Re-running it stopped a live site taking money, silently, on the strength
     * of an answer given about a site that no longer exists.
     */
    public function test_running_the_wizard_again_does_not_stop_a_live_site(): void
    {
        $this->finalize(['user_type' => 'exploring']);

        Plugin::instance()->container->get(SettingsService::class)
            ->update('gateways', ['test_mode' => false]);

        $res = $this->finalize(['user_type' => 'exploring']);

        $this->assertSame(200, $res->get_status());
        $this->assertFalse($this->testModeOn(), 'a second run left the live site alone');
    }

    public function test_finishing_marks_onboarding_complete(): void
    {
        $this->finalize(['user_type' => 'nonprofit']);

        $this->assertSame('completed', get_option('gratora_onboarding_status'));
    }
}
