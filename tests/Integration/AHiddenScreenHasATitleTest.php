<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Admin\AdminMenu;
use Gratora\Admin\Pages\FormsPage;

/**
 * WordPress looks a screen's title up through its parent menu and then prints
 * whatever it found. A screen kept out of the menu has no parent, so it finds
 * nothing, and admin-header.php hands null to strip_tags(), which PHP logs as
 * deprecated on every visit.
 */
final class AHiddenScreenHasATitleTest extends IntegrationTestCase
{
    private const MENU_GLOBALS = [
        'menu', 'submenu', 'title', 'plugin_page', 'pagenow', 'parent_file', 'admin_page_hooks',
        '_registered_pages', '_parent_pages', '_wp_real_parent_file', '_wp_menu_nopriv', '_wp_submenu_nopriv',
    ];

    /** @var array<string, mixed> */
    private array $before = [];

    protected function setUp(): void
    {
        parent::setUp();
        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        foreach (self::MENU_GLOBALS as $name) {
            $this->before[$name] = $GLOBALS[$name] ?? null;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->before as $name => $value) {
            $GLOBALS[$name] = $value;
        }
        parent::tearDown();
    }

    /** The steps wp-admin/admin.php takes before it loads the header of a plugin's screen. */
    private function titleWordPressFindsFor(string $page): ?string
    {
        $GLOBALS['pagenow']     = 'admin.php';
        $GLOBALS['plugin_page'] = $page;
        $GLOBALS['title']       = null;

        (new FormsPage())->register();
        (new AdminMenu())->registerMenu();

        do_action('load-' . get_plugin_page_hook($page, 'admin.php'));

        return get_admin_page_title();
    }

    public function test_the_form_editor_has_a_title_for_wordpress_to_print(): void
    {
        $this->assertSame('Forms', $this->titleWordPressFindsFor('gratora-forms'));
    }
}
