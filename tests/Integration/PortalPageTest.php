<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Closure;
use Gratora\Donors\Portal\PortalPage;
use Gratora\Foundation\Plugin;
use ReflectionFunction;

/**
 * The donor portal page is the front door for every magic-link email - if
 * it doesn't resolve to a real published page, donors hit a 404 trying to
 * reach their receipts, recurring management or my-fundraising.
 *
 * Locks: idempotent ensure(), adoption of existing slugged page, resolve()
 * filters trashed/draft/non-page rows, url() respects the gratora.portal.url
 * override but otherwise returns get_permalink() of the resolved id.
 */
final class PortalPageTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // The test bootstrap runs full activation (which creates the portal
        // page and sets these options); clear them so each test starts from the
        // brand-new-install state it asserts.
        delete_option(PortalPage::OPTION_PAGE_ID);
        delete_option(PortalPage::OPTION_VERSION);
    }

    protected function tearDown(): void
    {
        delete_option(PortalPage::OPTION_PAGE_ID);
        delete_option(PortalPage::OPTION_VERSION);
        remove_all_filters('gratora.portal.url');
        parent::tearDown();
    }

    public function test_ensure_creates_a_published_page_with_the_shortcode(): void
    {
        $id = (new PortalPage())->ensure();

        $this->assertGreaterThan(0, $id);
        $post = get_post($id);
        $this->assertNotNull($post);
        $this->assertSame('page', $post->post_type);
        $this->assertSame('publish', $post->post_status);
        $this->assertSame(PortalPage::SLUG, $post->post_name);
        $this->assertStringContainsString(PortalPage::SHORTCODE, $post->post_content);
        $this->assertSame('1', get_post_meta($id, PortalPage::META_MANAGED, true));
        $this->assertSame($id, (int) get_option(PortalPage::OPTION_PAGE_ID));
    }

    public function test_ensure_is_idempotent(): void
    {
        $svc   = new PortalPage();
        $first = $svc->ensure();
        $second = $svc->ensure();

        $this->assertSame($first, $second, 'ensure returns the existing page id on subsequent calls');
        // No duplicate pages with our slug.
        $matches = get_posts([
            'post_type'   => 'page',
            'name'        => PortalPage::SLUG,
            'post_status' => 'publish',
            'numberposts' => -1,
            'fields'      => 'ids',
        ]);
        $this->assertCount(1, $matches);
    }

    public function test_ensure_adopts_an_existing_page_with_the_canonical_slug(): void
    {
        // Older install set up the portal manually with the shortcode.
        $existing = wp_insert_post([
            'post_type'    => 'page',
            'post_title'   => 'Members area',
            'post_name'    => PortalPage::SLUG,
            'post_status'  => 'publish',
            'post_content' => '[gratora_donor_portal]',
        ], true);
        $this->assertIsInt($existing);
        $this->assertGreaterThan(0, $existing);

        $id = (new PortalPage())->ensure();

        $this->assertSame((int) $existing, $id, 'ensure adopts the existing slugged page, no new insert');
        $this->assertSame((int) $existing, (int) get_option(PortalPage::OPTION_PAGE_ID));
    }

    public function test_resolve_returns_zero_when_stored_page_is_trashed(): void
    {
        $svc = new PortalPage();
        $id  = $svc->ensure();
        $this->assertGreaterThan(0, $svc->resolve());

        wp_trash_post($id);
        $this->assertSame(0, $svc->resolve(), 'a trashed page no longer resolves');
    }

    public function test_resolve_returns_zero_when_stored_page_is_draft(): void
    {
        $svc = new PortalPage();
        $id  = $svc->ensure();

        wp_update_post(['ID' => $id, 'post_status' => 'draft']);
        $this->assertSame(0, $svc->resolve());
    }

    public function test_url_returns_get_permalink_when_page_resolves(): void
    {
        $svc = new PortalPage();
        $id  = $svc->ensure();
        $expected = get_permalink($id);

        $this->assertSame($expected, $svc->url());
    }

    public function test_url_falls_back_to_home_url_when_no_page_yet(): void
    {
        // No ensure() call - simulate brand-new install pre-activation.
        $url = (new PortalPage())->url();
        $this->assertSame(home_url('/' . PortalPage::SLUG . '/'), $url);
    }

    public function test_filter_override_wins(): void
    {
        add_filter('gratora.portal.url', static fn () => 'https://custom.example/portal/');

        (new PortalPage())->ensure();

        $this->assertSame('https://custom.example/portal/', (new PortalPage())->url());
    }

    public function test_maybeHeal_runs_once_per_version(): void
    {
        $this->assertSame(0, (int) get_option(PortalPage::OPTION_PAGE_ID, 0));

        (new PortalPage())->maybeHeal();
        $idAfterFirstHeal = (int) get_option(PortalPage::OPTION_PAGE_ID);
        $this->assertGreaterThan(0, $idAfterFirstHeal, 'first heal ensures the page');
        $this->assertSame(GRATORA_VERSION, get_option(PortalPage::OPTION_VERSION));

        // Second heal with same version: no-op, same id.
        (new PortalPage())->maybeHeal();
        $this->assertSame($idAfterFirstHeal, (int) get_option(PortalPage::OPTION_PAGE_ID));
    }

    /** @dataProvider hiddenStatuses */
    public function test_an_update_leaves_a_page_the_owner_hid_as_it_is(string $status): void
    {
        $id = $this->portalPageSetTo($status);

        update_option(PortalPage::OPTION_VERSION, '0.0.0', false);
        $this->fireCoreWpLoaded();

        $this->assertSame(GRATORA_VERSION, get_option(PortalPage::OPTION_VERSION), 'precondition: the update ran its portal check');
        $this->assertSame([$id], $this->everyPage());
        $this->assertSame($status, get_post_status($id));
    }

    /** @return array<string, array{string}> */
    public static function hiddenStatuses(): array
    {
        return [
            'draft'     => ['draft'],
            'private'   => ['private'],
            'pending'   => ['pending'],
            'scheduled' => ['future'],
            'binned'    => ['trash'],
        ];
    }

    public function test_switching_the_plugin_on_again_leaves_a_hidden_page_as_it_is(): void
    {
        $id = $this->portalPageSetTo('draft');

        Plugin::onActivation();

        $this->assertSame([$id], $this->everyPage());
        $this->assertSame('draft', get_post_status($id));
    }

    public function test_a_hidden_page_published_again_is_the_portal_again(): void
    {
        $id = $this->portalPageSetTo('draft');
        update_option(PortalPage::OPTION_VERSION, '0.0.0', false);
        $this->fireCoreWpLoaded();

        wp_publish_post($id);

        $svc = new PortalPage();
        $this->assertSame($id, $svc->resolve());
        $this->assertSame(get_permalink($id), $svc->url());
        $this->assertNull($svc->hiddenPage());
    }

    public function test_a_page_deleted_for_good_is_made_again_at_the_next_update(): void
    {
        $id = (new PortalPage())->ensure();
        wp_delete_post($id, true);

        update_option(PortalPage::OPTION_VERSION, '0.0.0', false);
        $this->fireCoreWpLoaded();

        $pages = $this->everyPage();
        $this->assertCount(1, $pages);
        $this->assertSame($pages[0], (new PortalPage())->resolve());
    }

    public function test_a_public_page_at_the_address_takes_over_from_a_binned_one(): void
    {
        $this->portalPageSetTo('trash');
        $own = self::factory()->post->create([
            'post_type'    => 'page',
            'post_name'    => PortalPage::SLUG,
            'post_status'  => 'publish',
            'post_content' => PortalPage::SHORTCODE,
        ]);

        update_option(PortalPage::OPTION_VERSION, '0.0.0', false);
        $this->fireCoreWpLoaded();

        $this->assertSame($own, (new PortalPage())->resolve());
    }

    /** @dataProvider publishedAndDraft */
    public function test_the_page_being_shown_is_not_taken_for_the_portal(string $status): void
    {
        $GLOBALS['post'] = get_post(self::factory()->post->create(['post_type' => 'page', 'post_status' => $status]));

        $svc = new PortalPage();

        $this->assertSame(0, $svc->resolve());
        $this->assertNull($svc->hiddenPage());
    }

    /** @dataProvider publishedAndDraft */
    public function test_a_stored_id_that_is_not_a_page_is_not_the_portal(string $status): void
    {
        update_option(PortalPage::OPTION_PAGE_ID, self::factory()->post->create(['post_status' => $status]), false);

        $svc = new PortalPage();

        $this->assertSame(0, $svc->resolve());
        $this->assertNull($svc->hiddenPage());
    }

    /** @return array<string, array{string}> */
    public static function publishedAndDraft(): array
    {
        return ['published' => ['publish'], 'draft' => ['draft']];
    }

    private function portalPageSetTo(string $status): int
    {
        $id = (new PortalPage())->ensure();

        if ($status === 'trash') {
            wp_trash_post($id);
        } elseif ($status === 'future') {
            $later = gmdate('Y-m-d H:i:s', time() + WEEK_IN_SECONDS);
            wp_update_post(['ID' => $id, 'post_status' => $status, 'post_date' => $later, 'post_date_gmt' => $later, 'edit_date' => true]);
        } else {
            wp_update_post(['ID' => $id, 'post_status' => $status]);
        }

        $this->assertSame($status, get_post_status($id), 'precondition: the page took the status');

        return $id;
    }

    /** @return list<int> */
    private function everyPage(): array
    {
        return array_map('intval', get_posts([
            'post_type'   => 'page',
            'post_status' => array_keys(get_post_stati()),
            'numberposts' => -1,
            'orderby'     => 'ID',
            'order'       => 'ASC',
            'fields'      => 'ids',
        ]));
    }

    /**
     * The test bootstrap hangs a full activation on wp_loaded, and that calls
     * ensure() whatever the version says, so only the callbacks Plugin declares
     * are left standing.
     */
    private function fireCoreWpLoaded(): void
    {
        global $wp_filter;

        $core    = realpath(dirname(__DIR__, 2) . '/src/Foundation/Plugin.php');
        $removed = [];

        foreach (($wp_filter['wp_loaded']->callbacks ?? []) as $priority => $callbacks) {
            foreach ($callbacks as $callback) {
                $fn = $callback['function'];
                if ($fn instanceof Closure && realpath((string) (new ReflectionFunction($fn))->getFileName()) === $core) {
                    continue;
                }

                $removed[] = [$fn, $priority, $callback['accepted_args']];
                remove_action('wp_loaded', $fn, $priority);
            }
        }

        try {
            do_action('wp_loaded');
        } finally {
            foreach ($removed as [$fn, $priority, $args]) {
                add_action('wp_loaded', $fn, $priority, $args);
            }
        }
    }
}
