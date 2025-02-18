<?php

declare(strict_types=1);

namespace Dono\Onboarding;

use Dono\Foundation\Hooks\HookProvider;

/**
 * Redirects to the onboarding page while `dono_onboarding_status` is 'pending'.
 *
 * @version 1.0.0
 */
final class Onboarding extends HookProvider
{
    public const OPTION = 'dono_onboarding_status';

    protected function actions(): array
    {
        return ['admin_init' => 'maybeRedirect'];
    }

    public function maybeRedirect(): void
    {
        if (wp_doing_ajax() || wp_doing_cron()) return;
        if (! current_user_can('manage_options')) return;

        $status = (string) get_option(self::OPTION, '');
        if ($status !== 'pending') return;

        $page = is_string($_GET['page'] ?? null) ? (string) $_GET['page'] : '';
        if ($page === OnboardingPage::PAGE_ID) return;

        // Allow plugin-management screens so admins can deactivate without being trapped.
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen && in_array($screen->id, ['plugins', 'plugins-network', 'update'], true)) return;

        wp_safe_redirect(admin_url('admin.php?page=' . OnboardingPage::PAGE_ID));
        exit;
    }

    /** Seed the option on activation if it doesn't exist yet. */
    public static function maybeSeedOnActivation(): void
    {
        if (get_option(self::OPTION, null) === null) {
            add_option(self::OPTION, 'pending', '', false);
        }
    }
}
