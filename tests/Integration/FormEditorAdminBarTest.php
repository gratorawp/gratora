<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Admin\Pages\FormsPage;

/**
 * The form editor is fullscreen. The screen's stylesheet hides the admin bar,
 * but only after it has rendered and pushed the page down, so the filter that
 * refuses it outright is what keeps the editor flush to the top.
 */
final class FormEditorAdminBarTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // isFormEditView() gates on is_admin(), which the suite is not.
        set_current_screen('toplevel_page_fundkit-forms');
    }

    protected function tearDown(): void
    {
        unset($_GET['page'], $_GET['form']);
        set_current_screen('front');
        parent::tearDown();
    }

    private function page(): FormsPage
    {
        return new FormsPage();
    }

    public function test_the_bar_is_refused_on_the_editor(): void
    {
        $_GET['page'] = 'fundkit-forms';
        $_GET['form'] = '7';

        $this->assertFalse($this->page()->hideAdminBar(true));
    }

    public function test_the_bar_is_left_alone_on_the_list(): void
    {
        $_GET['page'] = 'fundkit-forms';

        $this->assertTrue($this->page()->hideAdminBar(true));
    }

    public function test_the_bar_is_left_alone_elsewhere_in_wp_admin(): void
    {
        $_GET['page'] = 'fundkit-campaigns';

        $this->assertTrue($this->page()->hideAdminBar(true));
    }

    public function test_an_existing_refusal_is_not_overturned(): void
    {
        $_GET['page'] = 'fundkit-campaigns';

        $this->assertFalse($this->page()->hideAdminBar(false));
    }

    public function test_registering_the_page_attaches_the_filter(): void
    {
        $page = $this->page();
        $this->assertFalse(has_filter('show_admin_bar', [$page, 'hideAdminBar']));

        $page->register();

        $this->assertNotFalse(has_filter('show_admin_bar', [$page, 'hideAdminBar']));

        remove_filter('show_admin_bar', [$page, 'hideAdminBar']);
    }

    public function test_wordpress_gets_the_refusal_through_the_filter(): void
    {
        $_GET['page'] = 'fundkit-forms';
        $_GET['form'] = '7';

        $page = $this->page();
        $page->register();

        $this->assertFalse(apply_filters('show_admin_bar', true));

        remove_filter('show_admin_bar', [$page, 'hideAdminBar']);
    }
}
