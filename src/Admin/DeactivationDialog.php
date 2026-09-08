<?php

declare(strict_types=1);

namespace FundKit\Admin;

use FundKit\Foundation\Helpers\View;
use FundKit\Foundation\Uninstall\DataEraser;

/**
 * Ask whether to retain data before deactivation removes the plugin’s UI.
 *
 * @since 1.0.0
 */
final class DeactivationDialog
{
    private const ACTION = 'fundkit_deactivation_choice';

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

        // Version source assets by mtime so unreleased changes invalidate caches.
        wp_enqueue_style(
            'fundkit-deactivation',
            FUNDKIT_URL . 'assets/deactivation/dialog.css',
            [],
            $this->assetVersion('dialog.css')
        );
        wp_enqueue_script(
            'fundkit-deactivation',
            FUNDKIT_URL . 'assets/deactivation/dialog.js',
            [],
            $this->assetVersion('dialog.js'),
            true
        );
        wp_localize_script('fundkit-deactivation', 'fundkitDeactivation', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'action'  => self::ACTION,
            'nonce'   => wp_create_nonce(self::ACTION),
            'slug'    => plugin_basename(FUNDKIT_FILE),
        ]);
    }

    /** @since 1.0.0 */
    private function assetVersion(string $file): string
    {
        $path = FUNDKIT_DIR . 'assets/deactivation/' . $file;

        return (string) (@filemtime($path) ?: FUNDKIT_VERSION);
    }

    /** @since 1.0.0 */
    public function renderDialog(): void
    {
        if (! current_user_can('activate_plugins')) {
            return;
        }

        $markup = View::loadRelative(__DIR__, 'views/deactivation-dialog', [
            'wipeOptIn' => DataEraser::requested(),
        ]);

        // The echo is its own statement so the annotation covers the line the
        // sniff reports, which is the argument rather than the echo.
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- views/deactivation-dialog.php prints only esc_html_e(), esc_attr_e() and checked() output.
        echo $markup;
    }

    /** @since 1.0.0 */
    public function record(): void
    {
        check_ajax_referer(self::ACTION);

        if (! current_user_can('activate_plugins')) {
            wp_send_json_error(null, 403);
        }

        // An absent checkbox must clear the wipe flag.
        $wipe = ! empty($_POST['wipe']);
        if ($wipe) {
            update_option(DataEraser::OPT_IN, time(), false);
        } else {
            delete_option(DataEraser::OPT_IN);
        }

        wp_send_json_success(['wipe' => $wipe]);
    }
}
