<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use ReflectionClass;
use Gratora\Admin\AdminMenu;

/**
 * The admin menu slug is a `page=` query value, not a text domain.
 *
 * A rename that takes one with the other makes every breadcrumb 404, which is
 * what happened once because no test followed the link.
 *
 * NOT COVERED: a screen hardcoding a page slug instead of reading ADMIN_SLUG.
 * The check that caught it compared the slug against the text domain, and the
 * two are the same string, so it can no longer tell a good link from a bad one.
 * Resolving each link against the registered menu is the test worth having; the
 * subpages come from a filter that only populates in a full admin bootstrap,
 * which this suite does not run.
 */
final class AdminPageSlugTest extends IntegrationTestCase
{
    private function jsSlug(): string
    {
        $js = (string) file_get_contents(
            GRATORA_DIR . 'assets/admin/_shared/adminPages.js'
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
}
