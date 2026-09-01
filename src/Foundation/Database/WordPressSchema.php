<?php

declare(strict_types=1);

namespace FundKit\Foundation\Database;

use FundKit\Vendor\Queryable\DB;

/**
 * Teaches the query builder about the WordPress tables it did not define.
 *
 * Without this, withMeta() throws for wp_posts: it reads its configuration
 * from the registered schema, and WordPress core's tables are nobody's model.
 *
 * No aliases are registered here, because core cannot know the meta keys an
 * add-on cares about. Pass real keys - withMeta('_fundkit_p2p_layout') - or
 * contribute your own aliases on top of what is already registered:
 *
 *     $posts = DB::getSchema('posts');
 *     $posts['meta']['aliases']['layout'] = '_fundkit_p2p_layout';
 *     DB::registerSchema('posts', $posts);
 *
 * @since 1.0.0
 */
final class WordPressSchema
{
    /**
     * Runs at file load rather than on a hook: a query can be issued before
     * plugins_loaded (the integration bootstrap migrates that early), and a
     * schema registered after the first withMeta() call is a schema that was
     * not there when it mattered.
     */
    public static function register(): void
    {
        // The table name is stored unprefixed. DB::table() applies $wpdb->prefix
        // when the query is built, which is the only point the prefix is known.
        DB::registerSchema('posts', [
            'meta' => [
                'table'      => 'postmeta',
                'foreignKey' => 'post_id',
                'primaryKey' => 'ID',
            ],
        ]);
    }
}
