<?php

declare(strict_types=1);

namespace Gratora\Onboarding;

use Gratora\Admin\CurrentPage;
use Gratora\Foundation\Hooks\HookProvider;

/**
 * Shows the way to the setup after the first activation: a notice on the
 * Plugins screen, a Set up link in the plugin's row, and the setup opened on
 * the first other admin screen.
 *
 * @since 1.0.0
 */
final class Onboarding extends HookProvider
{
    public const OPTION = 'gratora_onboarding_status';

    /**
     * Set at the first activation, spent on the first admin screen after it.
     *
     * @since 1.0.0
     */
    public const GREET = 'gratora_onboarding_greet';

    /**
     * Set with the greeting, spent when the Plugins screen has shown it once.
     *
     * @since unreleased
     */
    public const NOTICE = 'gratora_onboarding_notice';

    private const SETUP_CHECK = 'admin.php?page=gratora-settings#setup';

    /** @since 1.0.0 */
    protected function actions(): array
    {
        return [
            'admin_init'    => 'maybeRedirect',
            'admin_notices' => 'activationNotice',
        ];
    }

    /** @since unreleased */
    protected function filters(): array
    {
        return ['plugin_action_links_' . plugin_basename(GRATORA_FILE) => 'setUpLink'];
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
     * Separate the decision from wp_safe_redirect/exit for testing.
     *
     * @since 1.0.0
     */
    public function shouldRedirect(): bool
    {
        if (wp_doing_ajax() || wp_doing_cron()) return false;
        if (! current_user_can('manage_options')) return false;

        // A redirect answers a GET. Anything else is carrying a body that only
        // its own handler knows how to finish, including other plugins'.
        if (isset($_SERVER['REQUEST_METHOD']) && strtoupper(sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD']))) !== 'GET') return false;

        // pagenow is set in wp-includes/vars.php, long before admin_init.
        // get_current_screen() is not: core calls set_current_screen() after
        // admin_init has already run, so it reads null here and every test
        // against it passes through without deciding anything.
        if (in_array((string) ($GLOBALS['pagenow'] ?? ''), self::PASS, true)) return false;

        if ((string) get_option(self::OPTION, '') !== 'pending') return false;

        if (CurrentPage::slug() === OnboardingPage::PAGE_ID) return false;

        // Once. The wizard keeps its menu item, and one that reappears on every
        // screen until it is finished is a plugin holding the admin hostage.
        return get_option(self::GREET, false) !== false;
    }

    /** @since 1.0.0 */
    public function maybeRedirect(): void
    {
        if (CurrentPage::slug() === OnboardingPage::PAGE_ID) {
            if (current_user_can('manage_options')) self::spendGreeting();
            return;
        }

        if (! $this->shouldRedirect()) return;

        self::spendGreeting();
        wp_safe_redirect(admin_url('admin.php?page=' . OnboardingPage::PAGE_ID));
        exit;
    }

    /** @since 1.0.0 */
    public static function maybeSeedOnActivation(): void
    {
        if (get_option(self::OPTION, null) === null) {
            add_option(self::OPTION, 'pending', '', false);
        }
    }

    /**
     * Called where WordPress switches the plugin on for one site, the first time.
     *
     * @since unreleased
     */
    public static function greetAfterActivation(): void
    {
        if ((string) get_option(self::OPTION, '') !== 'pending') return;

        update_option(self::GREET, '1', true);
        update_option(self::NOTICE, '1', true);
    }

    private static function spendGreeting(): void
    {
        delete_option(self::GREET);
        delete_option(self::NOTICE);
    }

    /**
     * Right after Activate, on the Plugins screen, once.
     *
     * @since unreleased
     */
    public function activationNotice(): void
    {
        if ((string) ($GLOBALS['pagenow'] ?? '') !== 'plugins.php' || is_network_admin()) return;
        if (! current_user_can('manage_options')) return;
        if (get_option(self::NOTICE, false) === false) return;

        delete_option(self::NOTICE);

        $text = sprintf(
            /* translators: %s: the name of the plugin's admin menu, "Fundraising". Use the translation of that menu name. */
            __('Gratora is on. Next, make your donation page and try a test donation. It takes about two minutes. You will find Gratora under %s in the menu.', 'gratora-donation-platform'),
            __('Fundraising', 'gratora-donation-platform')
        );

        wp_admin_notice(
            '<p>' . esc_html($text) . '</p><p><a class="button button-primary" href="'
                . esc_url(admin_url('admin.php?page=' . OnboardingPage::PAGE_ID)) . '">'
                . esc_html__('Set up Gratora', 'gratora-donation-platform') . '</a></p>',
            [
                'type'               => 'info',
                'dismissible'        => true,
                'paragraph_wrap'     => false,
                'additional_classes' => ['gratora-activation-notice'],
            ]
        );
    }

    /**
     * The setup while it is still to do, the setup check after it.
     *
     * @param  array<string,string> $links
     * @return array<string,string>
     *
     * @since unreleased
     */
    public function setUpLink(array $links): array
    {
        $pending = (string) get_option(self::OPTION, '') === 'pending';
        $path    = $pending ? 'admin.php?page=' . OnboardingPage::PAGE_ID : self::SETUP_CHECK;

        if (! current_user_can($pending ? 'manage_options' : 'gratora_access_settings')) {
            return $links;
        }

        return [
            'set-up' => sprintf('<a href="%s">%s</a>', esc_url(admin_url($path)), esc_html__('Set up', 'gratora-donation-platform')),
        ] + $links;
    }
}
