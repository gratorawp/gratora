<?php

declare(strict_types=1);

namespace Gratora\Donors\Portal;

use Gratora\Admin\ExtensionAssets;
use Gratora\Campaigns\Styling\CampaignStyleResolver;
use Gratora\Campaigns\Styling\Ink;
use Gratora\Campaigns\Styling\StylePresets;
use Gratora\Campaigns\Styling\Tokens;
use Gratora\Donations\AntiSpamGuard;
use Gratora\Foundation\Helpers\Money;
use Gratora\Foundation\Hooks\HookProvider;

/**
 * Mounts the donor portal app on the [gratora_donor_portal] shortcode.
 *
 * @since 1.0.0
 */
final class PortalShortcode extends HookProvider
{
    private const TAG    = 'gratora_donor_portal';
    private const HANDLE = 'gratora-donor-portal';

    /** @since 1.0.0 */
    public function __construct(private AntiSpamGuard $spam)
    {
    }

    /** @since 1.0.0 */
    protected function actions(): array
    {
        return [
            'init'               => 'register',
            'wp_enqueue_scripts' => 'maybeEnqueue',
        ];
    }

    /** @since 1.0.0 */
    public function register(): void
    {
        add_shortcode(self::TAG, [$this, 'render']);
    }

    /** @since 1.0.0 */
    public function maybeEnqueue(): void
    {
        if (! is_singular()) return;
        global $post;
        if (! $post || ! has_shortcode((string) $post->post_content, self::TAG)) return;
        $this->enqueue();
    }

    /** @since 1.0.0 */
    private function enqueue(): void
    {
        $assetPath = GRATORA_DIR . 'build/donor-portal/index/index.asset.php';
        if (file_exists($assetPath)) {
            $asset = require $assetPath;

            // Extension seam: registers window.gratora.tabs so add-ons can enqueue
            // their own portal tabs.
            ExtensionAssets::enqueue('portal');
            // Org currency config on window.gratora so formatAmount renders
            // money the same on the front end as in admin.
            wp_add_inline_script(
                ExtensionAssets::HANDLE,
                'window.gratora = window.gratora || {};'
                . 'window.gratora.default_currency = ' . wp_json_encode(Money::defaultCurrency()) . ';'
                . 'window.gratora.number_format = ' . wp_json_encode(Money::jsNumberFormat()) . ';'
            );
            $deps   = $asset['dependencies'] ?? [];
            $deps[] = ExtensionAssets::HANDLE;

            wp_enqueue_script(
                self::HANDLE,
                GRATORA_URL . 'build/donor-portal/index/index.js',
                $deps,
                $asset['version']      ?? GRATORA_VERSION,
                true
            );
            wp_localize_script(self::HANDLE, 'gratoraPortal', [
                'rest'  => esc_url_raw($this->restBase(rest_url('gratora/v1/portal/'))),
                // Only logged-in users get a REST nonce, so a page-cached
                // portal never carries a stale one that WP's cookie check
                // would 403. Portal auth is the session cookie + X-Gratora-Csrf.
                'nonce' => is_user_logged_in() ? wp_create_nonce('wp_rest') : '',
                // Signing up and asking for a link write without any session to
                // check, and this proves the caller loaded the page.
                'token' => $this->spam->mintPortalToken(),
                // So the picture field can refuse an oversized file before
                // sending it, and name the real limit rather than a guess.
                'avatarMaxBytes' => \Gratora\Donors\DonorAvatarUploader::maxBytes(),
                'avatarMaxLabel' => size_format(\Gratora\Donors\DonorAvatarUploader::maxBytes()),
            ]);
            wp_set_script_translations(self::HANDLE, 'gratora', GRATORA_DIR . 'languages');
        }
        $cssPath = GRATORA_DIR . 'build/donor-portal/index.css';
        if (file_exists($cssPath)) {
            // Versioned by file mtime, so a rebuilt stylesheet busts the
            // browser cache without a plugin version bump.
            wp_enqueue_style(self::HANDLE, GRATORA_URL . 'build/donor-portal/index.css', [], (string) filemtime($cssPath));
            wp_style_add_data(self::HANDLE, 'rtl', 'replace');
            wp_add_inline_style(self::HANDLE, $this->brandCss());
        }
    }

    /**
     * The base the portal's own fetch talks to. rest_url() answers with
     * home_url's host, so on an install that serves this page on both the apex
     * and www, the fetch from whichever one home_url is not is cross-origin:
     * the browser drops the session cookie /portal/exchange sets, and the
     * donor's single-use link is spent on a session that never holds. Asking
     * the host the page was actually served from keeps it same-origin.
     *
     * Host is a header the caller writes, and this page is cached, so only the
     * www label is ever swapped. Anything wider would let one poisoned request
     * leave a foreign REST base in the cache for everybody after it.
     *
     * @since 1.0.0
     */
    private function restBase(string $restUrl): string
    {
        $parts  = (array) wp_parse_url($restUrl);
        $host   = strtolower((string) ($parts['host'] ?? ''));
        $served = strtolower((string) preg_replace('/:\d+$/', '', sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'] ?? ''))));

        if ($host === '' || $served === '' || $served === $host) return $restUrl;
        if ($served !== 'www.' . $host && $host !== 'www.' . $served) return $restUrl;

        return ($parts['scheme'] ?? 'https') . '://' . $served
            . (isset($parts['port']) ? ':' . $parts['port'] : '')
            . (string) ($parts['path'] ?? '')
            . (isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '');
    }

    /** @since 1.0.0 */
    public function render($atts = []): string
    {
        $this->enqueue();
        return '<div id="gratora-donor-portal" class="gratora-donor-portal"></div>';
    }

    /**
     * Output lands in a style block, so chars that could escape the rule are
     * filtered.
     *
     * @since 1.0.0
     */
    private function brandCss(): string
    {
        // Through the resolver, not by hand: merging the default preset over the
        // catalogue skips the derivations the form and the campaign page get,
        // so one brand rendered a legible checkout and an illegible account.
        $tokens = (new CampaignStyleResolver())->resolveForCampaign(null);
        $vars = [];
        foreach ($tokens as $k => $v) {
            if (! is_string($v) || $v === '') continue;
            if (preg_match('/[;{}<>\\\\]/', $v)) continue;
            $name = preg_replace('/[^a-z0-9_-]/i', '', (string) $k);
            if ($name === '') continue;
            $vars[] = '--' . $name . ': ' . $v . ';';
        }
        if (empty($vars)) return '';

        $derived = Ink::declarationsFor((string) ($tokens['gratora-accent'] ?? ''))
            . Ink::softDeclarations($tokens)
            . Ink::fieldDeclarations($tokens);

        return '.gratora-donor-portal{' . implode(' ', $vars) . $derived . '}';
    }
}
