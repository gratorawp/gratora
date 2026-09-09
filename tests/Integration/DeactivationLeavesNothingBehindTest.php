<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Foundation\Plugin;
use Gratora\Foundation\Upgrade\UnautoloadGatewayConfig;

/**
 * What a deactivated plugin leaves in the options table keeps acting on the
 * site. Two of them did: a rewrite rule that captured every URL under
 * /campaigns/, and a plaintext webhook signing secret in the blob WordPress
 * unserialises on every front-end request.
 */
final class DeactivationLeavesNothingBehindTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        delete_option('gratora_gateway_config');
        parent::tearDown();
    }

    /**
     * Flushing while the plugin is still loaded re-stores its own rule, and
     * CampaignPermalinks::addRule has already run on init by then.
     */
    public function test_the_campaign_rewrite_rule_does_not_outlive_the_plugin(): void
    {
        update_option('rewrite_rules', ['^campaigns/([^/]+)/?$' => 'index.php?pagename=$matches[1]']);

        Plugin::onDeactivation();

        $this->assertFalse(get_option('rewrite_rules'), 'WordPress rebuilds it on the next permalink request');
    }

    /**
     * The secret is the only authentication on the public webhook route, and
     * the alloptions blob is handed to anything that asks for it.
     */
    public function test_the_gateway_config_is_taken_out_of_the_autoloaded_set(): void
    {
        update_option('gratora_gateway_config', ['stripe' => ['webhook_secret_live' => 'whsec_x']], true);
        wp_cache_delete('alloptions', 'options');
        $this->assertArrayHasKey('gratora_gateway_config', wp_load_alloptions(), 'precondition: it is autoloaded');

        (new UnautoloadGatewayConfig())->step();

        wp_cache_delete('alloptions', 'options');
        $this->assertArrayNotHasKey('gratora_gateway_config', wp_load_alloptions());
        $this->assertSame(
            'whsec_x',
            get_option('gratora_gateway_config')['stripe']['webhook_secret_live'],
            'and the value is still there'
        );
    }

    public function test_the_repair_is_harmless_on_a_site_that_never_stored_one(): void
    {
        delete_option('gratora_gateway_config');

        $this->assertTrue((new UnautoloadGatewayConfig())->step());
        $this->assertFalse(get_option('gratora_gateway_config', false));
    }
}
