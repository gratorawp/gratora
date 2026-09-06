<?php

declare(strict_types=1);

namespace FundKit\Tests\Multisite;

use FundKit\Foundation\Plugin;
use FundKit\Foundation\Uninstall\DataEraser;
use FundKit\Tests\Integration\IntegrationTestCase;

/**
 * register_deactivation_hook fires exactly once for a network-wide
 * deactivation, in the main site's context, and the consent used to be spent
 * as its first act. So a network administrator who ticked "Delete all
 * Fundraising Toolkit data as well" and network-deactivated erased site 1 and
 * spent the flag; the later plugin delete found requested() false and
 * uninstall.php erased nothing. Sites 2 through 12 kept every encrypted donor
 * row, consent history, donation and receipt, with the screen saying the data
 * was gone and not recoverable.
 *
 * The drop itself is not observable here: WordPress's harness rewrites DROP
 * TABLE to DROP TEMPORARY TABLE. What the wipe reaching a site does observably
 * is announce itself and delete that site's options, and both are per-site.
 */
final class NetworkDeactivationWipeTest extends IntegrationTestCase
{
    private int $otherSite = 0;

    /** @var list<int> */
    private array $erased = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (! is_multisite()) {
            $this->markTestSkipped('Run with WP_MULTISITE=1.');
        }

        $this->otherSite = (int) self::factory()->blog->create();
        $this->erased    = [];

        add_action('fundkit.uninstall', function (): void {
            $this->erased[] = get_current_blog_id();
        });

        // A network-activated plugin has its tables on every site; a blog the
        // factory just made has none, and erase() reads them before it deletes
        // anything.
        $this->onSite($this->otherSite, static function (): void {
            Plugin::onActivation(true);
        });

        foreach ([get_current_blog_id(), $this->otherSite] as $siteId) {
            $this->onSite($siteId, static function (): void {
                update_option('fundkit_org_profile', ['name' => 'Local charity']);
            });
        }
    }

    private function onSite(int $siteId, callable $fn): mixed
    {
        switch_to_blog($siteId);
        try {
            return $fn();
        } finally {
            restore_current_blog();
        }
    }

    private function stillHasSettings(int $siteId): bool
    {
        return $this->onSite($siteId, static fn (): bool => get_option('fundkit_org_profile') !== false);
    }

    private function askForTheWipe(): void
    {
        update_option(DataEraser::OPT_IN, time());
    }

    public function test_a_network_deactivation_reaches_every_site_not_just_the_first(): void
    {
        $this->assertTrue($this->stillHasSettings($this->otherSite), 'fixture: the second site holds data');

        $this->askForTheWipe();
        Plugin::onDeactivation(true);

        $this->assertContains(get_current_blog_id(), $this->erased);
        $this->assertContains(
            $this->otherSite,
            $this->erased,
            'the site owner was told the data was deleted and not recoverable'
        );
        $this->assertFalse($this->stillHasSettings($this->otherSite));
    }

    /** Deactivating on one site is not a network deactivation and must not reach the others. */
    public function test_a_single_site_deactivation_leaves_the_other_sites_alone(): void
    {
        $this->askForTheWipe();
        Plugin::onDeactivation(false);

        $this->assertSame([get_current_blog_id()], $this->erased);
        $this->assertTrue(
            $this->stillHasSettings($this->otherSite),
            'another site’s data is not this deactivation’s to delete'
        );
    }

    /** Nobody asked, so nothing goes, on any site. */
    public function test_a_network_deactivation_without_the_opt_in_erases_nothing(): void
    {
        Plugin::onDeactivation(true);

        $this->assertSame([], $this->erased);
        $this->assertTrue($this->stillHasSettings($this->otherSite));
    }

    /** One site the plugin was never active on cannot end the wipe for the rest. */
    public function test_a_site_that_cannot_be_erased_does_not_stop_the_others(): void
    {
        $first = true;
        add_action('fundkit.uninstall', static function () use (&$first): void {
            if ($first) {
                $first = false;
                throw new \RuntimeException('no tables on this site');
            }
        }, 5);

        $this->askForTheWipe();
        Plugin::onDeactivation(true);

        $this->assertGreaterThanOrEqual(1, count($this->erased), 'the wipe stopped at the first site that failed');
    }

    /**
     * The answer is spent by finishing. A wipe that could not reach every site
     * has to leave the plugin delete something to act on, or uninstall.php
     * returns at its own requested() check and the sites it missed keep every
     * donor row, with the screen saying the data is gone.
     */
    public function test_an_unfinished_wipe_leaves_the_answer_for_the_plugin_delete(): void
    {
        add_action('fundkit.uninstall', static function (): void {
            if (get_current_blog_id() !== 1) {
                throw new \RuntimeException('no tables on this site');
            }
        }, 5);

        $this->askForTheWipe();
        Plugin::onDeactivation(true);

        $this->assertTrue(
            DataEraser::requested(),
            'the delete that follows is the retry, and it needs the answer'
        );
    }

    /** A wipe that reached every site spends it, so nothing acts on it twice. */
    public function test_a_finished_wipe_spends_the_answer(): void
    {
        $this->askForTheWipe();
        Plugin::onDeactivation(true);

        $this->assertFalse(DataEraser::requested());
    }
}
