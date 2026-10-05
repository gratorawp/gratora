<?php

declare(strict_types=1);

namespace Gratora\Admin\Pages;

use Gratora\Foundation\Hooks\HookProvider;

/** @since 1.1.0 */
final class AddonsPage extends HookProvider
{
    private const PAGE_ID    = 'gratora-addons';
    private const CAPABILITY = 'gratora_access_settings';
    private const HANDLE     = 'gratora-admin-addons';
    private const BUILD_DIR  = 'build/admin/addons';
    private const SITE       = 'https://gratora.net/';
    private const MENU_STYLE = 'gratora-addons-menu-link';

    /**
     * Each admin colour scheme paints the menu its own ground, so the schemes
     * are named: the light one takes the darker ink, and a scheme not listed
     * keeps the link as WordPress draws it. Under the pointer, with focus and
     * as the current page the link looks like every other.
     */
    private const MENU_LINK_CSS = <<<'CSS'
        :is(.admin-color-modern, .admin-color-fresh, .admin-color-blue, .admin-color-midnight, .admin-color-sunrise, .admin-color-ectoplasm, .admin-color-ocean, .admin-color-coffee) #adminmenu .wp-submenu a[href$="page=gratora-addons"]:not(.current, :hover, :focus) { color: #e89940; }
        .admin-color-light #adminmenu .wp-submenu a[href$="page=gratora-addons"]:not(.current, :hover, :focus) { color: #b45309; }
        CSS;

    /** @since 1.1.0 */
    protected function filters(): array
    {
        return ['gratora.admin.pages' => 'registerPage'];
    }

    /** @since 1.1.2 */
    protected function actions(): array
    {
        return ['admin_enqueue_scripts' => 'menuLinkStyle'];
    }

    /** @since 1.1.0 */
    public function registerPage(array $pages): array
    {
        $pages[] = [
            'id'         => self::PAGE_ID,
            'title'      => __('Add-ons', 'gratora-donation-platform'),
            'capability' => self::CAPABILITY,
            'position'   => 100,
            'render'     => [$this, 'render'],
        ];
        return $pages;
    }

