<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Campaigns\Campaign;
use Dono\Campaigns\CampaignService;
use Dono\Foundation\Plugin;
use Dono\Funds\FundService;
use RuntimeException;

/**
 * Campaign create/delete/duplicate and the fund default switch, asserted on
 * the one property their DB::transaction blocks exist to provide: a block
 * that cannot finish leaves nothing of itself behind, and the same block
 * lands whole when nothing throws.
 *
 * The default-fund switch is a two-row invariant, so it gets the stronger
 * assertion: not just "the new default is gone" but "the org still has
 * exactly one default fund, and it is the one it had before".
 *
 * The harness pins Queryable's nesting depth to 1 (see IntegrationTestCase),
 * so every product transaction here runs as a nested one taking a SAVEPOINT
 * inside WP_UnitTestCase's wrapping transaction.
 */
final class CampaignFundTransactionRollbackTest extends IntegrationTestCase
{
    private const SEAM_FAILURE = 'the storage layer refused this write';

    private function campaigns(): CampaignService
    {
        return Plugin::instance()->container->get(CampaignService::class);
    }

    private function funds(): FundService
    {
        return Plugin::instance()->container->get(FundService::class);
    }

    /**
     * Fail the first query the matcher accepts, for the duration of $run.
     *
     * wpdb runs every statement through the `query` filter, which puts a
     * precise throw inside a transaction without editing product code. The
     * savepoint's own ROLLBACK statement passes through here too, so matchers
     * must be specific enough not to claim it.
     */
    private function whileQueryThrows(callable $matches, callable $run): void
    {
        $filter = static function ($sql) use ($matches) {
            if ($matches((string) $sql)) {
                throw new RuntimeException(self::SEAM_FAILURE);
            }
            return $sql;
        };

        add_filter('query', $filter);
        try {
            $run();
        } finally {
            remove_filter('query', $filter);
        }
    }

    /** As above, but only the $nth query the matcher accepts fails. */
    private function whileNthQueryThrows(callable $matches, int $nth, callable $run): void
    {
        $seen = 0;
        $this->whileQueryThrows(
            static function (string $sql) use ($matches, $nth, &$seen): bool {
                return $matches($sql) && ++$seen === $nth;
            },
            $run
        );
    }

    /** Rows straight from the database, past any model or object cache. */
    private function countRows(string $table, string $where): int
    {
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return (int) self::$wpdb->get_var(
            'SELECT COUNT(*) FROM ' . self::$prefix . $table . ' WHERE ' . $where
        );
    }

    private function pagesLinkedToCampaigns(): int
    {
        return (int) self::$wpdb->get_var(
            "SELECT COUNT(*) FROM " . self::$prefix . "posts p
             INNER JOIN " . self::$prefix . "postmeta m ON m.post_id = p.ID
             WHERE p.post_type = 'page' AND m.meta_key = '_dono_campaign_id'"
        );
    }

    /**
     * @return list<int> ids of every fund the database currently calls default
     */
    private function defaultFundIds(): array
    {
        $ids = self::$wpdb->get_col(
            'SELECT id FROM ' . self::$prefix . 'dono_funds WHERE is_default = 1 ORDER BY id'
        );

        return array_map('intval', (array) $ids);
    }

    /**
     * create() writes the campaign row, its default form and its WP page in
     * one block. All three land together or none of them do; a half-created
     * campaign is a campaign with no form to donate through.
     */
    public function test_a_failed_campaign_create_leaves_no_campaign_form_or_page(): void
    {
        $before = [
            'campaigns' => $this->countRows('dono_campaigns', '1=1'),
            'forms'     => $this->countRows('dono_forms', '1=1'),
            'pages'     => $this->pagesLinkedToCampaigns(),
        ];

        // The final UPDATE stamps form + page ids back onto the campaign, so
        // by the time it runs every write in the block has already landed.
        $this->whileQueryThrows(
            static fn (string $sql): bool => stripos($sql, 'UPDATE') === 0
                && stripos($sql, 'dono_campaigns') !== false,
            function (): void {
                try {
                    $this->campaigns()->create(['title' => 'Rollback Reef']);
                    $this->fail('the refused write should reach the caller');
                } catch (RuntimeException $e) {
                    $this->assertSame(self::SEAM_FAILURE, $e->getMessage());
                }
            }
        );

        $this->assertSame(
            $before,
            [
                'campaigns' => $this->countRows('dono_campaigns', '1=1'),
                'forms'     => $this->countRows('dono_forms', '1=1'),
                'pages'     => $this->pagesLinkedToCampaigns(),
            ],
            'the failed create left a campaign row, a default form or an orphan page behind'
        );
    }

