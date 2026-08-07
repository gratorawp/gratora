<?php

declare(strict_types=1);

namespace Dono\Admin;

use Dono\Foundation\Helpers\View;
use Dono\Foundation\Uninstall\DataEraser;

/**
 * The dialog shown when someone deactivates Dono from the plugins screen.
 *
 * It exists for the data question, not the survey: nothing else in WordPress
 * asks whether the site owner wants their donation records kept, and by the
 * time they reach the Delete link the plugin is gone and cannot ask.
 *
 * The reason radios are recorded locally. Sending them anywhere needs a
 * collector that does not exist yet, and a survey that posts into the void is
 * worse than none.
 */
final class DeactivationSurvey
{
    public const OPTION_LAST_REASON = 'dono_deactivation_reason';

    private const ACTION = 'dono_deactivation_choice';

    public function register(): void
    {
        add_action('admin_footer-plugins.php', [$this, 'renderDialog']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
        add_action('wp_ajax_' . self::ACTION, [$this, 'record']);
    }

    public function enqueue(string $hook): void
    {
        if ($hook !== 'plugins.php' || ! current_user_can('activate_plugins')) {
            return;
        }

        wp_enqueue_style(
            'dono-deactivation',
            DONO_URL . 'assets/deactivation/dialog.css',
            [],
            DONO_VERSION
        );
        wp_enqueue_script(
            'dono-deactivation',
            DONO_URL . 'assets/deactivation/dialog.js',
            [],
            DONO_VERSION,
            true
        );
        wp_localize_script('dono-deactivation', 'donoDeactivation', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'action'  => self::ACTION,
            'nonce'   => wp_create_nonce(self::ACTION),
            'slug'    => plugin_basename(DONO_FILE),
        ]);
    }

    public function renderDialog(): void
    {
        if (! current_user_can('activate_plugins')) {
            return;
        }

        echo View::loadRelative(__DIR__, 'views/deactivation-dialog', [
            'reasons'   => $this->reasons(),
            'wipeOptIn' => DataEraser::requested(),
        ]);
    }

    public function record(): void
    {
        check_ajax_referer(self::ACTION);

        if (! current_user_can('activate_plugins')) {
            wp_send_json_error(null, 403);
        }

        $reason = sanitize_key((string) ($_POST['reason'] ?? ''));
        if ($reason !== '' && array_key_exists($reason, $this->reasons())) {
            $feedback = [
                'reason'  => $reason,
                'details' => sanitize_textarea_field((string) ($_POST['details'] ?? '')),
                'at'      => gmdate('c'),
            ];
            update_option(self::OPTION_LAST_REASON, $feedback, false);

            // Nothing in core sends this anywhere. The answer is the site
            // owner's, given on their own machine, and it leaves only if
            // something is deliberately attached to carry it.
            do_action('dono.deactivation.feedback', $feedback);
        }

        // Deliberately explicit rather than a toggle: an absent checkbox in the
        // payload has to mean "keep my data", never "no answer, leave it set".
        $wipe = ! empty($_POST['wipe']);
        if ($wipe) {
            update_option(DataEraser::OPT_IN, true, false);
        } else {
            delete_option(DataEraser::OPT_IN);
        }

        wp_send_json_success(['wipe' => $wipe]);
    }

    /** @return array<string,string> */
    private function reasons(): array
    {
        return [
            'temporary'   => __('I am only deactivating temporarily', 'dono'),
            'not_needed'  => __('I no longer need it', 'dono'),
            'switched'    => __('I found something that suits us better', 'dono'),
            'short_term'  => __('We only needed it for a short campaign', 'dono'),
            'broke_site'  => __('It broke my site', 'dono'),
            'stopped'     => __('It stopped working', 'dono'),
            'hard_to_use' => __('I could not work out how to set it up', 'dono'),
            'other'       => __('Something else', 'dono'),
        ];
    }
}
