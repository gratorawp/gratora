<?php

declare(strict_types=1);

namespace FundKit\Admin;

use FundKit\Foundation\Auth\Capabilities;
use FundKit\Foundation\Http\ClientIp;

/**
 * Tells an admin that every visitor looks like one address.
 *
 * A private REMOTE_ADDR arriving with a forwarded header can only mean
 * something in front is terminating the connection. Every per-address limit is
 * then a limit for the whole site at once: ten donation attempts per fifteen
 * minutes shared between every donor, which one caller can spend to close the
 * form for everyone, and which a busy campaign trips on its own.
 *
 * Nothing else would say so. The limits do not fail loudly; they refuse a
 * donor, who leaves.
 *
 * @since 1.0.0
 */
final class ProxyNotice
{
    /** @since 1.0.0 */
    public function register(): void
    {
        add_action('admin_notices', [$this, 'render']);
    }

    /**
     * Separate from render() so the rule can be tested: the rendering half is
     * behind an is_admin() gate a test environment never satisfies.
     *
     * @since 1.0.0
     */
    public function shouldWarn(): bool
    {
        return ClientIp::looksProxied();
    }

    /** @since 1.0.0 */
    public function render(): void
    {
        if (! $this->shouldWarn() || ! Capabilities::userCan('fundkit_manage_settings')) {
            return;
        }

        printf(
            '<div class="notice notice-warning"><p><strong>%s</strong> %s</p><p>%s</p></div>',
            esc_html__('Fundraising Toolkit: every visitor looks like one address.', 'fundraising-toolkit'),
            esc_html__(
                'This site is being reached through a CDN, load balancer or reverse proxy, and no trusted proxy has been declared. Spam limits count visitors by address, so they are counting the whole site as one visitor: donors can be refused because of somebody else, and one caller can close the donation form for everyone.',
                'fundraising-toolkit'
            ),
            esc_html__(
                'Add your proxy\'s address ranges under Settings, Data & privacy. Until then the limits still hold, they are just shared.',
                'fundraising-toolkit'
            )
        );
    }
}
