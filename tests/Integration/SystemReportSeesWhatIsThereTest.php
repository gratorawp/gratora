<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Admin\SystemReport;
use Gratora\Foundation\Modules\ModuleManager;
use Gratora\Foundation\Plugin;
use Gratora\Foundation\Uninstall\DataEraser;
use Gratora\Gateways\GatewayManager;

/**
 * Two things this report is pasted into a ticket to answer, and could not.
 *
 * It reported "Add-ons / Installed: None" on a site carrying nine of them
 * switched off, while listing, two cards below, a scheduled job belonging to
 * one of the add-ons it had just said were not there. A deactivated add-on is
 * usually the answer to the ticket.
 *
 * And the Database section, whose whole point is that a missing table is
 * invisible everywhere else, checked eight of the sixteen tables core creates.
 */
final class SystemReportSeesWhatIsThereTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        wp_cache_delete('plugins', 'plugins');

        parent::tearDown();
    }

    private function report(): SystemReport
    {
        $c = Plugin::instance()->container;

        return new SystemReport($c->get(ModuleManager::class), $c->get(GatewayManager::class));
    }

    /** @return array<string, list<array{label:string, value:string}>> */
    private function sections(): array
    {
        $out = [];
        foreach ($this->report()->sections() as $section) {
            $out[$section['title']] = $section['rows'];
        }

        return $out;
    }

    private function sectionText(string $title): string
    {
        $out = '';
        foreach ($this->sections()[$title] ?? [] as $row) {
            $out .= $row['label'] . ': ' . $row['value'] . "\n";
        }

        return $out;
    }

    /**
     * What get_plugins() finds on disk, primed through the cache it reads, so
     * the report can be asked about a plugin this checkout does not carry.
     *
     * Core is always in the list: it shares the text domain prefix with its
     * add-ons and the report has to tell itself apart from them.
     *
     * @param array<string, array<string,string>> $plugins file => headers
     */
    private function onDisk(array $plugins): void
    {
        // What get_plugins() returns for a plugin inside the plugins directory.
        // plugin_basename() is no good here: this checkout is outside the test
        // install's plugin directory, so it answers with an absolute path.
        $core = basename(dirname(GRATORA_FILE)) . '/' . basename(GRATORA_FILE);

        $all = [$core => [
            'Name'       => 'Gratora - Donation Platform',
            'Version'    => defined('GRATORA_VERSION') ? GRATORA_VERSION : '1.0.0',
            'TextDomain' => 'gratora-donation-platform',
        ]];

        foreach ($plugins as $file => $headers) {
            $all[$file] = $headers + ['Name' => 'Unnamed', 'Version' => '', 'TextDomain' => ''];
        }

        wp_cache_set('plugins', ['' => $all], 'plugins');
    }

    public function test_an_add_on_on_disk_and_switched_off_is_reported(): void
    {
        $this->onDisk(['gratora-events/gratora-events.php' => [
            'Name'       => 'Gratora Event Tickets',
            'Version'    => '1.0.3',
            'TextDomain' => 'gratora-events',
        ]]);

        $text = $this->sectionText('Add-ons');

        $this->assertStringContainsString('Gratora Event Tickets', $text);
        $this->assertStringContainsString('1.0.3', $text);
        $this->assertStringNotContainsString('None', $text, 'it is installed, so the report cannot say nothing is');
    }

    /** Installed and switched off are different states and must read differently. */
    public function test_a_dormant_add_on_is_not_reported_as_running(): void
    {
        $this->onDisk(['gratora-tributes/gratora-tributes.php' => [
            'Name'       => 'Gratora Tributes',
            'Version'    => '1.0.0',
            'TextDomain' => 'gratora-tributes',
        ]]);

        $row = null;
        foreach ($this->sections()['Add-ons'] as $candidate) {
            if ($candidate['label'] === 'Gratora Tributes') {
                $row = $candidate;
                break;
            }
        }

        $this->assertNotNull($row);
        $this->assertNotSame('1.0.0', $row['value'], 'a bare version is how a running add-on is reported');
        $this->assertMatchesRegularExpression('/off|inactive|not active/i', $row['value']);
    }

    public function test_a_plugin_that_is_not_a_gratora_add_on_is_left_out(): void
    {
        $this->onDisk(['akismet/akismet.php' => [
            'Name'       => 'Akismet Anti-spam',
            'Version'    => '5.3',
            'TextDomain' => 'akismet',
        ]]);

        $this->assertStringNotContainsString('Akismet', $this->sectionText('Add-ons'));
    }

    /** Core shares the text domain prefix and is not one of its own add-ons. */
    public function test_core_is_not_listed_as_a_dormant_add_on(): void
    {
        $this->onDisk([]);

        $this->assertStringNotContainsString(
            'Donation Platform',
            $this->sectionText('Add-ons')
        );
    }

    /**
     * With nothing to report, the row may not claim to have looked at
     * installation and found none, which is what it used to answer.
     */
    public function test_with_no_add_ons_the_row_does_not_answer_for_installation(): void
    {
        $this->onDisk([]);

        $rows = $this->sections()['Add-ons'];

        $this->assertCount(1, $rows, 'nothing is registered and nothing is on disk');
        $this->assertSame('None', $rows[0]['value']);
        $this->assertNotSame('Installed', $rows[0]['label'], 'it looked at what is loaded, not at what is installed');
    }

    /**
     * Every table core migrates gets a row, derived from the migrations rather
     * than from a list kept beside the report, so a table added to the schema
     * cannot go unchecked.
     */
    public function test_every_table_core_creates_is_in_the_database_section(): void
    {
        global $wpdb;

        $text   = $this->sectionText('Database');
        $tables = (new DataEraser())->coreTables();

        $this->assertGreaterThan(8, count($tables), 'core creates more than the eight that were listed');

        foreach ($tables as $base) {
            $this->assertStringContainsString(
                $wpdb->prefix . $base,
                $text,
                $base . ' is a table core creates and the report does not mention it'
            );
        }
    }

    /**
     * The two worth naming outright. Without the first every encrypted column
     * is unreadable, and the second is where this plugin writes the errors the
     * same screen exists to show.
     */
    public function test_the_tables_a_site_dies_without_are_reported(): void
    {
        global $wpdb;

        $text = $this->sectionText('Database');

        $this->assertStringContainsString($wpdb->prefix . 'gratora_system_settings', $text);
        $this->assertStringContainsString($wpdb->prefix . 'gratora_events', $text);
    }

    /** A table that is there is counted, not reported as gone. */
    public function test_a_table_that_exists_reports_its_rows(): void
    {
        global $wpdb;

        foreach ($this->sections()['Database'] as $row) {
            if ($row['label'] === $wpdb->prefix . 'gratora_donations') {
                $this->assertStringContainsString('rows', $row['value']);
                $this->assertStringNotContainsString('MISSING', $row['value']);

                return;
            }
        }

        $this->fail('the donations table is not in the section');
    }
}
