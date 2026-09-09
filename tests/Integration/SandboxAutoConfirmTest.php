<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Donations\Donation;
use Gratora\Gateways\Sandbox\SandboxGateway;
use WP_REST_Request;

final class SandboxAutoConfirmTest extends IntegrationTestCase
{
    public function test_sandbox_gateway_advertises_auto_confirm(): void
    {
        // The flag is the contract between the gateway and the controller.
        // If a future change drops it, the symptom is silent (pending donations),
        // so lock it in directly.
        $clock  = \Gratora\Foundation\Plugin::instance()->container->get(\Gratora\Foundation\Time\Clock::class);
        $donation = \Gratora\Donations\Donation::make();
        $donation->reference = 'SANDBOX-TEST';
        $intent = (new SandboxGateway($clock, new \Gratora\Recurring\RecurringPlanRepository()))->createIntent($donation);
        $this->assertTrue(
            $intent->auto_confirm,
            'sandbox createIntent must set auto_confirm=true so the controller fires confirm in the same request'
        );
    }

    public function test_sandbox_donation_via_rest_lands_as_paid(): void
    {
        // Org-wide test mode must be on for the sandbox gateway to register.
        update_option('gratora_gateway_config', [
            'test_mode' => true,
            'sandbox'   => ['enabled' => true],
        ]);

        // Register sandbox explicitly because bootstrap ran before test_mode was enabled.
        $container = \Gratora\Foundation\Plugin::instance()->container;
        $manager   = $container->get(\Gratora\Gateways\GatewayManager::class);
        if (! $manager->get('sandbox')) {
            $manager->register(new SandboxGateway(
                $container->get(\Gratora\Foundation\Time\Clock::class),
                $container->get(\Gratora\Recurring\RecurringPlanRepository::class)
            ));
        }

        $campaignId = $this->seedCampaign();

        $res = $this->postJson('/gratora/v1/donations', [
            'campaign_id'  => $campaignId,
            'gateway'      => 'sandbox',
            'amount_cents' => 1500,
            'currency'     => 'EUR',
            'email'        => 'sandbox-auto-' . uniqid() . '@gratora.test',
            'profile'      => ['first_name' => 'Sandy', 'last_name' => 'Auto'],
        ]);
        $this->assertSame(201, $res->get_status(), 'donation create returns 201');

        $body = $res->get_data();
        $this->assertSame(
            'paid',
            $body['status'] ?? '',
            "sandbox donation should auto-confirm to 'paid' in the same request, got '" . ($body['status'] ?? 'null') . "'"
        );
        // A rehearsal numbers from its own counter, so the reference says on
        // its face that it is one and the live sequence is left whole: the
        // test-data purge deletes these rows, and a number it took from the
        // live ledger would leave a hole nobody could account for.
        $this->assertMatchesRegularExpression('/^TEST_DONATION-/', (string) ($body['reference'] ?? ''));

        // DB row reflects the same.
        $row = Donation::query()->where('reference', $body['reference'])->get();
        $this->assertNotNull($row, 'donation row persisted');
        $this->assertSame('paid', $row->status, 'DB row is paid, not pending');
        $this->assertSame(1500, (int) $row->amount_cents);
    }

    private function seedCampaign(): int
    {
        $service = \Gratora\Foundation\Plugin::instance()->container->get(\Gratora\Campaigns\CampaignService::class);
        $campaign = $service->create([
            'title'      => 'Sandbox AutoConfirm Test',
            'goal_type'  => 'amount',
            'goal_cents' => 100000,
            'currency'   => 'EUR',
        ]);
        $service->update($campaign, ['status' => 'published']);
        return (int) $campaign->id;
    }

    /** @param array<string,mixed> $body */
    private function postJson(string $path, array $body): \WP_REST_Response
    {
        $req = new WP_REST_Request('POST', $path);
        $req->set_header('content-type', 'application/json');
        $req->set_body(json_encode($body));
        return rest_do_request($req);
    }
}
