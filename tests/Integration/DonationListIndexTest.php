<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

/**
 * The admin donations list sorts by created_at and always filters is_test.
 *
 * Every composite on the table ended in paid_at, so nothing served that ORDER
 * BY: EXPLAIN on the default view read `key=idx_is_test, Extra: Using
 * filesort`, meaning MySQL read every live donation and sorted it to return 25.
 */
final class DonationListIndexTest extends IntegrationTestCase
{
    /** @return list<string> index names covering exactly these columns, in order */
    private function indexesOver(array $columns): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'dono_donations';
        $rows  = $wpdb->get_results("SHOW INDEX FROM `{$table}`");

        $byName = [];
        foreach ($rows as $row) {
            $byName[$row->Key_name][(int) $row->Seq_in_index] = $row->Column_name;
        }

        $found = [];
        foreach ($byName as $name => $cols) {
            ksort($cols);
            if (array_values($cols) === $columns) {
                $found[] = (string) $name;
            }
        }

        return $found;
    }

    public function test_the_default_list_order_is_indexed(): void
    {
        $this->assertNotEmpty(
            $this->indexesOver(['is_test', 'created_at']),
            'WHERE is_test = 0 ORDER BY created_at needs an index leading with both'
        );
    }

    public function test_the_status_filtered_list_order_is_indexed(): void
    {
        $this->assertNotEmpty(
            $this->indexesOver(['is_test', 'status', 'created_at']),
            'the status filter is the common variant and cannot use the two-column index'
        );
    }

    public function test_the_default_list_query_does_not_filesort(): void
    {
        global $wpdb;

        $table = $wpdb->prefix . 'dono_donations';
        $plan  = $wpdb->get_row(
            "EXPLAIN SELECT * FROM `{$table}` WHERE is_test = 0 ORDER BY created_at DESC LIMIT 25"
        );

        $this->assertNotNull($plan);
        $this->assertStringNotContainsString(
            'filesort',
            strtolower((string) ($plan->Extra ?? '')),
            'the list is index-ordered rather than sorting the whole live set'
        );
    }
}
