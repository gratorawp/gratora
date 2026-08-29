<?php

declare(strict_types=1);

namespace GiveFlow\Tests\Integration;

use GiveFlow\Admin\SystemReport;
use GiveFlow\Foundation\Config\SystemSetting;
use GiveFlow\Foundation\Modules\ModuleManager;
use GiveFlow\Foundation\Plugin;
use GiveFlow\Gateways\GatewayManager;

/**
 * The report exists to be pasted into a support ticket, so the thing it must
 * never do is carry a credential. Everything sensitive is reported as whether it
 * is set, not as what it is.
 */
final class SystemReportTest extends IntegrationTestCase
{
    private function report(): SystemReport
    {
        $c = Plugin::instance()->container;

        return new SystemReport($c->get(ModuleManager::class), $c->get(GatewayManager::class));
    }

    /** Every value on the screen, flattened the way the copy button flattens it. */
    private function text(): string
    {
        $out = '';
        foreach ($this->report()->sections() as $section) {
            $out .= $section['title'] . "\n";
            foreach ($section['rows'] as $row) {
                $out .= $row['label'] . ': ' . $row['value'] . "\n";
            }
        }

        return $out;
    }

    public function test_the_encryption_key_is_reported_as_held_never_printed(): void
    {
        $secret = base64_encode(str_repeat('k', 32));
        SystemSetting::write('encryption_key_v1', $secret);

        $text = $this->text();

        $this->assertStringNotContainsString($secret, $text, 'the key itself must never reach the report');
        $this->assertStringNotContainsString(str_repeat('k', 32), $text);
        $this->assertStringContainsString('Encryption key', $text);
    }

    /**
     * A key that has gone missing makes every encrypted column unreadable, so
     * the report has to say so rather than leave support guessing.
     */
    public function test_a_lost_encryption_key_is_called_out(): void
    {
        SystemSetting::write('encryption_key_lost_at', '2026-08-29 10:00:00');

        $this->assertStringContainsString('Encryption key lost at', $this->text());
        SystemSetting::forget('encryption_key_lost_at');
    }

    public function test_gateways_report_their_state_and_no_credentials(): void
    {
        $payments = null;
        foreach ($this->report()->sections() as $section) {
            if ($section['title'] === 'Payments') {
                $payments = $section;
            }
        }

        $this->assertNotNull($payments);
        foreach ($payments['rows'] as $row) {
            $this->assertContains(
                $row['value'],
                ['ready', 'not configured', 'None registered'],
                'a gateway row may say whether it can charge, and nothing else'
            );
        }
    }

    public function test_the_report_carries_what_a_support_answer_needs(): void
    {
        $text = $this->text();

        foreach ([
            'PHP version',
            'Web server',
            'Table prefix',
            'Charset',
            'Multisite',
            'Timezone',
            'WP-Cron disabled',
            'Missing PHP extensions',
        ] as $needle) {
            $this->assertStringContainsString($needle, $text);
        }
    }

    /**
     * The plugin list is the first thing a conflict report turns on, and a row
     * count is how "the donations are gone" gets answered in one look.
     */
    public function test_it_lists_active_plugins_and_counts_the_tables(): void
    {
        global $wpdb;

        $sections = [];
        foreach ($this->report()->sections() as $section) {
            $sections[$section['title']] = $section['rows'];
        }

        $this->assertArrayHasKey('Active plugins', $sections);
        $this->assertNotSame([], $sections['Active plugins']);

        $database = '';
        foreach ($sections['Database'] as $row) {
            $database .= $row['label'] . ': ' . $row['value'] . "\n";
        }
        $this->assertStringContainsString($wpdb->prefix . 'giveflow_donations', $database);
        $this->assertStringContainsString('rows', $database);
    }
}
