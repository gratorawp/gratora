<?php

declare(strict_types=1);

namespace Dono\Forms\Shortcode;

/**
 * Browser-side registry for donor fields that ship outside core: defines
 * window.dono.formFields inline and fires an action so an add-on can enqueue
 * the component, validation and payload contribution for its own field kind.
 *
 * The walker (dono.form.block_field) puts the field in the runtime config; this
 * is the other half, the code that renders it. An entry may supply any of
 * `component`, `values`, `validate` and `payload`; the runtime reads the
 * registry at render, validation and submit, so a bundle may load in either
 * order relative to it.
 *
 * @version 1.0.0
 */
final class FormFieldAssets
{
    public const HANDLE = 'dono-form-fields';

    /** Add-ons hook this to enqueue their field components. */
    public const ACTION = 'dono.form.fields';

    public static function enqueue(): void
    {
        if (! wp_script_is(self::HANDLE, 'registered')) {
            wp_register_script(self::HANDLE, false, [], DONO_VERSION, true);
            wp_add_inline_script(self::HANDLE, self::registryJs());
        }
        wp_enqueue_script(self::HANDLE);

        do_action(self::ACTION, self::HANDLE);
    }

    private static function registryJs(): string
    {
        return <<<'JS'
window.dono = window.dono || {};
window.dono.formFields = window.dono.formFields || (function () {
    var items = {};
    return {
        register: function (kind, entry) {
            if (!kind || !entry) return;
            items[kind] = entry;
        },
        get: function (kind) { return items[kind] || null; },
        all: function () {
            var out = [];
            for (var kind in items) {
                if (Object.prototype.hasOwnProperty.call(items, kind)) {
                    out.push([kind, items[kind]]);
                }
            }
            return out;
        }
    };
})();
JS;
    }
}
