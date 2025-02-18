<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\Donation;
use Dono\Gateways\Sandbox\SandboxGateway;
use WP_REST_Request;

/**
 * Regression for QA bug #3: the donation form runtime only calls
 * POST /donations (createIntent) and never POST /donations/{ref}/confirm.
 * For Stripe that's correct - the webhook confirms async. For the sandbox
 * gateway it left every test donation at status=pending forever, with the
 * donor seeing a misleading "Thank you!" screen.
 *
 * Fix: GatewayIntentResult::auto_confirm; sandbox returns true; the donations
 * controller calls gateway->confirm() + donations->confirm() in the same
 * request when set. This test posts a sandbox donation via REST and asserts
 * the response (and DB) show status=paid before the response returns.
 */
final class SandboxAutoConfirmTest extends IntegrationTestCase
{
    public function test_sandbox_gateway_advertises_auto_confirm(): void
    {
        // The flag is the contract between the gateway and the controller.
        // If a future change drops it, the symptom is silent (pending donations),
        // so lock it in directly.
        $clock  = \Dono\Foundation\Plugin::instance()->container->get(\Dono\Foundation\Time\Clock::class);
        $donation = \Dono\Donations\Donation::make();
        $donation->reference = 'SANDBOX-TEST';
        $intent = (new SandboxGateway($clock))->createIntent($donation);
        $this->assertTrue(
            $intent->auto_confirm,
            'sandbox createIntent must set auto_confirm=true so the controller fires confirm in the same request'
        );
    }

    public function test_sandbox_donation_via_rest_lands_as_paid(): void
    {
        // Org-wide test mode must be on for the sandbox gateway to register.
        update_option('dono_gateway_config', [
            'test_mode' => true,
            'sandbox'   => ['enabled' => true],
        ]);

        // Boot ran (and read test_mode) before this option was set, so the
        // sandbox gateway isn't registered yet; register it now so the REST
        // create can resolve gateway=sandbox.
        $container = \Dono\Foundation\Plugin::instance()->container;
        $manager   = $container->get(\Dono\Gateways\GatewayManager::class);
        if (! $manager->get('sandbox')) {
            $manager->register(new SandboxGateway($container->get(\Dono\Foundation\Time\Clock::class)));
        }

        $campaignId = $this->seedCampaign();

        $res = $this->postJson('/dono/v1/donations', [
            'campaign_id'  => $campaignId,
            'gateway'      => 'sandbox',
            'amount_cents' => 1500,
            'currency'     => 'EUR',
            'email'        => 'sandbox-auto-' . uniqid() . '@dono.test',
            'profile'      => ['first_name' => 'Sandy', 'last_name' => 'Auto'],
        ]);
        $this->assertSame(201, $res->get_status(), 'donation create returns 201');

        $body = $res->get_data();
        $this->assertSame(
            'paid',
            $body['status'] ?? '',
            "sandbox donation should auto-confirm to 'paid' in the same request, got '" . ($body['status'] ?? 'null') . "'"
        );
        $this->assertMatchesRegularExpression('/^DONO-/', (string) ($body['reference'] ?? ''));

        // DB row reflects the same.
        $row = Donation::query()->where('reference', $body['reference'])->get();
        $this->assertNotNull($row, 'donation row persisted');
        $this->assertSame('paid', $row->status, 'DB row is paid, not pending');
        $this->assertSame(1500, (int) $row->amount_cents);
    }

    private function seedCampaign(): int
    {
        $service = \Dono\Foundation\Plugin::instance()->container->get(\Dono\Campaigns\CampaignService::class);
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
