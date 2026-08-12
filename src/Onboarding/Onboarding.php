<?php

declare(strict_types=1);

namespace Dono\Onboarding;

use Dono\Foundation\Hooks\HookProvider;

/**
 * Redirects to the onboarding page while `dono_onboarding_status` is 'pending'.
 *
 * @since 1.0.0
 */
final class Onboarding extends HookProvider
{
    public const OPTION = 'dono_onboarding_status';

    /** Set at activation, spent on the first admin page load. */
    private const GREET = 'dono_onboarding_greet';

    /** @since 1.0.0 */
    protected function actions(): array
    {
        return ['admin_init' => 'maybeRedirect'];
    }

    /**
     * Screens an admin has to be able to reach while onboarding is pending.
     * admin-post.php and admin-ajax.php are here because they are not screens at
     * all: they run other people's handlers, and a redirect drops the payload.
     */
    private const PASS = [
        'plugins.php',
        'plugin-install.php',
        'update.php',
        'update-core.php',
        'admin-post.php',
        'admin-ajax.php',
        'async-upload.php',
    ];

    /**
     * Kept separate from the redirect itself so the decision can be tested. The
     * sending cannot: wp_safe_redirect is followed by exit.
     *
     * @since 1.0.0
     */
    public function shouldRedirect(): bool
    {
        if (wp_doing_ajax() || wp_doing_cron()) return false;
        if (! current_user_can('manage_options')) return false;

        // A redirect answers a GET. Anything else is carrying a body that only
        // its own handler knows how to finish, including other plugins'.
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return false;

        // pagenow is set in wp-includes/vars.php, long before admin_init.
        // get_current_screen() is not: core calls set_current_screen() after
        // admin_init has already run, so it reads null here and every test
        // against it passes through without deciding anything.
        if (in_array((string) ($GLOBALS['pagenow'] ?? ''), self::PASS, true)) return false;

        if ((string) get_option(self::OPTION, '') !== 'pending') return false;

        $page = is_string($_GET['page'] ?? null) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if ($page === OnboardingPage::PAGE_ID) return false;

        // Once. The wizard keeps its menu item, and one that reappears on every
        // screen until it is finished is a plugin holding the admin hostage.
        return get_transient(self::GREET) !== false;
    }

    /** @since 1.0.0 */
    public function maybeRedirect(): void
    {
        if (! $this->shouldRedirect()) return;

        delete_transient(self::GREET);
        wp_safe_redirect(admin_url('admin.php?page=' . OnboardingPage::PAGE_ID));
        exit;
    }

    /** @since 1.0.0 */
    public static function maybeSeedOnActivation(): void
    {
        if (get_option(self::OPTION, null) === null) {
            add_option(self::OPTION, 'pending', '', false);
            set_transient(self::GREET, 1, 5 * MINUTE_IN_SECONDS);
        }
    }
}
