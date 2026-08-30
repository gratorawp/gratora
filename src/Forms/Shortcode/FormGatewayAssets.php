<?php

declare(strict_types=1);

namespace FundKit\Forms\Shortcode;

/**
 * Browser-side registry for gateways that ship outside core: defines
 * window.fundkit.formGateways inline and fires an action so an add-on can enqueue
 * its payment component on the same pages the form is on.
 *
 * The runtime reads the registry lazily, at submit and at render, so an add-on
 * bundle may load in either order relative to it. Only the registry itself has
 * to exist first, which the dependency below guarantees.
 *
 * @since 1.0.0
 */
final class FormGatewayAssets
{
    public const HANDLE = 'fundkit-form-gateways';

    /** Add-ons hook this to enqueue their payment components. */
    public const ACTION = 'fundkit.form.enqueued';

    /** @since 1.0.0 */
    public static function enqueue(): void
    {
        if (! wp_script_is(self::HANDLE, 'registered')) {
            wp_register_script(self::HANDLE, false, [], FUNDKIT_VERSION, true);
            wp_add_inline_script(self::HANDLE, self::registryJs());
        }
        wp_enqueue_script(self::HANDLE);

        do_action(self::ACTION, self::HANDLE);
    }

    /** @since 1.0.0 */
    private static function registryJs(): string
    {
        return <<<'JS'
window.fundkit = window.fundkit || {};
window.fundkit.formGateways = window.fundkit.formGateways || (function () {
    var items = {};
    return {
        register: function (id, entry) {
            if (!id || !entry || typeof entry.component !== 'function') return;
            items[id] = entry;
        },
        get: function (id) { return items[id] || null; }
    };
})();
JS;
    }
}
