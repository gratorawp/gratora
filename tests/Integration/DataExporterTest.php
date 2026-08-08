<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donors\DonorService;
use Dono\Foundation\Plugin;
use Dono\Foundation\Transfer\DataExporter;

/**
 * An export is a file people email to support and commit to repositories, and
 * it is also the thing that has to restore on a different site. Those two pull
 * in opposite directions, so both are pinned here rather than left to review.
 */
final class DataExporterTest extends IntegrationTestCase
{
    private function exporter(): DataExporter
    {
        return Plugin::instance()->container->get(DataExporter::class);
    }

    /** @return array<string,mixed> */
    private function export(): array
    {
        $out = fopen('php://temp', 'r+');
        $this->exporter()->writeJson($out);
        rewind($out);
        $json = (string) stream_get_contents($out);
        fclose($out);

        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded, 'the export must be valid JSON: ' . json_last_error_msg());

        return $decoded;
    }

    private function seedDonor(string $email = 'exported@example.test'): int
    {
        $donor = Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate($email, ['first_name' => 'Ex', 'last_name' => 'Ported']);

        return (int) $donor->id;
    }

    /**
     * The whole point. Every _encrypted column is sealed with this install's
     * key, so shipping ciphertext would make a file only its own site could
     * read.
     */
    public function test_donor_pii_leaves_decrypted_so_another_site_can_read_it(): void
    {
        $this->seedDonor('plaintext@example.test');

        $donors = $this->export()['tables']['dono_donors'] ?? [];
        $match  = null;
        foreach ($donors as $d) {
            if (($d['email'] ?? '') === 'plaintext@example.test') $match = $d;
        }

        $this->assertNotNull($match, 'the donor should be in the export, by address');
        foreach (array_keys($match) as $column) {
            $this->assertStringEndsNotWith('_encrypted', (string) $column, 'no ciphertext may survive the export');
        }
    }

    /** Salted with this install's pepper, so it means nothing anywhere else. */
    public function test_the_email_hash_does_not_travel(): void
    {
        $this->seedDonor();

        foreach ($this->export()['tables']['dono_donors'] ?? [] as $d) {
            $this->assertArrayNotHasKey('email_hash', $d);
        }
    }

    /**
     * dono_system_settings holds encryption_key_v1, email_pepper_v1,
     * form_signing_secret_v1 and ip_salt_v1. If this ever passes by accident,
     * the export hands over the keys to every encrypted column in the database.
     */
    public function test_the_secrets_table_is_never_exported(): void
    {
        $tables = $this->export()['tables'] ?? [];

        $this->assertArrayNotHasKey('dono_system_settings', $tables);
        $this->assertNotContains('dono_system_settings', DataExporter::tables());
    }

    /** Live credentials: the file would let anyone sign in as any donor. */
    public function test_magic_link_tokens_are_never_exported(): void
    {
        $this->assertArrayNotHasKey('dono_magic_link_tokens', $this->export()['tables'] ?? []);
    }

    /** Raw gateway payloads carry other people's data and, on failures, secrets. */
    public function test_the_webhook_log_is_never_exported(): void
    {
        $this->assertArrayNotHasKey('dono_webhooks_log', $this->export()['tables'] ?? []);
    }

    public function test_gateway_secrets_are_redacted_in_settings(): void
    {
        update_option('dono_gateway_config', [
            'stripe' => ['webhook_secret_live' => 'whsec_this_must_not_travel'],
        ]);

        $settings = $this->export()['settings'] ?? [];

        $this->assertStringNotContainsString(
            'whsec_this_must_not_travel',
            (string) wp_json_encode($settings),
            'the webhook secret is the only authentication on that route'
        );

        delete_option('dono_gateway_config');
    }

    /** An add-on may add its own tables, but not reopen what SKIP closed. */
    public function test_an_add_on_cannot_add_back_a_skipped_table(): void
    {
        $sneak = static fn (array $t): array => array_merge($t, ['dono_system_settings', 'dono_tributes']);
        add_filter('dono.export.tables', $sneak);

        try {
            $tables = DataExporter::tables();
            $this->assertContains('dono_tributes', $tables, 'an add-on can contribute its own');
            $this->assertNotContains('dono_system_settings', $tables, 'but never a skipped one');
        } finally {
            remove_filter('dono.export.tables', $sneak);
        }
    }

    /** A donation must be restorable, which means its donor comes first. */
    public function test_tables_are_ordered_so_references_resolve(): void
    {
        $order = array_flip(DataExporter::tables());

        $this->assertLessThan($order['dono_donations'], $order['dono_donors']);
        $this->assertLessThan($order['dono_donations'], $order['dono_campaigns']);
        $this->assertLessThan($order['dono_receipts'], $order['dono_donations']);
    }

}
