<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Admin\AdminMenu;

/**
 * A page kept out of the menu is registered under no parent. WordPress before
 * 7.0 hands that parent to plugin_basename() as it is, and PHP complains about
 * a null there, twice, on every admin request.
 */
final class TheAdminMenuRegistersQuietlyTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        add_filter('gratora.admin.pages', static function (array $pages): array {
            $pages[] = ['id' => 'gratora-kept-out', 'title' => 'Kept out', 'hidden' => true, 'render' => '__return_null'];
            $pages[] = ['id' => 'gratora-in-the-menu', 'title' => 'In the menu', 'render' => '__return_null'];

            return $pages;
        });
    }

    /** @return list<string> what PHP raised while the menu was registered */
    private function raisedWhileRegistering(): array
    {
        $raised = [];
        set_error_handler(static function (int $level, string $message) use (&$raised): bool {
            $raised[] = $message;

            return true;
        });

        try {
            (new AdminMenu())->registerMenu();
        } finally {
            restore_error_handler();
        }

        return $raised;
    }

    public function test_registering_the_menu_raises_nothing(): void
    {
        $this->assertSame([], $this->raisedWhileRegistering());
    }

    public function test_a_page_kept_out_of_the_menu_can_still_be_opened(): void
    {
        $this->raisedWhileRegistering();

        $this->assertArrayHasKey('admin_page_gratora-kept-out', $GLOBALS['_registered_pages']);
        $this->assertNotContains(
            'gratora-kept-out',
            array_column($GLOBALS['submenu']['gratora'] ?? [], 2),
            'and it is not listed under Fundraising'
        );
        $this->assertContains('gratora-in-the-menu', array_column($GLOBALS['submenu']['gratora'] ?? [], 2));
    }
}
