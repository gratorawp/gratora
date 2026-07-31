<?php

declare(strict_types=1);

namespace Dono\Admin;

/**
 * Extension seam: defines the window.dono.tabs and window.dono.panels registries
 * inline and fires an action so add-ons can enqueue bundles per surface. A tab is
 * a whole screen; a panel is a section inside one (the portal's donation detail).
 * Core React apps read the registries via the shared useExtensionTabs hook.
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
        // A count an add-on learns after registering, e.g. how many pages are
        // waiting for review. Replaces the entry rather than mutating it, so a
        // consumer comparing identity re-renders; same id, so the panel does
        // not remount.
        badge: function (surface, id, value) {
            var list = items[surface] || [];
            for (var i = 0; i < list.length; i++) {
                if (list[i].id !== id) continue;
                if (list[i].badge === value) return;
                var next = {};
                for (var k in list[i]) { if (Object.prototype.hasOwnProperty.call(list[i], k)) next[k] = list[i][k]; }
                next.badge = value;
                list[i] = next;
                window.dispatchEvent(new CustomEvent('dono:tabs:changed', { detail: { surface: surface } }));
                return;
            }
        },
        get: function (surface) { return (items[surface] || []).slice(); }
    };
})();
window.dono.panels = window.dono.panels || (function () {
    var items = {};
    return {
        register: function (surface, panel) {
            if (!surface || !panel || !panel.id || typeof panel.mount !== 'function') return;
            (items[surface] = items[surface] || []).push(panel);
            window.dispatchEvent(new CustomEvent('dono:panels:changed', { detail: { surface: surface } }));
        },
        get: function (surface) { return (items[surface] || []).slice(); }
    };
})();

// One card open at a time within a named group. Core's cards and an add-on's
// render in separate React roots, so the open one cannot be tracked in either
// tree: whichever root a click lands in has to be able to close a card owned
// by the other.
window.dono.accordion = window.dono.accordion || (function () {
    var open = {};
    return {
        current: function (group) { return open[group] || null; },
        set: function (group, id) {
            open[group] = id || null;
            window.dispatchEvent(new CustomEvent('dono:accordion:changed', { detail: { group: group } }));
        },
        // Opens only if nobody has claimed the group yet. Several cards can
        // want attention on the same screen, and they must not fight over it
        // on mount: the first to ask wins, and a click always overrides.
        claim: function (group, id) {
            if (open[group]) return false;
            open[group] = id;
            window.dispatchEvent(new CustomEvent('dono:accordion:changed', { detail: { group: group } }));
            return true;
        }
    };
})();
JS;
    }
}
