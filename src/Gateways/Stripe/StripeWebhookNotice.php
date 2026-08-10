<?php

declare(strict_types=1);

namespace Dono\Gateways\Stripe;

use Dono\Recurring\RecurringPlan;

/**
 * Tells an admin that Stripe webhooks are being rejected.
 *
 * verifyWebhookSignature fails closed, so without the signing secret every
 * delivery is refused: recurring renewals, async payment confirmations and
 * account updates never process, and nothing else says so.
 *
 * @version 1.0.0
 */
final class StripeWebhookNotice
{
    public function __construct(
        private readonly StripeAccount $account,
        private readonly StripeApi $api,
    ) {
    }

    public function register(): void
    {
        add_action('admin_notices', [$this, 'render']);
    }

    /**
     * Separate from render() so the rule can be tested: the rendering half is
     * behind an is_admin() gate that a test environment never satisfies.
     */
    public function shouldWarn(): bool
    {
        if (! $this->account->isConnected()) {
            return false;
        }
        if ($this->api->hasWebhookSecret()) {
            return false;
        }

        // Switching Stripe off stops it being offered on a form; it does not
        // stop a subscription already running there from billing, and those
        // renewals arrive by webhook. So this goes quiet only when the gateway
        // is off AND nothing is left that still depends on the deliveries.
        if ($this->switchedOn()) {
            return true;
        }

        return RecurringPlan::query()
            ->where('gateway', 'stripe')
            ->whereIn('status', ['active', 'past_due', 'paused'])
            ->exists();
    }

    /**
     * The Settings flag itself, not GatewayManager::isOn: that also asks
     * whether the gateway is registered and can charge, and the question here
     * is only whether an admin switched it off.
     */
    private function switchedOn(): bool
    {
        $cfg = get_option('dono_gateway_config', []);
        if (! is_array($cfg)) {
            return true;
        }

        return (bool) ($cfg['stripe']['enabled'] ?? true);
    }

    public function render(): void
    {
        if (! current_user_can('manage_options') || ! $this->shouldWarn()) {
            return;
        }

        echo '<div class="notice dono-admin-notice" role="alert" style="'
            . 'border:1px solid #e5e7eb;border-left:3px solid #b54708;border-radius:8px;'
            . 'background:#fffaf5;color:#b54708;padding:11px 14px;'
            . 'font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Oxygen,Ubuntu,sans-serif;'
            . 'font-size:13px;line-height:1.45;">'
            . '<strong>Dono:</strong> '
            . esc_html__('Stripe is connected but its webhook signing secret is missing. Recurring renewals, payment confirmations, and account updates will not process until you add it under Dono, Settings, Payment gateways.', 'dono')
            . '</div>';
    }
}
