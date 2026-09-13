<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Foundation\Plugin;
use Gratora\Foundation\Crypto\Crypto;
use Gratora\Foundation\Transfer\DataExporter;
use Gratora\Settings\SecretRedactor;
use Gratora\Settings\SettingsService;
use WP_REST_Request;

/**
 * Which settings an export carries was a literal list of ten options, written
 * out twice, in the controller and in the exporter, while the registry they
 * mirror is SettingsService::groups() and takes whatever an add-on registers.
 *
 * So Gift Aid and conversion tracking registered groups that appeared in
 * neither file, and an export offered as the way to lift a configured site
 * onto another install left them behind. The records half of the same exporter
 * already asks add-ons for their tables and says in its docblock why.
 *
 * Both halves now read the registry, which is also the only way the two copies
 * can stop disagreeing with each other.
 */
final class AnAddOnsSettingsTravelTest extends IntegrationTestCase
{
    private const OPTION = 'gratora_test_addon_settings';

    /** @var list<callable> */
    private array $filters = [];

    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    protected function tearDown(): void
    {
        foreach ($this->filters as $f) {
            remove_filter('gratora.settings.groups', $f, 10);
        }
        $this->filters = [];
        delete_option(self::OPTION);

        parent::tearDown();
    }

    /** An add-on registering its own settings group, as the real ones do. */
    private function addOnGroup(array $defaults = ['enabled' => false]): void
    {
        $register = static function (array $groups) use ($defaults): array {
            $groups['test_addon'] = ['option' => self::OPTION, 'defaults' => $defaults];

            return $groups;
        };

        $this->filters[] = $register;
        add_filter('gratora.settings.groups', $register, 10);
    }

    /**
     * Built fresh rather than resolved. The container memoises, and the group
     * registry memoises within an instance, which is right for a request and
     * wrong for a suite where one process runs every test.
     */
    private function exporter(): DataExporter
    {
        return new DataExporter(
            Plugin::instance()->container->get(Crypto::class),
            new SettingsService()
        );
    }

    /** @return array<string,mixed> the settings half of the full export */
    private function fullExportSettings(): array
    {
        $out = fopen('php://temp', 'r+');
        $this->exporter()->writeJson($out);
        rewind($out);
        $json = (string) stream_get_contents($out);
        fclose($out);

        $decoded = json_decode($json, true);

        return is_array($decoded['settings'] ?? null) ? $decoded['settings'] : [];
    }

    /** @return array<string,mixed> the settings half of the settings-only export */
    private function settingsExport(): array
    {
        $res = rest_do_request(new WP_REST_Request('GET', '/gratora/v1/admin/tools/export'));
        $this->assertSame(200, $res->get_status(), (string) wp_json_encode($res->get_data()));

        return (array) ((array) $res->get_data())['settings'];
    }

    private function import(array $settings): \WP_REST_Response
    {
        $req = new WP_REST_Request('POST', '/gratora/v1/admin/tools/import');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode(['settings' => $settings]));

        return rest_do_request($req);
    }

    public function test_a_group_an_add_on_registers_is_in_the_settings_export(): void
    {
        $this->addOnGroup();
        update_option(self::OPTION, ['enabled' => true, 'charity_reference' => 'AB12345']);

        $this->assertArrayHasKey(self::OPTION, $this->settingsExport());
    }

    public function test_a_group_an_add_on_registers_is_in_the_full_export(): void
    {
        $this->addOnGroup();
        update_option(self::OPTION, ['enabled' => true]);

        $this->assertArrayHasKey(self::OPTION, $this->fullExportSettings());
    }

    /**
     * The two export paths carry the same settings. Written out by hand twice
     * they could not, and nothing said so.
     */
    public function test_both_exports_carry_the_same_settings(): void
    {
        $this->addOnGroup();
        update_option(self::OPTION, ['enabled' => true]);

        $one = array_keys($this->settingsExport());
        $two = array_keys($this->fullExportSettings());

        sort($one);
        sort($two);

        $this->assertSame($one, $two);
    }

    public function test_an_add_ons_settings_land_on_import(): void
    {
        $this->addOnGroup();
        delete_option(self::OPTION);

        $res = $this->import([self::OPTION => ['enabled' => true]]);

        $this->assertSame(200, $res->get_status(), (string) wp_json_encode($res->get_data()));
        $this->assertTrue((bool) (get_option(self::OPTION)['enabled'] ?? false));
    }

    /**
     * Widening what travels must not widen what leaks. The redactor works on
     * the shape of a key rather than on a list of groups, so it reaches an
     * add-on's secret without knowing the add-on exists.
     */
    public function test_a_secret_in_an_add_ons_group_is_masked_on_the_way_out(): void
    {
        $this->addOnGroup(['api_key' => '', 'enabled' => false]);
        update_option(self::OPTION, ['api_key' => 'sk_live_do_not_export', 'enabled' => true]);

        foreach ([$this->settingsExport(), $this->fullExportSettings()] as $exported) {
            $this->assertSame(SecretRedactor::MASK, $exported[self::OPTION]['api_key']);
            $this->assertStringNotContainsString(
                'sk_live_do_not_export',
                (string) wp_json_encode($exported)
            );
        }
    }

    /** And importing that mask back must not overwrite the real key with it. */
    public function test_importing_a_masked_secret_leaves_the_stored_one_alone(): void
    {
        $this->addOnGroup(['api_key' => '', 'enabled' => false]);
        update_option(self::OPTION, ['api_key' => 'sk_live_keep_me', 'enabled' => true]);

        $this->import([self::OPTION => ['api_key' => SecretRedactor::MASK, 'enabled' => false]]);

        $this->assertSame('sk_live_keep_me', (string) get_option(self::OPTION)['api_key']);
    }

    /**
     * A file from a site with an add-on this one does not have. Dropping those
     * settings without a word is how an admin comes to believe a restore was
     * complete.
     */
    public function test_settings_for_a_group_this_site_does_not_have_are_refused(): void
    {
        $res = $this->import(['gratora_absent_addon' => ['enabled' => true]]);

        $this->assertSame(422, $res->get_status());
        $this->assertNotSame('', trim((string) ((array) $res->get_data())['message']));
    }

    /**
     * Core's own groups still travel. Written first, because an export carries
     * what a site has saved and skips a group nobody has touched.
     */
    public function test_core_settings_still_travel(): void
    {
        $core = ['gratora_org_profile', 'gratora_gateway_config', 'gratora_email_settings', 'gratora_reference_settings'];

        foreach ($core as $option) {
            update_option($option, ['exported' => true]);
        }

        $exported = $this->settingsExport();

        foreach ($core as $option) {
            $this->assertArrayHasKey($option, $exported, $option . ' is core and has to be in an export');
        }
    }

    /** Every group the registry declares is offered, core and add-on alike. */
    public function test_the_export_asks_the_registry_what_to_carry(): void
    {
        $this->addOnGroup();

        $names = (new SettingsService())->optionNames();

        $this->assertContains(self::OPTION, $names, 'a registered add-on group');
        $this->assertContains('gratora_gateway_config', $names, 'and core is not dropped to make room');
        $this->assertSame($names, array_unique($names), 'two groups naming one option carry it once');
    }
}
