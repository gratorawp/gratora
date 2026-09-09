<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Campaigns\CampaignService;
use Gratora\Forms\FormService;
use Gratora\Foundation\Plugin;

/**
 * The payment-gateways block is the single writer of
 * settings.gateways.allowed (optionsFor + submit validation read it).
 */
final class GatewayBlockBridgeTest extends IntegrationTestCase
{
    private function forms(): FormService
    {
        return Plugin::instance()->container->get(FormService::class);
    }

    private function campaignId(): int
    {
        return Plugin::instance()->container->get(CampaignService::class)
            ->create(['title' => 'Bridge campaign'])->id;
    }

    public function test_block_allowed_list_syncs_into_settings_on_create(): void
    {
        $form = $this->forms()->create([
            'title'       => 'Gw bridge',
            'campaign_id' => $this->campaignId(),
            'blocks'      => '<!-- wp:gratora/payment-gateways {"allowed":["offline","stripe"]} /-->',
        ]);

        $this->assertSame(['offline', 'stripe'], $form->settings['gateways']['allowed']);
    }

    public function test_nested_block_found_and_absent_block_leaves_settings_untouched(): void
    {
        $form = $this->forms()->create([
            'title'       => 'Nested gw',
            'campaign_id' => $this->campaignId(),
            'blocks'      => '<!-- wp:gratora/row --><!-- wp:gratora/payment-gateways {"allowed":["offline"]} /--><!-- /wp:gratora/row -->',
        ]);
        $this->assertSame(['offline'], $form->settings['gateways']['allowed']);

        // No block: the bridge does not touch the list, so the Settings tab
        // (or a prior value) keeps governing rather than being clobbered.
        $form = $this->forms()->update($form, ['blocks' => '<!-- wp:gratora/name /-->']);
        $this->assertSame(['offline'], $form->settings['gateways']['allowed']);
    }
}