    /**
     * createDefaultFormFor() goes through FormService::create(), so
     * dono.form.created fires inside the campaign's transaction: an add-on
     * that refuses there refuses the campaign. Pinned because the seam is not
     * visible from the hook name, and an add-on doing irreversible work in it
     * would be doing it inside a block that can still be taken back.
     */
    public function test_an_add_on_refusing_the_form_hook_undoes_the_whole_campaign_create(): void
    {
        $before = [
            'campaigns' => $this->countRows('dono_campaigns', '1=1'),
            'forms'     => $this->countRows('dono_forms', '1=1'),
            'pages'     => $this->pagesLinkedToCampaigns(),
        ];

        $thrower = static function (): void {
            throw new RuntimeException(self::SEAM_FAILURE);
        };

        add_action('dono.form.created', $thrower);
        try {
            $this->campaigns()->create(['title' => 'Rollback Reef']);
            $this->fail('the add-on failure should reach the caller');
        } catch (RuntimeException $e) {
            $this->assertSame(self::SEAM_FAILURE, $e->getMessage());
        } finally {
            remove_action('dono.form.created', $thrower);
        }

        $this->assertSame(
            $before,
            [
                'campaigns' => $this->countRows('dono_campaigns', '1=1'),
                'forms'     => $this->countRows('dono_forms', '1=1'),
                'pages'     => $this->pagesLinkedToCampaigns(),
            ],
            'the refused create left a campaign row, a form or an orphan page behind'
        );
    }

    /**
     * duplicate() is create() with a source: same three writes, same promise.
     * A copy that fails halfway is a draft campaign with no form and a page
     * nobody linked, sitting in the pages list.
     */
    public function test_a_failed_campaign_duplicate_leaves_no_copy_form_or_page(): void
    {
        $source = $this->campaigns()->create(['title' => 'Reef Drive']);

        $before = [
            'campaigns' => $this->countRows('dono_campaigns', '1=1'),
            'forms'     => $this->countRows('dono_forms', '1=1'),
            'pages'     => $this->pagesLinkedToCampaigns(),
        ];

        // The copy's second save stamps page + form ids back onto it, so every
        // write in the block has landed by the time this one is refused.
        $this->whileQueryThrows(
            static fn (string $sql): bool => stripos($sql, 'UPDATE') === 0
                && stripos($sql, 'dono_campaigns') !== false,
            function () use ($source): void {
                try {
                    $this->campaigns()->duplicate($source);
                    $this->fail('the refused write should reach the caller');
                } catch (RuntimeException $e) {
                    $this->assertSame(self::SEAM_FAILURE, $e->getMessage());
                }
            }
        );

        $this->assertSame(
            $before,
            [
                'campaigns' => $this->countRows('dono_campaigns', '1=1'),
                'forms'     => $this->countRows('dono_forms', '1=1'),
                'pages'     => $this->pagesLinkedToCampaigns(),
            ],
            'the failed duplicate left a copy, a form or an orphan page behind'
        );
    }

    /** The same duplicate, unobstructed, lands a copy with its own form and page. */
    public function test_a_campaign_duplicate_that_does_not_throw_lands_copy_form_and_page(): void
    {
        $source = $this->campaigns()->create(['title' => 'Reef Drive']);

        $copy = $this->campaigns()->duplicate($source);

        $this->assertNotSame((int) $source->id, (int) $copy->id);
        $this->assertSame(
            ['campaign' => 1, 'form' => 1, 'page' => 1],
            [
                'campaign' => $this->countRows('dono_campaigns', 'id = ' . (int) $copy->id),
                'form'     => $this->countRows('dono_forms', 'id = ' . (int) $copy->default_form_id),
                'page'     => $this->countRows('posts', 'ID = ' . (int) $copy->page_id),
            ],
            'the duplicate did not land whole'
        );
        $this->assertNotSame((int) $source->page_id, (int) $copy->page_id);
    }

    /**
     * The forms and the campaign have to go together, or a campaign that
     * refused to delete is left with its forms already gone: the donation
     * routes stop resolving while the campaign is still published and still
     * taking links.
     *
     * The failure is injected on the campaign DELETE rather than on the
     * dono.form.deleted hook, which fires before either statement runs and so
     * would leave nothing for a rollback to undo.
     */
    public function test_a_refused_campaign_delete_puts_its_forms_back(): void
    {
        $campaign = $this->campaigns()->create(['title' => 'Delete Reef', 'status' => 'published']);
        $pageId   = (int) $campaign->page_id;
        $this->assertGreaterThan(0, $pageId);
        $this->assertSame(1, $this->countRows('dono_forms', 'campaign_id = ' . (int) $campaign->id));

        $this->whileQueryThrows(
            static fn (string $sql): bool => stripos($sql, 'DELETE') !== false
                && stripos($sql, 'dono_campaigns') !== false,
            function () use ($campaign): void {
                try {
                    $this->campaigns()->delete($campaign);
                    $this->fail('the refused delete should reach the caller');
                } catch (RuntimeException $e) {
                    $this->assertSame(self::SEAM_FAILURE, $e->getMessage());
                }
            }
        );

        $this->assertSame(
            ['campaign' => 1, 'forms' => 1, 'page' => 1],
            [
                'campaign' => $this->countRows('dono_campaigns', 'id = ' . (int) $campaign->id),
                'forms'    => $this->countRows('dono_forms', 'campaign_id = ' . (int) $campaign->id),
                'page'     => $this->countRows('posts', 'ID = ' . $pageId),
            ],
            'the refused delete took the campaign forms with it'
        );
    }

