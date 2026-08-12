<?php

declare(strict_types=1);

namespace Dono\Admin;

use Dono\Foundation\Helpers\View;
use Dono\Foundation\Uninstall\DataEraser;

/**
 * The dialog shown when someone deactivates Dono from the plugins screen.
 *
 * It asks one thing, because nothing else in WordPress asks it: whether the
 * site owner wants their donation records kept. By the time they reach the
 * Delete link the plugin is gone and cannot ask.
 *
 * @since 1.0.0
 */
final class DeactivationDialog
{
    private const ACTION = 'dono_deactivation_choice';

    /** @since 1.0.0 */
    public function register(): void
    {
        add_action('admin_footer-plugins.php', [$this, 'renderDialog']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
        add_action('wp_ajax_' . self::ACTION, [$this, 'record']);
    }

    /** @since 1.0.0 */
    public function enqueue(string $hook): void
    {
        if ($hook !== 'plugins.php' || ! current_user_can('activate_plugins')) {
            return;
        }

        // mtime, not DONO_VERSION: these are shipped as source rather than
        // built, so a fix lands without a release and a stale cache means the
        // dialog silently keeps the stale behavior.
        wp_enqueue_style(
            'dono-deactivation',
            DONO_URL . 'assets/deactivation/dialog.css',
            [],
            $this->assetVersion('dialog.css')
        );
        wp_enqueue_script(
            'dono-deactivation',
            DONO_URL . 'assets/deactivation/dialog.js',
            [],
            $this->assetVersion('dialog.js'),
            true
        );
        wp_localize_script('dono-deactivation', 'donoDeactivation', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'action'  => self::ACTION,
            'nonce'   => wp_create_nonce(self::ACTION),
            'slug'    => plugin_basename(DONO_FILE),
        ]);
    }

    /** @since 1.0.0 */
    private function assetVersion(string $file): string
    {
        $path = DONO_DIR . 'assets/deactivation/' . $file;

        return (string) (@filemtime($path) ?: DONO_VERSION);
    }

    /** @since 1.0.0 */
    public function renderDialog(): void
    {
        if (! current_user_can('activate_plugins')) {
            return;
        }

        // Markup from a template that escapes its own values as it prints them.
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo View::loadRelative(__DIR__, 'views/deactivation-dialog', [
            'wipeOptIn' => DataEraser::requested(),
        ]);
    }

    /** @since 1.0.0 */
    public function record(): void
    {
        check_ajax_referer(self::ACTION);

        if (! current_user_can('activate_plugins')) {
            wp_send_json_error(null, 403);
        }

        // Deliberately explicit rather than a toggle: an absent checkbox in the
        // payload has to mean "keep my data", never "no answer, leave it set".
        $wipe = ! empty($_POST['wipe']);
        if ($wipe) {
            update_option(DataEraser::OPT_IN, time(), false);
        } else {
            delete_option(DataEraser::OPT_IN);
        }

        wp_send_json_success(['wipe' => $wipe]);
    }
}
