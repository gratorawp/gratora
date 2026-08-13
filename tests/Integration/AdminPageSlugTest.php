<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use ReflectionClass;
use Dono\Admin\AdminMenu;

/**
 * The admin menu slug is a `page=` query value, not a text domain.
 *
 * They look identical in source: `{ page: 'dono' }` sits on the same line as
 * `__( 'Dono', 'dono' )`, so a sweep that renames the domain takes the slug
 * with it and every "Dono" breadcrumb 404s. That is exactly what happened, and
 * nothing caught it because no test ever followed the link.
 */
final class AdminPageSlugTest extends IntegrationTestCase
{
    private function jsSlug(): string
    {
        $js = (string) file_get_contents(
            DONO_DIR . 'assets/admin/_shared/adminPages.js'
        );

        $this->assertMatchesRegularExpression(
            "/export const ADMIN_SLUG = '([a-z0-9-]+)';/",
            $js,
            'the shared slug constant must stay greppable'
        );

        preg_match("/export const ADMIN_SLUG = '([a-z0-9-]+)';/", $js, $m);

        return $m[1];
    }

    public function test_the_javascript_slug_matches_the_registered_menu(): void
    {
        $ref  = new ReflectionClass(AdminMenu::class);
        $slug = (string) $ref->getConstant('SLUG');

        $this->assertNotSame('', $slug, 'AdminMenu::SLUG must exist for this to mean anything');
        $this->assertSame(
            $slug,
            $this->jsSlug(),
            'the breadcrumb would link to a page WordPress never registered'
        );
    }

    public function test_no_screen_hardcodes_the_text_domain_as_a_page_slug(): void
    {
        // The specific mistake, pinned by shape rather than by file: any screen
        // linking page= to the text domain is linking to nothing.
        $hits = [];
        $dir  = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(DONO_DIR . 'assets/admin')
        );

        foreach ($dir as $file) {
            if ($file->getExtension() !== 'jsx' && $file->getExtension() !== 'js') {
                continue;
            }
            $body = (string) file_get_contents($file->getPathname());
            if (str_contains($body, "page: 'dono-fundraising-platform'")
                || str_contains($body, "page=dono-fundraising-platform")) {
                $hits[] = $file->getPathname();
            }
        }

        $this->assertSame([], $hits, 'these link to a page that does not exist');
    }
}
