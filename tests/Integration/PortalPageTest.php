<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donors\Portal\PortalPage;

/**
 * The donor portal page is the front door for every magic-link email - if
 * it doesn't resolve to a real published page, donors hit a 404 trying to
 * reach their receipts, recurring management or my-fundraising.
 *
 * Locks: idempotent ensure(), adoption of existing slugged page, resolve()
 * filters trashed/draft/non-page rows, url() respects the dono.portal.url
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
        remove_all_filters('dono.portal.url');
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
            'post_content' => '[dono_donor_portal]',
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
        add_filter('dono.portal.url', static fn () => 'https://custom.example/portal/');

        (new PortalPage())->ensure();

        $this->assertSame('https://custom.example/portal/', (new PortalPage())->url());
    }

    public function test_maybeHeal_runs_once_per_version(): void
    {
        $this->assertSame(0, (int) get_option(PortalPage::OPTION_PAGE_ID, 0));

        (new PortalPage())->maybeHeal();
        $idAfterFirstHeal = (int) get_option(PortalPage::OPTION_PAGE_ID);
        $this->assertGreaterThan(0, $idAfterFirstHeal, 'first heal ensures the page');
        $this->assertSame(DONO_VERSION, get_option(PortalPage::OPTION_VERSION));

        // Second heal with same version: no-op, same id.
        (new PortalPage())->maybeHeal();
        $this->assertSame($idAfterFirstHeal, (int) get_option(PortalPage::OPTION_PAGE_ID));
    }
}
