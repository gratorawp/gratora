<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\Donation;
use Dono\Donors\DonorService;
use Dono\Foundation\Plugin;
use Dono\Reports\TaxStatementBuilder;

/**
 * summary() is the tax-relevant figure a caller states without opening the PDF,
 * so it has to say which currency the figure is in, and it has to decline to
 * name one when the year spans several: no integer is that year's total in USD
 * and in EUR at once.
 *
 * @since 1.0.0
 */
final class TaxStatementCurrencyTest extends IntegrationTestCase
{
    private const YEAR = 2024;

    public function test_a_single_currency_year_reports_the_currency_it_is_in(): void
    {
        $donorId = $this->seedDonor('eur.only@example.com');
        $this->seedPaidDonation($donorId, 10_000, 'EUR', '-02-11 09:00:00');

        $summary = $this->builder()->summary($donorId, self::YEAR);

        $this->assertSame(1, $summary['donation_count']);
        $this->assertSame(10_000, $summary['total_cents']);
        $this->assertSame('EUR', $summary['currency']);
        $this->assertSame(['EUR' => 10_000], $summary['totals_by_currency']);
    }

    public function test_a_year_across_currencies_reports_no_single_total(): void
    {
        $donorId = $this->seedDonor('mixed.currency@example.com');
        $this->seedPaidDonation($donorId, 10_000, 'USD', '-03-04 09:00:00');
        $this->seedPaidDonation($donorId, 10_000, 'EUR', '-06-15 10:00:00');

        $summary = $this->builder()->summary($donorId, self::YEAR);

        $this->assertSame(2, $summary['donation_count']);
        $this->assertNull($summary['total_cents'], 'USD 100 plus EUR 100 is not 200 of any currency.');
        $this->assertSame('', $summary['currency']);
        $this->assertSame(10_000, $summary['totals_by_currency']['USD'] ?? null);
        $this->assertSame(10_000, $summary['totals_by_currency']['EUR'] ?? null);
    }

    // --- helpers ---------------------------------------------------------

    private function builder(): TaxStatementBuilder
    {
        return Plugin::instance()->container->get(TaxStatementBuilder::class);
    }

    private function seedDonor(string $email): int
    {
        return (int) Plugin::instance()->container->get(DonorService::class)->findOrCreate($email, [
            'first_name' => 'Jane',
            'last_name'  => 'Donor',
        ])->id;
    }

    /** @param string $dayAndTime Suffix of a date inside self::YEAR, e.g. '-03-04 09:00:00'. */
    private function seedPaidDonation(int $donorId, int $amountCents, string $currency, string $dayAndTime): void
    {
        $at = self::YEAR . $dayAndTime;

        $don = Donation::make();
        $don->reference    = 'DN-' . $currency . '-' . strtoupper(uniqid());
        $don->donor_id     = $donorId;
        $don->amount_cents = $amountCents;
        $don->net_cents    = $amountCents;
        $don->currency     = $currency;
        $don->gateway      = 'offline';
        $don->status       = 'paid';
        $don->is_test      = false;
        $don->paid_at      = $at;
        $don->created_at   = $at;
        $don->updated_at   = $at;
        $don->save();
    }
}
