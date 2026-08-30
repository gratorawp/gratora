<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

final class MigrationsTest extends IntegrationTestCase
{
    public function test_all_10_core_tables_exist(): void
    {
        $expected = [
            'fundkit_donors', 'fundkit_consents', 'fundkit_magic_link_tokens',
            'fundkit_campaigns', 'fundkit_funds',
            'fundkit_donations', 'fundkit_recurring_plans', 'fundkit_receipts',
            'fundkit_forms', 'fundkit_events',
        ];

        foreach ($expected as $t) {
            $full = self::$prefix . $t;
            $found = (int) self::$wpdb->get_var(self::$wpdb->prepare(
                "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s",
                $full
            ));
            $this->assertSame(1, $found, "Missing table {$full}");
        }
    }

    public function test_donations_has_webhook_dedup_unique_indexes(): void
    {
        $tbl = self::$prefix . 'fundkit_donations';
        $indexes = self::$wpdb->get_results("SHOW INDEX FROM {$tbl}");

        $named = [];
        foreach ($indexes as $row) {
            $named[$row->Key_name][$row->Seq_in_index] = $row->Column_name;
        }

        $this->assertArrayHasKey('uk_gateway_gateway_intent_id', $named);
        $this->assertSame('gateway',           $named['uk_gateway_gateway_intent_id'][1]);
        $this->assertSame('gateway_intent_id', $named['uk_gateway_gateway_intent_id'][2]);

        $this->assertArrayHasKey('uk_gateway_gateway_txn_id', $named);
    }

    public function test_events_table_has_funnel_indexes(): void
    {
        $tbl = self::$prefix . 'fundkit_events';
        $indexes = self::$wpdb->get_results("SHOW INDEX FROM {$tbl}");

        $names = array_unique(array_map(fn ($r) => $r->Key_name, $indexes));
        $this->assertContains('idx_type_occurred_at', $names);
        $this->assertContains('idx_form_id_type_occurred_at', $names);
        $this->assertContains('idx_donation_id_occurred_at', $names);
    }
}
