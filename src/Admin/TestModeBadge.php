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
 * @since 1.0.0
 */
final class TestModeBadge extends HookProvider
{
    /** @since 1.0.0 */
    protected function actions(): array
    {
        return [
            'admin_bar_menu'      => ['addNode', 90, 1],
            'admin_head'          => 'styles',
            'wp_head'             => 'styles',
        ];
    }

    /** @since 1.0.0 */
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

        // Both name Dono: other plugins put their own test badge in this bar,
        // and a bare "test mode" leaves the operator guessing whose till is open.
        $title = $orgWide
            ? __('Dono Test Mode Active', 'dono-fundraising-platform')
            : sprintf(
                /* translators: %d: how many published forms are in test mode. */
                _n('%d Dono Form in Test Mode', '%d Dono Forms in Test Mode', $forms, 'dono-fundraising-platform'),
                $forms
            );

        $bar->add_node([
            'id' => 'dono-test-mode',
            // top-secondary puts it on the right, beside the account menu,
            // where the eye already goes. The default group buries it among
            // the site and comment links.
            'parent' => 'top-secondary',
            'title'  => '<span class="dono-test-mode-badge">' . $this->icon() . esc_html($title) . '</span>',
            'href'   => esc_url(admin_url('admin.php?page=dono-settings&tab=gateways')),
            'meta'  => [
                'title' => $orgWide
                    ? __('No card is charged and these donations stay out of your reporting. Turn this off before you go live.', 'dono-fundraising-platform')
                    : __('These forms take no real money. Every other form on the site does.', 'dono-fundraising-platform'),
            ],
        ]);
    }

    /**
     * lucide flask-conical, inlined so the badge does not wait on an icon font.
     *
     * @since 1.0.0
     */
    private function icon(): string
    {
        return '<svg class="dono-test-mode-badge__icon" viewBox="0 0 24 24" fill="none"'
            . ' stroke="currentColor" stroke-width="2" stroke-linecap="round"'
            . ' stroke-linejoin="round" aria-hidden="true" focusable="false">'
            . '<path d="M14 2v6a2 2 0 0 0 .245.96l5.51 10.08A2 2 0 0 1 18 22H6a2 2 0'
            . ' 0 1-1.755-2.96l5.51-10.08A2 2 0 0 0 10 8V2"/>'
            . '<path d="M6.453 15h11.094"/>'
            . '<path d="M8.5 2h7"/>'
            . '</svg>';
    }

    /** @since 1.0.0 */
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
            /* Sized and coloured to sit alongside the other fundraising
               plugins' test badges rather than compete with them: a chip inset
               from the bar, not a full-height block. */
            #wpadminbar #wp-admin-bar-dono-test-mode .dono-test-mode-badge {
                display: inline-flex;
                align-items: center;
                gap: 4px;
                margin: 0 4px;
                padding: 0 8px;
                border-radius: 4px;
                background: #e89940;
                color: #fff;
                font-weight: 600;
                font-size: 12px;
                line-height: 25px;
                white-space: nowrap;
            }
            #wpadminbar #wp-admin-bar-dono-test-mode .dono-test-mode-badge__icon {
                width: 13px;
                height: 13px;
                flex: none;
            }
            #wpadminbar #wp-admin-bar-dono-test-mode:hover .dono-test-mode-badge { background: #d68a37; }
            #wpadminbar #wp-admin-bar-dono-test-mode > .ab-item { padding: 0; }
        </style>
        <?php
    }

    /** @since 1.0.0 */
    private function orgWide(): bool
    {
        $cfg = get_option('dono_gateway_config', []);

        return is_array($cfg) && ! empty($cfg['test_mode']);
    }

    /**
     * Published forms carrying their own test_mode. Counted per request rather
     * than cached: the count has to be right the moment someone flips it off,
     * and a stale badge is worse than no badge.
     *
     * @since 1.0.0
     */
    private function formsInTestMode(): int
    {
        // whereRaw first: it contributes no AND connector, so anything before
        // it runs straight into the fragment and the SQL will not parse.
        //
        // Compared as text rather than as JSON. MariaDB has no JSON type and
        // rejects CAST(x AS JSON) as a syntax error, and this runs on wp_head,
        // so the whole front end dies with it. JSON_UNQUOTE gives 'true' for a
        // JSON boolean on both engines.
        //
        // IF(JSON_VALID(...)) because the column is LONGTEXT, so nothing stops
        // a non-JSON string reaching it. MySQL raises an error on one, MariaDB
        // returns NULL; guarding makes both return NULL.
        //
        // test_mode is written as a JSON boolean today; matching '1' as well
        // means a future writer storing an int or a string does not silently
        // stop counting.
        return (int) DB::table('dono_forms')
            ->whereRaw(
                "JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(settings), settings, NULL), "
                . "'\$.test_mode')) IN ('true', '1')"
            )
            ->where('status', 'published')
            ->count();
    }

    /**
     * Whoever cannot see a donation has no use for the state of the till.
     *
     * @since 1.0.0
     */
    private function visibleToCurrentUser(): bool
    {
        return is_user_logged_in() && Capabilities::userCan('dono_view_donations');
    }
}
