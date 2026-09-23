<?php

declare(strict_types=1);

namespace Gratora\Admin;

/**
 * The plugin admin page a request is serving, as wp-admin/admin.php resolved
 * it from ?page= before admin_init. That is the page WordPress routes to, so
 * no screen decides differently from the router.
 *
 * @since 1.0.0
 */
final class CurrentPage
{
    /**
     * Empty outside a plugin admin page.
     *
     * @since 1.0.0
     */
    public static function slug(): string
    {
        $page = $GLOBALS['plugin_page'] ?? '';

        return is_string($page) ? sanitize_key($page) : '';
    }

    /**
     * The dashboard's slug is the bare "gratora"; every other screen is "gratora-".
     *
     * @since 1.0.0
     */
    public static function isGratora(): bool
    {
        $page = self::slug();

        return $page === 'gratora' || str_starts_with($page, 'gratora-');
    }
}
