<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\Donation;
use Dono\Donors\Donor;
use Dono\Foundation\Helpers\View;

/**
 * The receipt renders in the donor's locale, so the recurring lede has to name
 * the frequency through a translated label rather than the stored slug.
 */
final class ReceiptFrequencyLabelTest extends IntegrationTestCase
{
    private function render(string $frequency): string
    {
        $now = gmdate('Y-m-d H:i:s');

        $donation = Donation::make();
        $donation->reference    = 'DONO-FREQ-' . strtoupper(bin2hex(random_bytes(3)));
        $donation->donor_id     = 0;
        $donation->amount_cents = 2500;
        $donation->net_cents    = 2500;
        $donation->currency     = 'USD';
        $donation->gateway      = 'offline';
        $donation->status       = 'paid';
        $donation->frequency    = $frequency;
        $donation->paid_at      = $now;

        return View::load('Receipts.generic', [
            'donation'         => $donation,
            'donor'            => Donor::make(),
            'donor_name'       => 'Rae New',
            'donor_address'    => '',
            'org'              => ['name' => 'Test Org', 'address_lines' => [], 'tax_id' => '', 'email' => ''],
            'locale'           => 'fr_FR',
            'extras'           => [],
            'amount_display'   => '25.00 USD',
            'receipt_number'   => 'REC-2026-00001',
            'receipt_template' => [],
            'refunded_cents'   => 0,
            'refunded_display' => '',
            'custom_data'      => [],
            'custom_field_labels' => [],
        ]);
    }

    public function test_the_recurring_lede_uses_a_translated_frequency(): void
    {
        // Stands in for a locale being active during the render, which is what
        // ReceiptIssuer switches to before calling the renderer.
        add_filter('gettext', static function ($translation, $text, $domain) {
            if ($domain === 'dono' && $text === 'Monthly') return 'Mensuel';
            return $translation;
        }, 10, 3);

        $html = $this->render('monthly');

        $this->assertStringContainsString('Mensuel', $html);
        $this->assertStringNotContainsString('Monthly', $html);
    }

    public function test_an_unmapped_frequency_still_reads_as_a_word(): void
    {
        $this->assertStringContainsString('Fortnightly', $this->render('fortnightly'));
    }
}
