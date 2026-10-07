<?php

declare(strict_types=1);

namespace Gratora\Admin;

use Gratora\Foundation\Hooks\HookProvider;

/**
 * Core and its add-ons each carry their own copy of WordPress's data views
 * package, and more than one of them can load on a screen. WordPress 7.0
 * answers a package that asks for its private APIs again; before it the second
 * copy throws and its screen never draws. On those versions a script printed
 * after WordPress's own gives the first answer again.
 *
 * @unreleased
 */
final class RepeatedPackageRegistration extends HookProvider
{
    /** @unreleased */
    protected function actions(): array
    {
        return ['admin_enqueue_scripts' => ['allow', 1]];
    }

    /** @unreleased */
    public function allow(): void
    {
        if (is_wp_version_compatible('7.0') || ! CurrentPage::isGratora()) {
            return;
        }

        wp_add_inline_script(
            'wp-private-apis',
            (string) file_get_contents(GRATORA_DIR . 'assets/compat/private-apis-before-7.js'),
            'after'
        );
    }
}
