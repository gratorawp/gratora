<?php

declare(strict_types=1);

namespace Dono\Admin;

/**
 * Extension-tab seam: defines the window.dono.tabs registry inline and fires an
 * action so add-ons can enqueue tab bundles per surface. Core React apps read the
 * registry via the shared useExtensionTabs hook.
 */
final class ExtensionAssets
{
    public const HANDLE = 'dono-extensions';

    /** Add-ons hook this (with the surface name) to enqueue their tab scripts. */
    public const ACTION = 'dono.extension_tabs';

    /**
     * Ensure the registry script is enqueued, then let add-ons enqueue their
     * tab bundles (declaring self::HANDLE as a dependency) for this surface.
     */
    public static function enqueue(string $surface): void
    {
        if (! wp_script_is(self::HANDLE, 'registered')) {
            wp_register_script(self::HANDLE, false, [], DONO_VERSION, true);
            wp_add_inline_script(self::HANDLE, self::registryJs());
        }
        wp_enqueue_script(self::HANDLE);

        do_action(self::ACTION, $surface);
    }

    private static function registryJs(): string
    {
        return <<<'JS'
window.dono = window.dono || {};
window.dono.tabs = window.dono.tabs || (function () {
    var items = {};
    return {
        register: function (surface, tab) {
            if (!surface || !tab || !tab.id || typeof tab.mount !== 'function') return;
            (items[surface] = items[surface] || []).push(tab);
            window.dispatchEvent(new CustomEvent('dono:tabs:changed', { detail: { surface: surface } }));
        },
        get: function (surface) { return (items[surface] || []).slice(); }
    };
})();
JS;
    }
}
