<?php

declare(strict_types=1);

namespace Dono\Donors\Portal;

use Dono\Admin\ExtensionAssets;
use Dono\Campaigns\Styling\StylePresets;
use Dono\Campaigns\Styling\Tokens;
use Dono\Foundation\Helpers\Money;
use Dono\Foundation\Hooks\HookProvider;

/**
 * Registers and renders the [dono_donor_portal] shortcode.
 *
 * @version 1.0.0
 */
final class PortalShortcode extends HookProvider
{
    private const TAG    = 'dono_donor_portal';
    private const HANDLE = 'dono-donor-portal';

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

            // Extension seam: register the window.dono.tabs registry and let
            // add-ons enqueue their portal tabs (e.g. dono-p2p "My fundraising").
            ExtensionAssets::enqueue('portal');
            // Org currency config on window.dono so @dono/ui formatAmount renders
            // money the same on the front end as in admin (the portal + add-on
            // tabs read it). Admin gets this via AdminGlobals.
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
                // Same rule as the donation form: only logged-in users get a
                // REST nonce. Anonymous visitors send none, so a page-cached
                // portal never carries a stale nonce that WP's cookie check
                // would 403 (which would break magic-link sign-in itself).
                // Portal auth is the session cookie + X-Dono-Csrf, not this.
                'nonce' => is_user_logged_in() ? wp_create_nonce('wp_rest') : '',
            ]);
            wp_set_script_translations(self::HANDLE, 'dono', DONO_DIR . 'languages');
        }
        $cssPath = DONO_DIR . 'build/donor-portal/index.css';
        if (file_exists($cssPath)) {
            // Version by file mtime, not DONO_VERSION, so a rebuilt stylesheet
            // busts the browser cache without a plugin version bump.
            wp_enqueue_style(self::HANDLE, DONO_URL . 'build/donor-portal/index.css', [], (string) filemtime($cssPath));
            wp_add_inline_style(self::HANDLE, $this->brandCss());
        }
    }

    /** @param array<string,mixed>|string $atts */
    public function render($atts = []): string
    {
        $this->enqueue();
        return '<div id="dono-donor-portal" class="dono-donor-portal"></div>';
    }

    /**
     * Org preset tokens as CSS custom properties. Output lands in a style
     * block; chars that could escape the rule are filtered defensively.
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
