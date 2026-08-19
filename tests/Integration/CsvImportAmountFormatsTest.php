<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\Donation;
use Dono\Donors\Donor;
use Dono\Foundation\Identity\IdentityHasher;
use Dono\Foundation\Plugin;
use Dono\Foundation\Transfer\CsvImporter;

/**
 * How a written amount is read.
 *
 * A spreadsheet inserts a thousands separator from a thousand upward, so a
 * rule that takes the last separator as the decimal point imports the small
 * rows of a file correctly and silently divides the large ones by a thousand.
 * Nothing downstream can catch it: the row is valid, no warning is raised, and
 * the dry-run preview reports counts rather than amounts, so the admin has
 * nothing on screen that would show them 1,000 became 1.00.
 *
 * Amounts are stored as the major unit times one hundred whatever the currency
 * is, so the expectations here are that, not the currency's own minor units.
 */
final class CsvImportAmountFormatsTest extends IntegrationTestCase
{
    private const MAPPING = [
        'email'    => 'Email',
        'amount'   => 'Amount',
        'currency' => 'Currency',
        'date'     => 'Date',
    ];

    /**
     * @return array<string,array{0:string,1:string,2:int}>
     */
    public function amounts(): array
    {
        return [
            // The reported defect, and the shapes around it.
            'grouped thousand'        => ['$1,000', 'USD', 100000],
            'grouped ten thousand'    => ['10,000', 'USD', 1000000],
            'grouped million'         => ['1,000,000', 'USD', 100000000],
            'grouped with decimals'   => ['1,000.00', 'USD', 100000],
            'grouped with symbol'     => ['$1,234,567.89', 'USD', 123456789],

            // Both conventions for the decimal point still work.
            'us decimal'              => ['1,234.56', 'USD', 123456],
            'european decimal'        => ['1.234,56', 'EUR', 123456],
            'european one decimal'    => ['1,5', 'EUR', 150],
            'plain decimal'           => ['25.50', 'USD', 2550],
            'plain whole'             => ['25', 'USD', 2500],

            // A currency with no minor unit cannot be writing decimals, so a
            // three-digit tail is grouping.
            'zero decimal currency'   => ['5,000', 'JPY', 500000],
            // An exporter that writes two decimals whatever the currency is
            // still writing one hundred, not ten thousand.
            'zero decimal with cents' => ['100.00', 'JPY', 10000],

            // One with three of them can be, which is the only case where a
            // three-digit tail is a decimal point.
            'three decimal currency'  => ['1.234', 'KWD', 123],
        ];
    }

    /**
     * @dataProvider amounts
     */
    public function test_an_amount_is_read_the_way_it_is_written(string $cell, string $currency, int $expected): void
    {
        $email = 'amt' . md5($cell . $currency) . '@example.test';

        Plugin::instance()->container->get(CsvImporter::class)->import(
            "Email,Amount,Currency,Date\n\"{$email}\",\"{$cell}\",{$currency},2024-06-01\n",
            self::MAPPING,
            false
        );

        $hasher = Plugin::instance()->container->get(IdentityHasher::class);
        $donor  = Donor::query()->where('email_hash', $hasher->emailHash($email))->get();
        $this->assertNotNull($donor, "no donor imported for {$cell}");

        $donation = Donation::query()->where('donor_id', (int) $donor->id)->get();
        $this->assertNotNull($donation, "no donation imported for {$cell}");

        $this->assertSame(
            $expected,
            (int) $donation->amount_cents,
            "{$cell} in {$currency}"
        );
    }
}
