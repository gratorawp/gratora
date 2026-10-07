<?php

declare(strict_types=1);

namespace Gratora\Admin\Pages;

use Gratora\Admin\Addons\AddonsCatalog;
use Gratora\Foundation\Hooks\HookProvider;
use Gratora\Foundation\License\LicenseService;

/** @since 1.1.0 */
final class AddonsPage extends HookProvider
{
    private const PAGE_ID    = 'gratora-addons';
    private const CAPABILITY = 'gratora_access_settings';
    private const HANDLE     = 'gratora-admin-addons';
    private const BUILD_DIR  = 'build/admin/addons';
    private const MENU_STYLE = 'gratora-addons-menu-link';

    private const MENU_LINK = '#adminmenu .wp-submenu a[href$="page=gratora-addons"]:not(.current, :hover, :focus)';

    /** The schemes whose menu the amber reads on. */
    private const AMBER_SCHEMES = ['modern', 'fresh', 'blue', 'midnight', 'sunrise', 'ectoplasm', 'ocean', 'coffee'];

    /** Before WordPress 7.0 these paint the menu a paler ground: the amber reads at 1.4 to 4.4 to 1 on it. */
    private const PALE_BEFORE_7 = ['blue', 'sunrise', 'ocean', 'coffee'];

    /** @since 1.1.3 */
    public function __construct(private AddonsCatalog $catalog, private LicenseService $license)
    {
    }

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
        wp_add_inline_style(self::MENU_STYLE, self::menuLinkCss());
    }

    /**
     * Each admin colour scheme paints the menu its own ground, so the schemes
     * are named: the light one takes the darker ink, and a scheme not listed
     * keeps the link as WordPress draws it. Under the pointer, with focus and
     * as the current page the link looks like every other.
     */
    private static function menuLinkCss(): string
    {
        $schemes = is_wp_version_compatible('7.0')
            ? self::AMBER_SCHEMES
            : array_diff(self::AMBER_SCHEMES, self::PALE_BEFORE_7);

        $named = implode(', ', array_map(static fn (string $scheme): string => '.admin-color-' . $scheme, $schemes));

        return ':is(' . $named . ') ' . self::MENU_LINK . " { color: #e89940; }\n"
            . '.admin-color-light ' . self::MENU_LINK . ' { color: #b45309; }';
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
     * What the screen shows. An offer is for a site that has bought nothing yet.
     *
     * @return array{
     *   source:'remote'|'builtin',
     *   addons:list<array{slug:string,name:string,description:string,icon:string,url:string,free:bool,status:string,activateUrl:string,plan:string}>,
     *   plans:list<array{slug:string,name:string,summary:string,sites:int,count:int,url:string}>,
     *   offer:array{text:string,url:string}|null,
     * }
     *
     * @since 1.1.3
     */
    public function screen(): array
    {
        $catalog = $this->catalog->get();

        $plans = [];
        foreach ($catalog['plans'] as $plan) {
            $plans[] = [
                'slug'    => $plan['slug'],
                'name'    => $plan['name'],
                'summary' => $plan['summary'],
                'sites'   => $plan['sites'],
                'count'   => count($plan['addons']),
                'url'     => $plan['url'],
            ];
        }

        return [
            'source' => $catalog['source'],
            'addons' => $this->onThisSite($catalog['addons'], $catalog['plans']),
            'plans'  => $plans,
            'offer'  => $this->license->isPro() ? null : $catalog['offer'],
        ];
    }

    /**
     * Every add-on, whether this site has it, and the smallest plan that
     * includes it.
     *
     * @return list<array{slug:string,name:string,description:string,icon:string,url:string,free:bool,status:string,activateUrl:string,plan:string}>
     *
     * @since 1.1.0
     */
    public function addons(): array
    {
        return $this->screen()['addons'];
    }

    /**
     * A plugin is recognised by its main file, whatever folder it was unpacked into.
     *
     * @param  list<array{slug:string,file:string,name:string,description:string,icon:string,url:string,free:bool}> $addons
     * @param  list<array{name:string,addons:list<string>}> $plans
     * @return list<array{slug:string,name:string,description:string,icon:string,url:string,free:bool,status:string,activateUrl:string,plan:string}>
     */
    private function onThisSite(array $addons, array $plans): array
    {
        if (! function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $files = array_keys(get_plugins());
        $out   = [];

        foreach ($addons as $addon) {
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

            $plan = '';
            foreach ($addon['free'] ? [] : $plans as $candidate) {
                if (in_array($addon['slug'], $candidate['addons'], true)) {
                    $plan = $candidate['name'];
                    break;
                }
            }

            unset($addon['file']);
            $out[] = $addon + ['status' => $status, 'activateUrl' => $activateUrl, 'plan' => $plan];
        }

        return $out;
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
        wp_localize_script(self::HANDLE, 'gratoraAddons', $this->screen());

        wp_enqueue_style(
            self::HANDLE,
            GRATORA_URL . 'build/admin/addons.css',
            [],
            (string) (@filemtime(GRATORA_DIR . 'build/admin/addons.css') ?: GRATORA_VERSION)
        );
        wp_style_add_data(self::HANDLE, 'rtl', 'replace');
    }
}
