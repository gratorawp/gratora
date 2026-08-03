<?php

declare(strict_types=1);

namespace Dono\Admin;

use Dono\Foundation\Auth\Capabilities;
use Dono\Foundation\Hooks\HookProvider;
use Dono\Vendor\Queryable\DB;
use WP_Admin_Bar;

/**
 * Admin bar badge while donations are not real money.
 *
 * Test mode is invisible from the admin otherwise: the donations list fills
 * with rows, the totals move, and nothing says the card was never charged. The
 * expensive version of finding out is a launched campaign that took nothing.
 *
 * Two states, because Dono has two switches. The org-wide flag is loud. A
 * single form left behind after a launch is quieter and worse, so it is called
 * out separately rather than folded into the same message.
 *
 * @version 1.0.0
 */
final class TestModeBadge extends HookProvider
{
    protected function actions(): array
    {
        return [
            'admin_bar_menu'      => ['addNode', 90, 1],
            'admin_head'          => 'styles',
            'wp_head'             => 'styles',
        ];
    }

    public function addNode(WP_Admin_Bar $bar): void
    {
        if (! $this->visibleToCurrentUser()) {
            return;
        }

        $orgWide = $this->orgWide();
        $forms   = $orgWide ? 0 : $this->formsInTestMode();

        if (! $orgWide && $forms === 0) {
            return;
        }

        $title = $orgWide
            ? __('Dono test mode', 'dono')
            : sprintf(
                /* translators: %d: how many published forms are in test mode. */
                _n('%d form in test mode', '%d forms in test mode', $forms, 'dono'),
                $forms
            );

        $bar->add_node([
            'id' => 'dono-test-mode',
            // top-secondary puts it on the right, beside the account menu,
            // where the eye already goes. The default group buries it among
            // the site and comment links.
            'parent' => 'top-secondary',
            'title'  => '<span class="dono-test-mode-badge">' . esc_html($title) . '</span>',
            'href'   => esc_url(admin_url('admin.php?page=dono-settings#payments')),
            'meta'  => [
                'title' => $orgWide
                    ? __('No card is charged and these donations stay out of your reporting. Turn this off before you go live.', 'dono')
                    : __('These forms take no real money. Every other form on the site does.', 'dono'),
            ],
        ]);
    }

    public function styles(): void
    {
        if (! is_admin_bar_showing() || ! $this->visibleToCurrentUser()) {
            return;
        }
        if (! $this->orgWide() && $this->formsInTestMode() === 0) {
            return;
        }
        ?>
        <style>
            #wpadminbar #wp-admin-bar-dono-test-mode .dono-test-mode-badge {
                display: inline-block;
                padding: 0 10px;
                background: #b97a05;
                color: #fff;
                font-weight: 600;
                line-height: 32px;
            }
            #wpadminbar #wp-admin-bar-dono-test-mode:hover .dono-test-mode-badge { background: #a06a04; }
            #wpadminbar #wp-admin-bar-dono-test-mode > .ab-item { padding: 0; }
        </style>
        <?php
    }

    private function orgWide(): bool
    {
        $cfg = get_option('dono_gateway_config', []);

        return is_array($cfg) && ! empty($cfg['test_mode']);
    }

    /**
     * Published forms carrying their own test_mode. Counted per request rather
     * than cached: the count has to be right the moment someone flips it off,
     * and a stale badge is worse than no badge.
     */
    private function formsInTestMode(): int
    {
        // whereRaw first: it contributes no AND connector, so anything before
        // it runs straight into the fragment and the SQL will not parse.
        //
        // test_mode is written as a JSON boolean today; the IN also matches a
        // 1, so a future writer storing an int does not silently stop counting.
        return (int) DB::table('dono_forms')
            ->whereRaw("JSON_EXTRACT(settings, '\$.test_mode') IN (CAST('true' AS JSON), CAST('1' AS JSON))")
            ->where('status', 'published')
            ->count();
    }

    /** Whoever cannot see a donation has no use for the state of the till. */
    private function visibleToCurrentUser(): bool
    {
        return is_user_logged_in() && Capabilities::userCan('dono_view_donations');
    }
}
