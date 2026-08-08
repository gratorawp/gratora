<?php

declare(strict_types=1);

namespace Dono\Donors\Portal;

use Dono\Admin\ExtensionAssets;
use Dono\Campaigns\Styling\StylePresets;
use Dono\Campaigns\Styling\Tokens;
use Dono\Donations\AntiSpamGuard;
use Dono\Foundation\Helpers\Money;
use Dono\Foundation\Hooks\HookProvider;

final class PortalShortcode extends HookProvider
{
    private const TAG    = 'dono_donor_portal';
    private const HANDLE = 'dono-donor-portal';

    public function __construct(private AntiSpamGuard $spam)
    {
    }

    protected function actions(): array
    {
        return [
            'init'               => 'register',
            'wp_enqueue_scripts' => 'maybeEnqueue',
        ];
    }

    public function register(): void
    {
        add_shortcode(self::TAG, [$this, 'render']);
    }

    public function maybeEnqueue(): void
    {
        if (! is_singular()) return;
        global $post;
        if (! $post || ! has_shortcode((string) $post->post_content, self::TAG)) return;
        $this->enqueue();
    }

    private function enqueue(): void
    {
        $assetPath = DONO_DIR . 'build/donor-portal/index/index.asset.php';
        if (file_exists($assetPath)) {
            $asset = require $assetPath;

            // Extension seam: registers window.dono.tabs so add-ons can enqueue
            // their own portal tabs.
            ExtensionAssets::enqueue('portal');
            // Org currency config on window.dono so @dono/ui formatAmount
            // renders money the same on the front end as in admin.
            wp_add_inline_script(
                ExtensionAssets::HANDLE,
                'window.dono = window.dono || {};'
                . 'window.dono.default_currency = ' . wp_json_encode(Money::defaultCurrency()) . ';'
                . 'window.dono.number_format = ' . wp_json_encode(Money::jsNumberFormat()) . ';'
            );
            $deps   = $asset['dependencies'] ?? [];
            $deps[] = ExtensionAssets::HANDLE;

            wp_enqueue_script(
                self::HANDLE,
                DONO_URL . 'build/donor-portal/index/index.js',
                $deps,
                $asset['version']      ?? DONO_VERSION,
                true
            );
            wp_localize_script(self::HANDLE, 'donoPortal', [
                'rest'  => esc_url_raw(rest_url('dono/v1/portal/')),
                // Only logged-in users get a REST nonce, so a page-cached
                // portal never carries a stale one that WP's cookie check
                // would 403. Portal auth is the session cookie + X-Dono-Csrf.
                'nonce' => is_user_logged_in() ? wp_create_nonce('wp_rest') : '',
                // Signing up and asking for a link write without any session to
                // check, and this proves the caller loaded the page.
                'token' => $this->spam->mintPortalToken(),
                // So the picture field can refuse an oversized file before
                // sending it, and name the real limit rather than a guess.
                'avatarMaxBytes' => \Dono\Donors\DonorAvatarUploader::maxBytes(),
                'avatarMaxLabel' => size_format(\Dono\Donors\DonorAvatarUploader::maxBytes()),
            ]);
            wp_set_script_translations(self::HANDLE, 'dono', DONO_DIR . 'languages');
        }
        $cssPath = DONO_DIR . 'build/donor-portal/index.css';
        if (file_exists($cssPath)) {
            // Versioned by file mtime, so a rebuilt stylesheet busts the
            // browser cache without a plugin version bump.
            wp_enqueue_style(self::HANDLE, DONO_URL . 'build/donor-portal/index.css', [], (string) filemtime($cssPath));
            wp_style_add_data(self::HANDLE, 'rtl', 'replace');
            wp_add_inline_style(self::HANDLE, $this->brandCss());
        }
    }

    public function render($atts = []): string
    {
        $this->enqueue();
        return '<div id="dono-donor-portal" class="dono-donor-portal"></div>';
    }

    /**
     * Output lands in a style block, so chars that could escape the rule are
     * filtered.
     */
    private function brandCss(): string
    {
        $tokens = array_merge(
            Tokens::defaults(),
            StylePresets::tokensFor(StylePresets::defaultId()),
        );
        $vars = [];
        foreach ($tokens as $k => $v) {
            if (! is_string($v) || $v === '') continue;
            if (preg_match('/[;{}<>\\\\]/', $v)) continue;
            $name = preg_replace('/[^a-z0-9_-]/i', '', (string) $k);
            if ($name === '') continue;
            $vars[] = '--' . $name . ': ' . $v . ';';
        }
        if (empty($vars)) return '';
        return '.dono-donor-portal{' . implode(' ', $vars) . '}';
    }
}
