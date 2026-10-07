<?php

declare(strict_types=1);

namespace Gratora\Admin;

use Closure;
use Gratora\Foundation\Helpers\View;
use Gratora\Foundation\Uninstall\DataEraser;

/**
 * Ask whether to retain data, and why, before deactivation removes the plugin’s UI.
 *
 * @since 1.0.0
 */
final class DeactivationDialog
{
    private const ACTION = 'gratora_deactivation_choice';
    private const REASON = 'gratora_deactivation_reason';

    /**
     * @unreleased
     *
     * @param Closure(): DeactivationSurvey $survey built only when an answer comes in
     */
    public function __construct(private Closure $survey)
    {
    }

    /** @since 1.0.0 */
    public function register(): void
    {
        add_action('admin_footer-plugins.php', [$this, 'renderDialog']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
        add_action('wp_ajax_' . self::ACTION, [$this, 'record']);
        add_action('wp_ajax_' . self::REASON, [$this, 'tell']);
    }

    /** @since 1.0.0 */
    public function enqueue(string $hook): void
    {
        if ($hook !== 'plugins.php' || ! current_user_can('activate_plugins')) {
            return;
        }

        // Version source assets by mtime so unreleased changes invalidate caches.
        wp_enqueue_style(
            'gratora-deactivation',
            GRATORA_URL . 'assets/deactivation/dialog.css',
            [],
            $this->assetVersion('dialog.css')
        );
        wp_enqueue_script(
            'gratora-deactivation',
            GRATORA_URL . 'assets/deactivation/dialog.js',
            [],
            $this->assetVersion('dialog.js'),
            true
        );
        wp_localize_script('gratora-deactivation', 'gratoraDeactivation', [
            'ajaxUrl'      => admin_url('admin-ajax.php'),
            'action'       => self::ACTION,
            'nonce'        => wp_create_nonce(self::ACTION),
            'reasonAction' => self::REASON,
            'reasonNonce'  => wp_create_nonce(self::REASON),
            'slug'         => plugin_basename(GRATORA_FILE),
        ]);
    }

    /** @since 1.0.0 */
    private function assetVersion(string $file): string
    {
        $path = GRATORA_DIR . 'assets/deactivation/' . $file;

        return (string) (@filemtime($path) ?: GRATORA_VERSION);
    }

    /** @since 1.0.0 */
    public function renderDialog(): void
    {
        if (! current_user_can('activate_plugins')) {
            return;
        }

        View::printRelative(__DIR__, 'views/deactivation-dialog', [
            'wipeOptIn'     => DataEraser::requested(),
            'reasons'       => DeactivationSurvey::asks() ? DeactivationSurvey::reasons() : [],
            'commentLength' => DeactivationSurvey::COMMENT_LENGTH,
        ]);
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

    /**
     * A request of its own, so that deactivation never waits on gratora.net.
     *
     * @unreleased
     */
    public function tell(): void
    {
        check_ajax_referer(self::REASON);

        if (! current_user_can('activate_plugins')) {
            wp_send_json_error(null, 403);
        }

        // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- the reason is matched against the list, and the words are kept as typed and cleaned where they are sent.
        $reason  = wp_unslash($_POST['reason'] ?? '');
        $comment = wp_unslash($_POST['comment'] ?? '');
        // phpcs:enable

        if (is_string($reason) && $reason !== '') {
            // The choice about data is what deactivation waits for, and it would queue
            // behind this request for as long as another plugin's session stayed open in it.
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }

            ($this->survey)()->send($reason, is_string($comment) ? $comment : '');
        }

        wp_send_json_success();
    }
}
