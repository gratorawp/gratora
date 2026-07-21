<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Settings\SettingsService;

/**
 * groups() memoises per instance (one resolve per request in production). The
 * test constructs a fresh SettingsService after attaching the filter so the
 * memo reflects it; that exercises groups()/get()/update() honouring the
 * filter directly, which is the E2 unit of behaviour.
 */
final class SettingsGroupsFilterTest extends IntegrationTestCase
{
    public function test_pro_group_via_filter_is_served_and_writable(): void
    {
        delete_option('dono_pro_p2p_test');
        add_filter('dono.settings.groups', static function (array $g): array {
            return $g + ['p2p' => ['option' => 'dono_pro_p2p_test', 'defaults' => ['goal_cents' => 0]]];
        });

        $fired = [];
        add_action('dono.settings.updated', static function (string $group, array $next) use (&$fired): void {
            $fired[] = [$group, $next];
        }, 10, 2);

        $s = new SettingsService();

        $this->assertTrue($s->knows('p2p'));
        $this->assertSame(['goal_cents' => 0], $s->get('p2p'));

        $result = $s->update('p2p', ['goal_cents' => 500]);
        $this->assertSame(500, $result['goal_cents']);
        $this->assertSame(500, get_option('dono_pro_p2p_test')['goal_cents']);
        $this->assertNotEmpty($fired);
        $this->assertSame('p2p', $fired[count($fired) - 1][0]);

        // Built-in group still reads/writes identically (regression).
        $this->assertTrue($s->knows('email'));
        $this->assertNotEmpty($s->get('email'));

        remove_all_filters('dono.settings.groups');
        remove_all_actions('dono.settings.updated');
    }

    /**
     * An add-on registering its own email template via the filter must not
     * displace the core template set. resolveDynamicDefaults used to inject
     * core templates only when the group's templates were empty, so any add-on
     * that pre-populated them silently dropped every core transactional email
     * (receipts, magic link). Merge, do not skip.
     */
    public function test_addon_email_template_does_not_displace_core_templates(): void
    {
        add_filter('dono.settings.groups', static function (array $g): array {
            $g['email']['defaults']['templates']['dono_addon_welcome'] = [
                'enabled' => true,
                'subject' => 'Add-on welcome',
                'body'    => 'Hello from the add-on.',
            ];
            return $g;
        });

        $templates = (new SettingsService())->get('email')['templates'] ?? [];

        $this->assertArrayHasKey('dono_addon_welcome', $templates, 'the add-on template is present');
        $this->assertArrayHasKey('magic_link', $templates, 'core magic-link survives an add-on template');
        $this->assertArrayHasKey('donation_receipt', $templates, 'core receipt survives an add-on template');
        $this->assertTrue((bool) ($templates['magic_link']['enabled'] ?? false), 'core magic-link stays enabled');

        remove_all_filters('dono.settings.groups');
    }
}
