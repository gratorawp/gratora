<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Currency\FxBackfill;
use Dono\Donations\Donation;
use Dono\Vendor\Queryable\Model;
use Dono\Vendor\Queryable\Schema\Table;
use ReflectionProperty;

/**
 * FxBackfill::pending() reads "base_amount_cents IS NULL" on every load of
 * Tools > Maintenance and on the daily rate job. With no index on the column
 * that predicate is a full table scan, so proving a healthy site has nothing
 * stranded costs a walk of the whole donations table, and the healthy case is
 * the one that runs forever.
 *
 * Read off the declared schema rather than off the live table. dbDelta never
 * drops an index, so a table this suite has already migrated keeps one that
 * the model no longer declares, and a check against SHOW INDEX would go on
 * passing for a fresh install that never gets it.
 */
final class DonationBaseAmountIndexTest extends IntegrationTestCase
{
    /** The CREATE TABLE the model would build on a site installing today. */
    private static function declaredSchema(): string
    {
        $prop = new ReflectionProperty(Model::class, 'schemas');
        $prop->setAccessible(true);
        /** @var array<class-string,callable> $schemas */
        $schemas = $prop->getValue();

        $table = new Table('utf8mb4', 'utf8mb4_unicode_ci', []);
        $schemas[Donation::class]($table);

        return $table->compile('dono_donations');
    }

    public function test_the_column_leads_an_index_a_fresh_install_would_get(): void
    {
        $this->assertMatchesRegularExpression(
            '/KEY\s+\S*\s*\(base_amount_cents[,)]/i',
            self::declaredSchema(),
            'base_amount_cents needs an index it leads, not a trailing column of a composite'
        );
    }

    /** And that the migration this suite ran actually put it on the table. */
    public function test_the_migrated_table_carries_it(): void
    {
        $table   = self::$prefix . 'dono_donations';
        $indexed = self::$wpdb->get_col(
            self::$wpdb->prepare(
                'SHOW INDEX FROM `' . $table . '` WHERE Seq_in_index = 1 AND Column_name = %s',
                'base_amount_cents'
            )
        );

        $this->assertNotEmpty($indexed, 'the declared index reached the table');
    }

    /**
     * The predicate the index exists for still returns what it did. An empty
     * result on a site with nothing stranded is the answer the Tools screen
     * reads, and it is the one the scan was being paid for.
     */
    public function test_pending_still_answers_correctly_through_the_index(): void
    {
        $this->assertSame([], FxBackfill::pending());

        $donation = Donation::make();
        $donation->reference         = 'idx-' . uniqid();
        $donation->amount_cents      = 5000;
        $donation->base_amount_cents = null;
        $donation->currency          = 'SEK';
        $donation->status            = 'paid';
        $donation->is_test           = false;
        $donation->save();

        $pending = FxBackfill::pending();
        $this->assertCount(1, $pending);
        $this->assertSame('SEK', $pending[0]['currency']);
        $this->assertSame(5000, $pending[0]['amount_cents']);
    }
}
