<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Foundation\Plugin;
use Gratora\Settings\SecretRedactor;
use Gratora\Settings\SettingsService;
use WP_REST_Request;

/**
 * An export masks every secret it cannot carry. On import the mask means
 * "whatever this site already holds", and where this site holds nothing the
 * mask is not a secret: it is four characters that satisfy every "is it set"
 * check while failing every signature they are used for, so the readiness
 * screen reports a signed webhook and every delivery is rejected.
 */
final class SettingsImportSecretsTest extends IntegrationTestCase
{
    private const GATEWAYS = 'gratora_gateway_config';

    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        delete_option(self::GATEWAYS);
    }

    private function settings(): SettingsService
    {
        return Plugin::instance()->container->get(SettingsService::class);
    }

    private function import(array $settings): \WP_REST_Response
    {
        $req = new WP_REST_Request('POST', '/gratora/v1/admin/tools/import');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode(['settings' => $settings]));

        return rest_do_request($req);
    }

    private function stripe(): array
    {
        $g = (array) $this->settings()->get('gateways');

        return is_array($g['stripe'] ?? null) ? $g['stripe'] : [];
    }

    public function test_the_mask_never_lands_as_a_secret_on_a_site_that_has_none(): void
    {
        $res = $this->import([
            self::GATEWAYS => [
                'stripe' => [
                    'enabled'              => true,
                    'webhook_secret_live'  => SecretRedactor::MASK,
                ],
            ],
        ]);

        $this->assertLessThan(300, $res->get_status(), (string) wp_json_encode($res->get_data()));
        $this->assertNotSame(
            SecretRedactor::MASK,
            (string) ($this->stripe()['webhook_secret_live'] ?? ''),
            'the placeholder was stored as the signing secret'
        );
        $this->assertSame('', (string) ($this->stripe()['webhook_secret_live'] ?? ''), 'no secret is the truth here');
    }

    public function test_a_mask_still_keeps_the_secret_this_site_does_hold(): void
    {
        update_option(self::GATEWAYS, ['stripe' => ['enabled' => true, 'webhook_secret_live' => 'whsec_real']]);

        $this->import([
            self::GATEWAYS => ['stripe' => ['enabled' => true, 'webhook_secret_live' => SecretRedactor::MASK]],
        ]);

        $this->assertSame('whsec_real', (string) ($this->stripe()['webhook_secret_live'] ?? ''));
    }

    public function test_a_real_secret_in_the_file_still_replaces_what_is_stored(): void
    {
        update_option(self::GATEWAYS, ['stripe' => ['enabled' => true, 'webhook_secret_live' => 'whsec_old']]);

        $this->import([
            self::GATEWAYS => ['stripe' => ['enabled' => true, 'webhook_secret_live' => 'whsec_new']],
        ]);

        $this->assertSame('whsec_new', (string) ($this->stripe()['webhook_secret_live'] ?? ''));
    }

    public function test_the_redactor_alone_never_returns_the_mask(): void
    {
        // The invariant, independent of any caller: whatever goes in, the mask
        // does not come out.
        $out = SecretRedactor::restore(
            ['a' => SecretRedactor::MASK, 'nested' => ['b' => SecretRedactor::MASK]],
            []
        );

        $this->assertSame('', $out['a']);
        $this->assertSame(SecretRedactor::MASK, $out['nested']['b'] ?? null, 'a subtree with no counterpart is left alone');

        $deep = SecretRedactor::restore(
            ['nested' => ['b' => SecretRedactor::MASK]],
            ['nested' => []]
        );
        $this->assertSame('', $deep['nested']['b']);
    }
}
