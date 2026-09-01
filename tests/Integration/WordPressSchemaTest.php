<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Vendor\Queryable\DB;

/**
 * withMeta() reads its configuration from the registered schema, and WordPress
 * core's tables are nobody's model, so wp_posts had none: every call threw
 * "No meta configuration defined for this table" and the method was
 * unreachable for post meta.
 */
final class WordPressSchemaTest extends IntegrationTestCase
{
    private function page(string $title, ?string $layout = null): int
    {
        $args = ['post_type' => 'page', 'post_status' => 'publish', 'post_title' => $title];
        if ($layout !== null) {
            $args['meta_input'] = ['_fundkit_layout' => $layout];
        }

        return (int) wp_insert_post($args);
    }

    public function test_posts_is_registered(): void
    {
        $this->assertSame('postmeta', DB::getSchema('posts')['meta']['table'] ?? null);
    }

    public function test_with_meta_reads_post_meta(): void
    {
        $this->page('plain');
        $this->page('with layout', 'team');

        $rows = DB::table('posts')
            ->select('post_title')
            ->withMeta('_fundkit_layout')
            ->where('post_type', 'page')
            ->whereIsNotNull('_fundkit_layout')
            ->getAll();

        $this->assertCount(1, $rows);
        $this->assertSame('team', $rows[0]['_fundkit_layout']);
    }

    /**
     * The join condition used to be built from the real table name while the
     * FROM clause named the alias, which MySQL rejects as an unknown column.
     */
    public function test_with_meta_works_on_an_aliased_table(): void
    {
        $this->page('aliased', 'start');

        $rows = DB::table('posts', 'p')
            ->select('p.post_title')
            ->withMeta('_fundkit_layout')
            ->where('p.post_type', 'page')
            ->whereIsNotNull('_fundkit_layout')
            ->getAll();

        $this->assertSame('start', $rows[0]['_fundkit_layout']);
    }

    /**
     * The prefix is only knowable when the query is built, so the schema stores
     * the table unprefixed and DB::table() applies it.
     */
    public function test_the_meta_table_is_prefixed_at_query_time(): void
    {
        global $wpdb;

        $this->assertStringContainsString(
            "LEFT JOIN {$wpdb->postmeta} AS",
            DB::table('posts')->select('ID')->withMeta('_fundkit_layout')->toSQL()
        );
    }
}
