<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Campaigns\Campaign;
use Dono\Donations\Donation;
use Dono\Donors\DonorService;
use Dono\Foundation\Plugin;
use Dono\Settings\SettingsService;
use WP_REST_Request;

/**
 * Tools > Import is a settings writer, so everything that guards a settings
 * write has to hold there too: the base-currency lock, the per-group type
 * whitelist, and the dono.settings.updated broadcast the FX snapshot and the
 * campaign currency sync hang off.
 */
final class ToolsImportSettingsWriterTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        update_option('dono_currency_locale', [
            'default_currency'     => 'USD',
            'supported_currencies' => ['USD', 'EUR'],
        ], false);
    }

    private function liveDonation(): void
    {
        $d = Donation::make();
        $d->reference         = 'REF-' . uniqid();
        $d->status            = 'paid';
        $d->gateway           = 'offline';
        $d->kind              = 'donation';
        $d->amount_cents      = 10000;
        $d->base_amount_cents = 10000;
        $d->currency          = 'USD';
        $d->is_test           = false;
        $d->donor_id          = (int) Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate('import-lock-' . uniqid() . '@example.test')->id;
        $d->created_at        = gmdate('Y-m-d H:i:s');
        $d->paid_at           = gmdate('Y-m-d H:i:s');
        $d->save();
    }

    /** @param array<string,mixed> $body */
    private function post(array $body): \WP_REST_Response
    {
        $req = new WP_REST_Request('POST', '/dono/v1/admin/tools/import');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode($body));

        return rest_do_request($req);
    }

    private function base(): string
    {
        $opt = get_option('dono_currency_locale');

        return (string) (is_array($opt) ? ($opt['default_currency'] ?? '') : '');
    }

    public function test_an_imported_file_cannot_change_the_base_currency_after_live_money(): void
    {
        $this->liveDonation();

        $res = $this->post(['settings' => ['dono_currency_locale' => [
            'default_currency'     => 'EUR',
            'supported_currencies' => ['EUR'],
        ]]]);

        $this->assertSame(409, $res->get_status(), 'the import must refuse the write the settings screen refuses');
        $this->assertSame('dono_base_currency_locked', $res->as_error()->get_error_code());
        $this->assertSame('USD', $this->base(), 'the stored base is untouched');
    }

    public function test_a_refused_group_does_not_stop_the_rest_of_the_file(): void
    {
        $this->liveDonation();

        $res = $this->post(['settings' => [
            'dono_currency_locale' => ['default_currency' => 'EUR'],
            'dono_receipt_settings' => ['header_title' => 'Imported receipt'],
        ]]);

        $this->assertSame(409, $res->get_status());
        $this->assertSame('USD', $this->base());
        $this->assertSame(
            'Imported receipt',
            (string) (get_option('dono_receipt_settings')['header_title'] ?? ''),
            'the groups that were allowed still landed'
        );

        $data = $res->as_error()->get_error_data();
        $this->assertSame(['dono_currency_locale'], array_keys((array) ($data['refused'] ?? [])));
        $this->assertSame(1, (int) ($data['applied'] ?? 0));
    }

    public function test_a_scalar_settings_payload_cannot_blank_the_base_currency(): void
    {
        update_option('dono_currency_locale', [
            'default_currency'     => 'EUR',
            'supported_currencies' => ['EUR'],
        ], false);
        $this->liveDonation();

        // Every reader merges over the group defaults, so a scalar in the option
        // reads as default_currency USD: the same re-denomination by another
        // route.
        $res = $this->post(['settings' => ['dono_currency_locale' => 'EUR']]);

        $this->assertSame(422, $res->get_status());
        $this->assertSame(
            'EUR',
            Plugin::instance()->container->get(SettingsService::class)
                ->get('currency-locale')['default_currency'],
            'the base currency still reads EUR'
        );
    }

    public function test_an_allowed_base_currency_change_still_broadcasts(): void
    {
        $c = Campaign::make();
        $c->title    = 'Broadcast probe';
        $c->slug     = 'broadcast-probe-' . uniqid();
        $c->status   = 'active';
        $c->currency = 'USD';
        $c->save();

        $res = $this->post(['settings' => ['dono_currency_locale' => [
            'default_currency'     => 'EUR',
            'supported_currencies' => ['EUR'],
        ]]]);

        $this->assertSame(200, $res->get_status());
        $this->assertSame('EUR', $this->base());

        $fx = get_option('dono_fx_rates');
        $this->assertSame('EUR', (string) ($fx['base'] ?? ''), 'the FX snapshot follows the base');

        $this->assertSame(
            'EUR',
            (string) Campaign::query()->where('id', (int) $c->id)->get()->currency,
            'campaign currency stays in lockstep with the org base'
        );
    }

    public function test_an_undeclared_key_does_not_reach_the_stored_option(): void
    {
        $res = $this->post(['settings' => ['dono_privacy' => [
            'anonymize_ips' => false,
            'evil_key'      => 'planted',
        ]]]);

        $this->assertSame(200, $res->get_status());

        $privacy = get_option('dono_privacy');
        $this->assertArrayNotHasKey('evil_key', (array) $privacy);
        $this->assertFalse((bool) ($privacy['anonymize_ips'] ?? true));
    }

    public function test_a_role_mapping_import_replaces_wholesale_and_reaches_the_roles(): void
    {
        Plugin::instance()->container->get(SettingsService::class)
            ->update('roles', ['mapping' => ['author' => ['dono_view_donations']]]);
        $this->assertTrue(get_role('author')->has_cap('dono_view_donations'));

        $res = $this->post(['settings' => ['dono_roles' => [
            'mapping' => ['editor' => ['dono_view_donations']],
        ]]]);

        $this->assertSame(200, $res->get_status());
        $this->assertSame(
            ['editor' => ['dono_view_donations']],
            (array) (get_option('dono_roles')['mapping'] ?? []),
            'the mapping is replaced, not merged, or a role can never be taken off it'
        );
        $this->assertTrue(get_role('editor')->has_cap('dono_view_donations'));
        $this->assertFalse(get_role('author')->has_cap('dono_view_donations'));
    }

    public function test_an_option_no_group_declares_is_refused_rather_than_written(): void
    {
        add_filter('dono.settings.groups', static function (array $groups): array {
            unset($groups['consents']);

            return $groups;
        });

        $res = $this->post(['settings' => ['dono_consents' => ['purposes' => [['key' => 'planted']]]]]);

        $this->assertSame(422, $res->get_status());
        // The planted value, not the option's existence: the integration
        // database is shared, so asserting the option was never created only
        // holds until something else in the suite writes one.
        $stored = get_option('dono_consents');
        $keys   = array_column((array) (is_array($stored) ? ($stored['purposes'] ?? []) : []), 'key');
        $this->assertNotContains('planted', $keys, 'nothing is written past the writer');
    }

    /**
     * PayPal registers only once keys are connected and sandbox only while
     * org-wide test mode is on, so on the site a backup is restored onto,
     * neither need be registered. A key nothing declares is dropped, and
     * GatewayManager::isOn() reads a missing enabled flag as on, so an org's
     * decision to switch PayPal off came back switched on the moment they
     * re-entered their credentials. Credentials live in an encrypted setting
     * the export does not carry, so re-entry is the ordinary restore path.
     */
    public function test_a_restore_keeps_the_settings_of_a_gateway_this_site_has_not_registered(): void
    {
        // The registry is process-wide, and a PayPal suite earlier in the run
        // leaves it registered, which would hide the very drop under test.
        $this->deregisterGateway('paypal');
        $manager = Plugin::instance()->container->get(\Dono\Gateways\GatewayManager::class);
        $this->assertNull($manager->get('paypal'), 'unregistered here, as on a fresh restore target');

        $res = $this->post(['settings' => ['dono_gateway_config' => [
            'test_mode' => false,
            'paypal'    => ['enabled' => false],
            'offline'   => ['enabled' => true],
        ]]]);
        $this->assertSame(200, $res->get_status(), (string) wp_json_encode($res->get_data()));

        $stored = (array) get_option('dono_gateway_config', []);
        $this->assertSame(
            ['enabled' => false],
            $stored['paypal'] ?? null,
            'the org decided PayPal was off, and a restore has to say so'
        );
    }
}
