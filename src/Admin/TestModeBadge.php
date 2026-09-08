<?php

declare(strict_types=1);

namespace FundKit\Admin;

use FundKit\Foundation\Auth\Capabilities;
use FundKit\Foundation\Hooks\HookProvider;
use FundKit\Vendor\Queryable\DB;
use WP_Admin_Bar;

/**
 * Admin bar badge while donations are not real money.
 *
 * Test mode is invisible from the admin otherwise: the donations list fills
 * with rows, the totals move, and nothing says the card was never charged. The
 * expensive version of finding out is a launched campaign that took nothing.
 *
 * Two states, because FundKit has two switches. The org-wide flag is loud. A
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
            // The admin bar appears on frontend pages too.
            'admin_enqueue_scripts' => 'styles',
            'wp_enqueue_scripts'    => 'styles',
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

        // Name FundKit to distinguish other plugins’ test badges.
        $title = $orgWide
            ? __('Fundraising Toolkit Test Mode Active', 'fundraising-toolkit')
            : sprintf(
                /* translators: %d: how many published forms are in test mode. */
                _n('%d Fundraising Toolkit Form in Test Mode', '%d Fundraising Toolkit Forms in Test Mode', $forms, 'fundraising-toolkit'),
                $forms
            );

        $bar->add_node([
            'id' => 'fundkit-test-mode',
            'parent' => 'top-secondary',
            'title'  => '<span class="fundkit-test-mode-badge">' . $this->icon() . esc_html($title) . '</span>',
            'href'   => esc_url(admin_url('admin.php?page=fundkit-settings&tab=gateways')),
            'meta'  => [
                'title' => $orgWide
                    ? __('No card is charged and these donations stay out of your reporting. Turn this off before you go live.', 'fundraising-toolkit')
                    : __('These forms take no real money. Every other form on the site does.', 'fundraising-toolkit'),
            ],
        ]);
    }

    /**
     * Lucide flask-conical.
     *
     * @since 1.0.0
     */
    private function icon(): string
    {
        return '<svg class="fundkit-test-mode-badge__icon" viewBox="0 0 24 24" fill="none"'
            . ' stroke="currentColor" stroke-width="2" stroke-linecap="round"'
            . ' stroke-linejoin="round" aria-hidden="true" focusable="false">'
            . '<path d="M14 2v6a2 2 0 0 0 .245.96l5.51 10.08A2 2 0 0 1 18 22H6a2 2 0'
            . ' 0 1-1.755-2.96l5.51-10.08A2 2 0 0 0 10 8V2"/>'
            . '<path d="M6.453 15h11.094"/>'
            . '<path d="M8.5 2h7"/>'
            . '</svg>';
    }

    private const BADGE_CSS = <<<'CSS'
    /* Sized and coloured to sit alongside the other fundraising
       plugins' test badges rather than compete with them: a chip inset
       from the bar, not a full-height block. */
    #wpadminbar #wp-admin-bar-fundkit-test-mode .fundkit-test-mode-badge {
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
    #wpadminbar #wp-admin-bar-fundkit-test-mode .fundkit-test-mode-badge__icon {
        width: 13px;
        height: 13px;
        flex: none;
    }
    #wpadminbar #wp-admin-bar-fundkit-test-mode:hover .fundkit-test-mode-badge { background: #d68a37; }
    #wpadminbar #wp-admin-bar-fundkit-test-mode > .ab-item { padding: 0; }
CSS;

    /** @since 1.0.0 */
    public function styles(): void
    {
        if (! is_admin_bar_showing() || ! $this->visibleToCurrentUser()) {
            return;
        }
        if (! $this->orgWide() && $this->formsInTestMode() === 0) {
            return;
        }

        // Use a registered handle for inline CSS.
        wp_register_style('fundkit-test-mode-badge', false, [], FUNDKIT_VERSION);
        wp_enqueue_style('fundkit-test-mode-badge');
        wp_add_inline_style('fundkit-test-mode-badge', self::BADGE_CSS);
    }

    /** @since 1.0.0 */
    private function orgWide(): bool
    {
        $cfg = get_option('fundkit_gateway_config', []);

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
        // Put whereRaw first; it adds no AND connector. Guard LONGTEXT with JSON_VALID and
        // compare unquoted text for MySQL/MariaDB compatibility. Accept boolean and numeric
        // test flags.
        return (int) DB::table('fundkit_forms')
            ->whereRaw(
                "JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(settings), settings, NULL), "
                . "'\$.test_mode')) IN ('true', '1')"
            )
            ->where('status', 'published')
            ->count();
    }

    /** @since 1.0.0 */
    private function visibleToCurrentUser(): bool
    {
        return is_user_logged_in() && Capabilities::userCan('fundkit_view_donations');
    }
}