    /** The same delete, unobstructed, takes campaign, forms and page. */
    public function test_a_campaign_delete_that_does_not_throw_removes_campaign_form_and_page(): void
    {
        $campaign = $this->campaigns()->create(['title' => 'Delete Reef', 'status' => 'published']);
        $pageId   = (int) $campaign->page_id;
        $formId   = (int) $campaign->default_form_id;

        $this->campaigns()->delete($campaign);

        $this->assertSame(
            ['campaign' => 0, 'forms' => 0, 'page' => 0],
            [
                'campaign' => $this->countRows('dono_campaigns', 'id = ' . (int) $campaign->id),
                'forms'    => $this->countRows('dono_forms', 'id = ' . $formId),
                'page'     => $this->countRows('posts', 'ID = ' . $pageId),
            ],
            'the delete left part of the campaign behind'
        );
    }

    /**
     * Promoting a fund is two rows: the new default goes up and the old one
     * comes down. An org has exactly one default fund, so a promotion that
     * dies between the two writes must leave the old default standing rather
     * than a site with two defaults or none.
     */
    public function test_a_failed_promotion_leaves_exactly_one_default_fund(): void
    {
        $general  = $this->funds()->create(['code' => 'general', 'name' => 'General', 'is_default' => true]);
        $building = $this->funds()->create(['code' => 'building', 'name' => 'Building']);

        // Second UPDATE on dono_funds in the block: the first raised the new
        // default, so the old one is still up when this one is refused.
        $this->whileNthQueryThrows(
            static fn (string $sql): bool => stripos($sql, 'UPDATE') === 0
                && stripos($sql, 'dono_funds') !== false,
            2,
            function () use ($building): void {
                try {
                    $this->funds()->update($building, ['is_default' => true]);
                    $this->fail('the refused write should reach the caller');
                } catch (RuntimeException $e) {
                    $this->assertSame(self::SEAM_FAILURE, $e->getMessage());
                }
            }
        );

        $this->assertSame(
            [(int) $general->id],
            $this->defaultFundIds(),
            'the failed promotion did not leave exactly the previous default fund in place'
        );
    }

    /** The same promotion, unobstructed, moves the default across. */
    public function test_a_promotion_that_does_not_throw_moves_the_default(): void
    {
        $this->funds()->create(['code' => 'general', 'name' => 'General', 'is_default' => true]);
        $building = $this->funds()->create(['code' => 'building', 'name' => 'Building']);

        $this->funds()->update($building, ['is_default' => true]);

        $this->assertSame([(int) $building->id], $this->defaultFundIds());
    }

    /**
     * Creating a fund as the default is the same invariant with an INSERT in
     * front of it: the new row and the demotion of the old default commit
     * together, or the org keeps the default fund it already had.
     */
    public function test_a_failed_default_fund_create_leaves_no_row_and_the_old_default_standing(): void
    {
        $general = $this->funds()->create(['code' => 'general', 'name' => 'General', 'is_default' => true]);

        $this->whileQueryThrows(
            static fn (string $sql): bool => stripos($sql, 'UPDATE') === 0
                && stripos($sql, 'dono_funds') !== false,
            function (): void {
                try {
                    $this->funds()->create(['code' => 'building', 'name' => 'Building', 'is_default' => true]);
                    $this->fail('the refused write should reach the caller');
                } catch (RuntimeException $e) {
                    $this->assertSame(self::SEAM_FAILURE, $e->getMessage());
                }
            }
        );

        $this->assertSame(
            0,
            $this->countRows('dono_funds', "code = 'building'"),
            'the failed create left its fund row behind'
        );
        $this->assertSame(
            [(int) $general->id],
            $this->defaultFundIds(),
            'the failed create did not leave exactly the previous default fund in place'
        );
    }

    /** The same block, unobstructed, lands all three. */
    public function test_a_campaign_create_that_does_not_throw_lands_campaign_form_and_page(): void
    {
        $campaign = $this->campaigns()->create(['title' => 'Rollback Reef']);

        $this->assertSame(
            1,
            $this->countRows('dono_campaigns', 'id = ' . (int) $campaign->id),
            'the campaign row was not written'
        );
        $this->assertGreaterThan(0, (int) $campaign->default_form_id);
        $this->assertSame(
            1,
            $this->countRows('dono_forms', 'id = ' . (int) $campaign->default_form_id),
            'the default form was not written'
        );
        $this->assertGreaterThan(0, (int) $campaign->page_id);
        $this->assertSame(
            1,
            $this->countRows('posts', 'ID = ' . (int) $campaign->page_id),
            'the campaign page was not written'
        );
    }
}
