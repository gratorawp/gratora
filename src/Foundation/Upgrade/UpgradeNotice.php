<?php

declare(strict_types=1);

namespace Dono\Foundation\Upgrade;

/**
 * Says so while a data migration is outstanding.
 *
 * The Advanced screen carries the detail and the button, and nobody opens the
 * Advanced screen. That is fine while the routines are draining normally, which
 * takes a minute or two. It is not fine when they are not draining at all:
 * Action Scheduler rides WP-cron, plenty of hosts disable or throttle it, and
 * the result is a site sitting half-migrated indefinitely while its totals read
 * as though nothing were wrong.
 *
 * A notice is the only thing that reaches someone who is not looking for it.
 *
 * @version 1.0.0
 */
final class UpgradeNotice
{
    public function __construct(private UpgradeRunner $runner)
    {
    }

    public function register(): void
    {
        add_action('admin_notices', [$this, 'render']);
    }

    public function render(): void
    {
        if (! current_user_can('manage_dono')) {
            return;
        }

        $pending = $this->runner->pending();
        if ($pending === []) {
            return;
        }

        // Already on the screen that says all of this, with the button.
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen && str_contains((string) $screen->id, 'dono-settings')) {
            return;
        }

        $url = admin_url('admin.php?page=dono-settings&tab=advanced');

        printf(
            '<div class="notice notice-warning"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
            esc_html__('Dono is finishing a data update.', 'dono'),
            esc_html(
                _n(
                    'One job is still outstanding. It runs in the background; if it is still here in a few minutes, this site\'s scheduled tasks are not running.',
                    'Some jobs are still outstanding. They run in the background; if they are still here in a few minutes, this site\'s scheduled tasks are not running.',
                    count($pending),
                    'dono'
                )
            ),
            esc_url($url),
            esc_html__('Finish them now', 'dono')
        );
    }
}
