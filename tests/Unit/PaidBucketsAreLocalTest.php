<?php

declare(strict_types=1);

namespace Dono\Tests\Unit;

use Dono\Donations\DonationQueries;
use PHPUnit\Framework\TestCase;
use TypeError;

/**
 * Every day, weekday and hour bucket on paid_at goes through the two helpers
 * that put it on the org's clock.
 *
 * The windows these reports run over are already the org's calendar days, so a
 * bucket left on the UTC clock files a donation outside the window its own
 * money is counted in, and two screens then disagree about one donation. Each
 * bucketing's answer is pinned in PaidBucketTimezoneTest; this reads the source
 * instead, because a bucketing added later arrives with no test of its own and
 * would go back to UTC unnoticed.
 */
final class PaidBucketsAreLocalTest extends TestCase
{
    /**
     * A MySQL date part read straight off the column. MIN/MAX are absent on
     * purpose: they return the stamp itself, and their callers convert it.
     */
    private const RAW_BUCKET = '/\b(?:DATE|DATE_FORMAT|DAYOFWEEK|DAYOFYEAR|DAYOFMONTH|WEEK|WEEKDAY|MONTH|YEAR|HOUR|MINUTE)\s*\(\s*[^()]*paid_at/i';

    /** The qualified column, wherever it is named. */
    private const COLUMN = '/\S*dono_donations\.paid_at/';

    /** Naming it is only allowed as the thing being converted. */
    private const CONVERTED = '/DonationQueries::local(?:Date|Stamp)Expr\(\s*[^,]*dono_donations\.paid_at/';

    /** @return array<string, array{0:string}> */
    public function reportingSources(): array
    {
        return [
            'donation repository' => ['src/Donations/DonationRepository.php'],
            'dashboard metrics'   => ['src/Dashboard/DashboardMetricsService.php'],
            'campaign metrics'    => ['src/Campaigns/CampaignMetricsService.php'],
            'revenue exporter'    => ['src/Exports/RevenueExporter.php'],
        ];
    }

    /** @dataProvider reportingSources */
    public function test_no_bucket_reads_paid_at_on_the_utc_clock(string $relative): void
    {
        preg_match_all(self::RAW_BUCKET, $this->source($relative), $found);

        $this->assertSame(
            [],
            $found[0],
            $relative . ' buckets paid_at as stored: convert it through localPaidDayExpr or localPaidStampExpr'
        );
    }

    /**
     * The variant the regex above cannot see: the column put in a variable
     * first, which is how three of these bucketings were written.
     *
     * @dataProvider reportingSources
     */
    public function test_the_column_is_only_ever_named_to_convert_it(string $relative): void
    {
        $source = $this->source($relative);

        $this->assertSame(
            preg_match_all(self::COLUMN, $source),
            preg_match_all(self::CONVERTED, $source),
            $relative . ' names paid_at somewhere other than a conversion: a bucket built on that expression reads UTC'
        );
    }

    /**
     * The window is what carries the offset, so a conversion asked for without
     * one hands back the bare column: SQL the call site reads as local and the
     * database reads as UTC. The two checks above cannot see that, because such
     * a bucketing does name the column to a converter. The signature is what
     * stops it, and this is what stops the signature loosening again.
     *
     * @dataProvider windowlessCalls
     */
    public function test_a_conversion_cannot_be_asked_for_without_a_window(callable $call): void
    {
        $this->expectException(TypeError::class);

        $call();
    }

    /** @return array<string, array{0:callable}> */
    public function windowlessCalls(): array
    {
        return [
            'day bucket'   => [static fn () => DonationQueries::localDateExpr('paid_at', null, null)],
            'stamp bucket' => [static fn () => DonationQueries::localStampExpr('paid_at', null, null)],
        ];
    }

    /** Code only: a comment is free to name the column it is explaining. */
    private function source(string $relative): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relative;
        $this->assertFileExists($path);

        $lines = preg_split('/\R/', (string) file_get_contents($path)) ?: [];

        return implode("\n", array_filter(
            $lines,
            static fn ($line) => preg_match('#^\s*(?://|\*|/\*|\#)#', $line) !== 1
        ));
    }
}