    /**
     * The menu is on every admin screen, so the colour of its link loads on each.
     *
     * @since 1.1.2
     */
    public function menuLinkStyle(): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            return;
        }

        // Use a registered handle for inline CSS.
        wp_register_style(self::MENU_STYLE, false, [], GRATORA_VERSION);
        wp_enqueue_style(self::MENU_STYLE);
        wp_add_inline_style(self::MENU_STYLE, self::MENU_LINK_CSS);
    }

    /** @since 1.1.0 */
    public function render(): void
    {
        $this->enqueueAssets();
        ?>
        <div class="wrap">
            <hr class="wp-header-end" />
            <div id="gratora-admin-addons"></div>
        </div>
        <?php
    }

    /**
     * Every add-on, and whether this site has it. A plugin is recognised by its
     * main file, whatever folder it was unpacked into.
     *
     * @return list<array{slug:string,name:string,description:string,icon:string,url:string,free:bool,status:string,activateUrl:string}>
     *
     * @since 1.1.0
     */
    public function addons(): array
    {
        if (! function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $files = array_keys(get_plugins());
        $out   = [];

        foreach (self::catalog() as $addon) {
            $found  = array_values(array_filter($files, static fn (string $file): bool => basename($file) === $addon['file']));
            $active = array_filter($found, 'is_plugin_active');
            $status = $active !== [] ? 'active' : ($found !== [] ? 'installed' : 'available');

            $activateUrl = '';
            if ($status === 'installed' && current_user_can('activate_plugin', $found[0])) {
                $activateUrl = add_query_arg(
                    [
                        'action'   => 'activate',
                        'plugin'   => urlencode($found[0]),
                        '_wpnonce' => wp_create_nonce('activate-plugin_' . $found[0]),
                    ],
                    self_admin_url('plugins.php')
                );
            }

            unset($addon['file']);
            $out[] = $addon + ['status' => $status, 'activateUrl' => $activateUrl];
        }

        return $out;
    }

    /** @return list<array{slug:string,file:string,name:string,description:string,icon:string,url:string,free:bool}> */
    private static function catalog(): array
    {
        $paid = [
            ['peer-to-peer-fundraising', 'gratora-p2p.php', __('Peer-to-Peer', 'gratora-donation-platform'), __('Supporters raise money for you on their own pages, alone or in teams.', 'gratora-donation-platform'), 'users-round'],
            ['events', 'gratora-events.php', __('Event Tickets', 'gratora-donation-platform'), __('Sell tickets to fundraising events, then check guests in by phone.', 'gratora-donation-platform'), 'ticket'],
            ['ai-assistant', 'gratora-ai-assistant.php', __('AI Assistant', 'gratora-donation-platform'), __('Ask about your fundraising and make changes in plain language.', 'gratora-donation-platform'), 'sparkles'],
            ['payment-gateways', 'gratora-payment-gateways.php', __('Payment Gateways', 'gratora-donation-platform'), __('Take donations through Authorize.Net, Square, GoCardless, Moneris or Razorpay.', 'gratora-donation-platform'), 'credit-card'],
            ['conversion-tracking', 'gratora-conversion-tracking.php', __('Conversion Tracking', 'gratora-donation-platform'), __('Report completed donations to GA4, Google Ads and Meta, with amount and currency.', 'gratora-donation-platform'), 'chart-line'],
            ['connect', 'gratora-connect.php', _x('Connect', 'add-on name', 'gratora-donation-platform'), __('Send donation, donor and recurring events to signed webhooks, Slack and Mailchimp.', 'gratora-donation-platform'), 'webhook'],
            ['tributes', 'gratora-tributes.php', __('Tributes', 'gratora-donation-platform'), __('Donors dedicate a donation in honor or in memory of someone.', 'gratora-donation-platform'), 'rose'],
            ['gift-aid', 'gratora-gift-aid.php', __('Gift Aid', 'gratora-donation-platform'), __('Collect UK Gift Aid declarations on your forms and prepare your claim for HMRC.', 'gratora-donation-platform'), 'landmark'],
            ['donation-recovery', 'gratora-donation-recovery.php', __('Donation Recovery', 'gratora-donation-platform'), __('One reminder email to someone who started a donation and did not finish it.', 'gratora-donation-platform'), 'mail'],
        ];

        $catalog = [];
        foreach ($paid as [$slug, $file, $name, $description, $icon]) {
            $catalog[] = [
                'slug'        => $slug,
                'file'        => $file,
                'name'        => $name,
                'description' => $description,
                'icon'        => $icon,
                'url'         => self::SITE . 'add-ons/' . $slug . '/',
                'free'        => false,
            ];
        }

        $catalog[] = [
            'slug'        => 'give-importer',
            'file'        => 'gratora-give-importer.php',
            'name'        => __('GiveWP Importer', 'gratora-donation-platform'),
            'description' => __('Copy donors, donations, campaigns and recurring subscriptions from GiveWP into Gratora.', 'gratora-donation-platform'),
            'icon'        => 'import',
            'url'         => self::SITE . 'add-ons/#importer',
            'free'        => true,
        ];

        return $catalog;
    }

    /** @since 1.1.0 */
    private function enqueueAssets(): void
    {
        $assetPath = GRATORA_DIR . self::BUILD_DIR . '/index.asset.php';
        if (! file_exists($assetPath)) {
            return;
        }

        $asset = require $assetPath;

        wp_enqueue_script(
            self::HANDLE,
            GRATORA_URL . self::BUILD_DIR . '/index.js',
            $asset['dependencies'] ?? [],
            $asset['version']      ?? GRATORA_VERSION,
            true
        );

        wp_set_script_translations(self::HANDLE, 'gratora-donation-platform', GRATORA_DIR . 'languages');
        wp_localize_script(self::HANDLE, 'gratoraAddons', ['addons' => $this->addons()]);

        wp_enqueue_style(
            self::HANDLE,
            GRATORA_URL . 'build/admin/addons.css',
            [],
            (string) (@filemtime(GRATORA_DIR . 'build/admin/addons.css') ?: GRATORA_VERSION)
        );
        wp_style_add_data(self::HANDLE, 'rtl', 'replace');
    }
}
