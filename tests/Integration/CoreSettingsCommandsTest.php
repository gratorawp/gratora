<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Analytics\EventRecorder;
use Dono\Core\Commands\CoreCommandProvider;
use Dono\Foundation\Commands\CommandContext;
use Dono\Foundation\Commands\CommandRegistry;
use Dono\Foundation\Plugin;
use Dono\Settings\SettingsService;

/**
 * settings.get / settings.update let the assistant read and write benign org
 * settings. Security-critical: only an allowlist of groups is reachable, secret
 * keys are redacted on read and refused on write, and roles + gateways are
 * never in the enum (privilege escalation and Stripe secrets stay human-only).
 */
final class CoreSettingsCommandsTest extends IntegrationTestCase
{
    private function registry(): CommandRegistry
    {
        $c = Plugin::instance()->container;
        $r = new CommandRegistry($c->get(EventRecorder::class));
        (new CoreCommandProvider())->register($r, $c);
        return $r;
    }

    private function adminCtx(): CommandContext
    {
        $admin = self::factory()->user->create(['role' => 'administrator']);
        get_role('administrator')->add_cap('dono_manage_settings');
        wp_set_current_user($admin);
        return new CommandContext($admin, 'rest', 'req-' . uniqid());
    }

    private function settings(): SettingsService
    {
        return Plugin::instance()->container->get(SettingsService::class);
    }

    public function test_manifest_lists_both_settings_commands_with_correct_flags(): void
    {
        $byId = [];
        foreach ($this->registry()->manifest() as $entry) {
            $byId[$entry['id']] = $entry;
        }

        $this->assertArrayHasKey('settings.get', $byId, 'manifest missing settings.get');
        $this->assertArrayHasKey('settings.update', $byId, 'manifest missing settings.update');

        $this->assertFalse($byId['settings.get']['mutating'], 'settings.get must be non-mutating');
        $this->assertTrue($byId['settings.get']['idempotent'], 'settings.get must be idempotent');

        $this->assertTrue($byId['settings.update']['mutating'], 'settings.update must be mutating');
        $this->assertFalse($byId['settings.update']['idempotent'], 'settings.update must not be idempotent');

        foreach (['settings.get', 'settings.update'] as $id) {
            $this->assertSame('dono_manage_settings', $byId[$id]['capability'], "{$id} must be gated on dono_manage_settings");
        }
    }

    public function test_settings_get_returns_a_values_array_for_an_allowlisted_group(): void
    {
        $ctx = $this->adminCtx();

        $res = $this->registry()->dispatch('settings.get', ['group' => 'org-profile'], $ctx);

        $this->assertTrue($res->ok, $res->error ?? '');
        $this->assertSame('org-profile', $res->data['group']);
        $this->assertIsArray($res->data['values']);
        $this->assertArrayHasKey('name', $res->data['values'], 'org-profile carries the organisation name key');
    }

    public function test_settings_update_persists_a_real_key(): void
    {
        $ctx = $this->adminCtx();

        // `name` is the org-profile organisation-name key (SettingsService GROUPS).
        $res = $this->registry()->dispatch('settings.update', [
            'group'  => 'org-profile',
            'values' => ['name' => 'Hope Foundation'],
        ], $ctx);

        $this->assertTrue($res->ok, $res->error ?? '');
        $this->assertSame('Hope Foundation', $res->data['values']['name']);
        $this->assertSame('Hope Foundation', $this->settings()->get('org-profile')['name'], 'the write must reach SettingsService');
    }

    public function test_settings_update_changes_the_receipt_footer(): void
    {
        $ctx = $this->adminCtx();

        // "change the receipt footer" - footer_note is the receipts footer key.
        $res = $this->registry()->dispatch('settings.update', [
            'group'  => 'receipts',
            'values' => ['footer_note' => 'Registered charity no. 12345.'],
        ], $ctx);

        $this->assertTrue($res->ok, $res->error ?? '');
        $this->assertSame('Registered charity no. 12345.', $this->settings()->get('receipts')['footer_note']);
    }

