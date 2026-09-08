<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Foundation\Crypto\Crypto;
use FundKit\Foundation\Identity\IdentityHasher;
use FundKit\Foundation\Plugin;
use FundKit\Foundation\Transfer\DataImporter;
use FundKit\Foundation\Upgrade\OpenTheDefaultFund;
use FundKit\Funds\Fund;
use FundKit\Funds\FundRepository;
use FundKit\Vendor\Queryable\DB;

/**
 * A restore writes fund rows column for column, past the service that holds the
 * default-fund rules. A file taken off a site that predates them can carry a
 * default with a schedule, and a default whose code differs from this site's
 * lands beside the existing one rather than replacing it: two rows flagged, and
 * whichever the database returns first decides where untagged money goes.
 */
final class ImportedDefaultFundTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::table('fundkit_funds')->where('id', 0, '>')->delete();
    }

    public function test_an_imported_default_arrives_as_an_ordinary_fund_when_this_site_has_one(): void
    {
        $ours = $this->fund('general', 'General', ['is_default' => 1]);

        $this->import([
            'code'       => 'building',
            'name'       => 'Building fund',
            'is_default' => 1,
            'is_active'  => 1,
            'ends_at'    => '2026-12-31 00:00:00',
        ]);

        $landed = Fund::query()->where('code', 'building')->get();
        $this->assertNotNull($landed, 'the fund still arrives');
        $this->assertFalse((bool) $landed->is_default, 'but it does not claim the flag this site already gave out');
        $this->assertNotNull($landed->ends_at, 'and as an ordinary fund it keeps its schedule');

        $this->assertSame(
            (int) $ours->id,
            (int) Plugin::instance()->container->get(FundRepository::class)->default()->id
        );
        $this->assertSame(1, $this->defaultCount());
    }

    public function test_an_imported_default_lands_open_when_this_site_has_none(): void
    {
        $this->import([
            'code'       => 'general',
            'name'       => 'General',
            'is_default' => 1,
            'is_active'  => 0,
            'starts_at'  => '2026-01-01 00:00:00',
            'ends_at'    => '2026-12-31 00:00:00',
        ]);

        $landed = Fund::query()->where('code', 'general')->get();
        $this->assertTrue((bool) $landed->is_default);
        $this->assertTrue((bool) $landed->is_active, 'a default nobody can donate to is not a default');
        $this->assertNull($landed->starts_at);
        $this->assertNull($landed->ends_at);
        $this->assertTrue($landed->isOpen());
    }

    public function test_a_scheduled_fund_that_is_not_the_default_is_untouched(): void
    {
        $this->import([
            'code'      => 'winter',
            'name'      => 'Winter appeal',
            'is_active' => 1,
            'starts_at' => '2026-11-01 00:00:00',
            'ends_at'   => '2026-12-31 00:00:00',
        ]);

        $landed = Fund::query()->where('code', 'winter')->get();
        $this->assertFalse((bool) $landed->is_default);
        $this->assertSame('2026-11-01 00:00:00', $landed->starts_at);
        $this->assertSame('2026-12-31 00:00:00', $landed->ends_at);
    }

    /**
     * The repair has to cope with what a restore can leave behind, not only
     * with what an older editor could write.
     */
    public function test_the_upgrade_settles_a_site_left_with_two_defaults(): void
    {
        $first  = $this->fund('general', 'General', [
            'is_default' => 1,
            'is_active'  => 0,
            'ends_at'    => '2020-01-01 00:00:00',
        ]);
        $second = $this->fund('building', 'Building', ['is_default' => 1]);

        $this->assertTrue((new OpenTheDefaultFund())->step());

        $kept = Fund::query()->find('id', (int) $first->id);
        $this->assertTrue((bool) $kept->is_default, 'the one the site had been filing against stays');
        $this->assertTrue((bool) $kept->is_active);
        $this->assertNull($kept->ends_at);
        $this->assertTrue($kept->isOpen());

        $this->assertFalse((bool) Fund::query()->find('id', (int) $second->id)->is_default);
        $this->assertSame(1, $this->defaultCount());
    }

    public function test_the_default_lookup_answers_the_same_row_every_time(): void
    {
        $first = $this->fund('general', 'General', ['is_default' => 1]);
        $this->fund('building', 'Building', ['is_default' => 1]);

        $repo = Plugin::instance()->container->get(FundRepository::class);

        $this->assertSame((int) $first->id, (int) $repo->default()->id);
        $this->assertSame((int) $first->id, (int) $repo->default()->id, 'and it does not drift between reads');
    }

    public function test_the_upgrade_is_a_no_op_on_a_site_with_no_funds(): void
    {
        $this->assertTrue((new OpenTheDefaultFund())->step());
        $this->assertSame(0, $this->defaultCount());
    }

    /** @param array<string,mixed> $row */
    private function import(array $row): void
    {
        (new DataImporter(
            Plugin::instance()->container->get(Crypto::class),
            Plugin::instance()->container->get(IdentityHasher::class),
        ))->import(['tables' => ['fundkit_funds' => [array_merge([
            'id'         => 900,
            'is_default' => 0,
            'is_active'  => 1,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ], $row)]]]);
    }

    /** @param array<string,mixed> $overrides */
    private function fund(string $code, string $name, array $overrides = []): Fund
    {
        $now = gmdate('Y-m-d H:i:s');

        $fund = Fund::make();
        $fund->code       = $code;
        $fund->name       = $name;
        $fund->is_default = (bool) ($overrides['is_default'] ?? false);
        $fund->is_active  = (bool) ($overrides['is_active'] ?? true);
        $fund->starts_at  = $overrides['starts_at'] ?? null;
        $fund->ends_at    = $overrides['ends_at'] ?? null;
        $fund->created_at = $now;
        $fund->updated_at = $now;
        $fund->save();

        return $fund;
    }

    private function defaultCount(): int
    {
        return (int) Fund::query()->where('is_default', 1)->count();
    }
}
