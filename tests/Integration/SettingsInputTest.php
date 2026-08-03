<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Settings\SettingsService;

/**
 * update() used to merge whatever arrived straight into the option: no
 * whitelist, no types. A mistyped key persisted forever as a setting nothing
 * reads, and a string landed where an int was expected, so a retention window
 * could be stored as "" and every comparison against it quietly became zero.
 */
final class SettingsInputTest extends IntegrationTestCase
{
    private function service(): SettingsService
    {
        return new SettingsService();
    }

    public function test_a_key_the_group_does_not_declare_is_not_stored(): void
    {
        $after = $this->service()->update('privacy', ['donor_retention_yearz' => 5]);

        $this->assertArrayNotHasKey('donor_retention_yearz', $after);
    }

    public function test_a_rejected_key_is_announced_rather_than_dropped_in_silence(): void
    {
        $seen = [];
        add_action('dono.settings.rejected', static function ($group, $keys) use (&$seen): void {
            $seen = array_merge($seen, $keys);
        }, 10, 2);

        $this->service()->update('privacy', ['not_a_setting' => 1]);

        $this->assertContains('not_a_setting', $seen, 'a silently dropped key is how a setting stops saving unnoticed');
    }

    public function test_values_are_stored_at_the_type_the_group_declares(): void
    {
        $after = $this->service()->update('privacy', [
            'retention_days_after_redaction' => '45',
            'anonymize_ips'                  => 'yes',
        ]);

        $this->assertSame(45, $after['retention_days_after_redaction']);
        $this->assertTrue($after['anonymize_ips']);
    }

    public function test_a_shape_mismatch_is_refused_rather_than_coerced(): void
    {
        // Casting an array to a string produces nonsense that then looks like a
        // saved setting.
        $before = $this->service()->get('privacy')['privacy_policy_url'];

        $after = $this->service()->update('privacy', ['privacy_policy_url' => ['nope']]);

        $this->assertSame($before, $after['privacy_policy_url']);
    }

    public function test_a_null_default_declares_no_type_and_passes_through(): void
    {
        // A null default means "nothing yet", not "string". Coercing against it
        // turns a stored timestamp into "1735689600". Registered through the
        // add-on filter because no core group declares one, and an add-on is
        // exactly who would.
        $register = static function (array $groups): array {
            $groups['test_null_default'] = [
                'option'   => 'dono_test_null_default',
                'defaults' => ['seen_at' => null],
            ];
            return $groups;
        };
        add_filter('dono.settings.groups', $register);

        try {
            $after = $this->service()->update('test_null_default', ['seen_at' => 1735689600]);
            $this->assertSame(1735689600, $after['seen_at']);
        } finally {
            remove_filter('dono.settings.groups', $register);
            delete_option('dono_test_null_default');
        }
    }

    public function test_every_group_round_trips_its_own_values_unchanged(): void
    {
        $svc = $this->service();

        foreach (array_keys($svc->groups()) as $group) {
            $before = $svc->get($group);
            $after  = $svc->update($group, $before);

            $this->assertSame(
                $before,
                array_intersect_key($after, $before),
                "saving {$group} unchanged must change nothing"
            );
        }
    }

    public function test_a_map_with_keys_core_cannot_know_survives(): void
    {
        // roles.mapping is role => capabilities and numbering.prefixes is
        // scope => prefix. Whitelisting inside them would throw away exactly
        // the data the screen edits.
        $after = $this->service()->update('numbering', [
            'prefixes' => ['donation' => 'ZZZ', 'receipt' => 'YYY', 'refund' => 'XXX'],
        ]);

        $this->assertSame('ZZZ', $after['prefixes']['donation']);
        $this->assertSame('YYY', $after['prefixes']['receipt']);
    }
}
