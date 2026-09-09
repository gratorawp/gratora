<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Exports\DonorExporter;
use Gratora\Foundation\Plugin;

/**
 * Every other export in the plugin bounds itself: donations at 50000 with a
 * truncation header, at-risk donors at 10000, revenue at 240 months. This one
 * buffered the whole file in memory with no row cap, decrypting three PII
 * columns per row, so a large org got a 500 or a truncated download with
 * nothing explaining it.
 */
final class DonorExportIsCappedTest extends IntegrationTestCase
{
    private function seedDonors(int $count): void
    {
        global $wpdb;

        $now    = gmdate('Y-m-d H:i:s');
        $tuples = [];
        for ($i = 0; $i < $count; $i++) {
            $tuples[] = $wpdb->prepare('(%s, %s, %s, %s)', 'cap-' . $i . '-' . uniqid(), 'x', $now, $now);
        }

        $wpdb->query(
            'INSERT INTO ' . $wpdb->prefix . 'gratora_donors (email_hash, email_encrypted, created_at, updated_at) VALUES '
            . implode(',', $tuples)
        );
    }

    private function dataRows(string $csv): int
    {
        $lines = array_values(array_filter(explode("\n", trim($csv))));

        return max(0, count($lines) - 1);
    }

    public function test_the_export_stops_at_the_cap(): void
    {
        $this->seedDonors(12);

        $cap = static fn (): int => 5;
        add_filter('gratora.export.donors_max_rows', $cap);

        try {
            $csv = Plugin::instance()->container->get(DonorExporter::class)->toCsv([]);
        } finally {
            remove_filter('gratora.export.donors_max_rows', $cap);
        }

        $this->assertSame(5, $this->dataRows($csv));
    }

    /** Below the cap nothing changes, or the cap would be buying its cheapness. */
    public function test_a_small_export_is_whole(): void
    {
        $this->seedDonors(3);

        $csv = Plugin::instance()->container->get(DonorExporter::class)->toCsv([]);

        $this->assertSame(3, $this->dataRows($csv));
    }

    /** The donations export's own cap must not reach a PII export. */
    public function test_it_does_not_answer_to_the_donations_cap(): void
    {
        $this->seedDonors(4);

        $raise = static fn (): int => 1;
        add_filter('gratora.export.max_rows', $raise);

        try {
            $csv = Plugin::instance()->container->get(DonorExporter::class)->toCsv([]);
        } finally {
            remove_filter('gratora.export.max_rows', $raise);
        }

        $this->assertSame(4, $this->dataRows($csv));
    }
}
