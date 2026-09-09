<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Foundation\Plugin;
use Gratora\Gateways\GatewayManager;
use Gratora\Settings\SettingsService;
use ReflectionProperty;

/**
 * Turning a payment method off without disconnecting it.
 *
 * The flag itself already worked; what did not was saving it. SettingsService
 * drops top-level keys the group does not declare, and a gateway id is only
 * knowable at runtime, so PayPal's and any add-on's would have been rejected
 * in silence: a switch that flipped in the UI and was gone on reload.
 */
final class GatewayEnableTest extends IntegrationTestCase
{
    private function settings(): SettingsService
    {
        return Plugin::instance()->container->get(SettingsService::class);
    }

    private function gateways(): GatewayManager
    {
        return Plugin::instance()->container->get(GatewayManager::class);
    }

    protected function tearDown(): void
    {
        delete_option('gratora_gateway_config');
        parent::tearDown();
    }

    /** Re-resolve the schema because tests can register gateways after bootstrap. */
    private function forgetGroupSchema(): void
    {
        $prop = new ReflectionProperty(SettingsService::class, 'groupsCache');
        $prop->setAccessible(true);
        $prop->setValue($this->settings(), null);
    }

    public function test_a_gateway_is_on_by_default(): void
    {
        $this->assertTrue($this->gateways()->isOn('offline'));
    }

    public function test_every_registered_gateway_survives_a_save(): void
    {
        $this->forgetGroupSchema();

        foreach (array_keys($this->gateways()->all()) as $id) {
            $this->settings()->update('gateways', [$id => ['enabled' => false]]);

            $stored = (array) get_option('gratora_gateway_config', []);
            $this->assertFalse(
                $stored[$id]['enabled'] ?? null,
                "{$id} did not persist: the gateways group does not declare its key"
            );
            $this->assertFalse($this->gateways()->isOn($id), "{$id} still reports as on");
        }
    }

    public function test_the_registry_declares_a_flag_for_each_gateway(): void
    {
        $groups = $this->gateways()->declareSettings([
            'gateways' => ['option' => 'gratora_gateway_config', 'defaults' => []],
        ]);

        foreach (array_keys($this->gateways()->all()) as $id) {
            $this->assertTrue($groups['gateways']['defaults'][$id]['enabled'] ?? null);
        }
    }

    /** A gateway that declares its own defaults must not lose them. */
    public function test_declaring_does_not_clobber_existing_defaults(): void
    {
        $id = array_key_first($this->gateways()->all());
        $this->assertNotNull($id, 'precondition: a gateway is registered');

        $groups = $this->gateways()->declareSettings([
            'gateways' => [
                'option'   => 'gratora_gateway_config',
                'defaults' => [$id => ['some_existing_default' => 'keep-me']],
            ],
        ]);

        $this->assertSame('keep-me', $groups['gateways']['defaults'][$id]['some_existing_default']);
        $this->assertTrue($groups['gateways']['defaults'][$id]['enabled']);
    }

    public function test_a_disabled_gateway_is_not_offered(): void
    {
        $this->forgetGroupSchema();
        $this->settings()->update('gateways', ['offline' => ['enabled' => false]]);

        $this->assertNotContains('offline', array_column($this->gateways()->optionsMetaFor([]), 'id'));
    }
}