    public function test_settings_update_rejects_a_fantasy_key(): void
    {
        $ctx = $this->adminCtx();

        $res = $this->registry()->dispatch('settings.update', [
            'group'  => 'org-profile',
            'values' => ['not_a_real_key' => 'x'],
        ], $ctx);

        $this->assertFalse($res->ok, 'an invented key must be rejected, never silently stored');
        $this->assertSame('command.failed', $res->error_code);
        $this->assertStringContainsString('not_a_real_key', (string) $res->error);
        $this->assertArrayNotHasKey('not_a_real_key', $this->settings()->get('org-profile'), 'the fantasy key must not be stored');
    }

    public function test_settings_update_refuses_a_secret_shaped_key(): void
    {
        $ctx = $this->adminCtx();

        // Even if a secret-shaped key were somehow a group key, writing it is refused.
        $res = $this->registry()->dispatch('settings.update', [
            'group'  => 'email',
            'values' => ['webhook_secret' => 'whsec_nope'],
        ], $ctx);

        $this->assertFalse($res->ok, 'a secret-shaped key must never be writable through the assistant');
        $this->assertSame('command.failed', $res->error_code);
    }

    public function test_excluded_groups_are_not_in_the_enum(): void
    {
        $ctx = $this->adminCtx();

        // gateways holds Stripe secrets + bank details; roles is a privilege
        // surface. Both (and advanced/privacy/telemetry) are outside the enum,
        // so the schema rejects them before the handler ever runs.
        foreach (['gateways', 'roles', 'advanced', 'privacy', 'telemetry'] as $group) {
            $get = $this->registry()->dispatch('settings.get', ['group' => $group], $ctx);
            $this->assertFalse($get->ok, "settings.get must reject excluded group {$group}");
            $this->assertSame('command.invalid_input', $get->error_code, "excluded group {$group} must fail as invalid_input");

            $set = $this->registry()->dispatch('settings.update', ['group' => $group, 'values' => ['x' => 1]], $ctx);
            $this->assertFalse($set->ok, "settings.update must reject excluded group {$group}");
            $this->assertSame('command.invalid_input', $set->error_code);
        }
    }

    public function test_settings_get_redacts_secret_shaped_values_at_any_depth(): void
    {
        $ctx = $this->adminCtx();

        // No allowlisted group ships a secret, so inject secret-shaped keys into
        // an allowed group's option to prove the redaction walk (top-level +
        // nested). SettingsService::get merges the stored option over defaults.
        update_option('dono_org_profile', [
            'name'    => 'Hope Foundation',
            'api_key' => 'sk_live_should_not_leak',
            'nested'  => ['webhook_secret' => 'whsec_should_not_leak', 'city' => 'Berlin'],
        ]);

        $res = $this->registry()->dispatch('settings.get', ['group' => 'org-profile'], $ctx);

        $this->assertTrue($res->ok, $res->error ?? '');
        $this->assertSame('***', $res->data['values']['api_key'], 'top-level secret redacted');
        $this->assertSame('***', $res->data['values']['nested']['webhook_secret'], 'nested secret redacted');
        $this->assertSame('Berlin', $res->data['values']['nested']['city'], 'non-secret siblings survive');
        $this->assertSame('Hope Foundation', $res->data['values']['name'], 'plain values are untouched');
        $this->assertStringNotContainsString('should_not_leak', (string) json_encode($res->data['values']), 'no secret value leaks anywhere');
    }

    public function test_settings_command_denied_without_capability(): void
    {
        $subscriber = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber);
        $ctx = new CommandContext($subscriber, 'rest', 'req-' . uniqid());

        $res = $this->registry()->dispatch('settings.get', ['group' => 'org-profile'], $ctx);

        $this->assertFalse($res->ok);
        $this->assertSame('command.denied', $res->error_code);
    }
}
