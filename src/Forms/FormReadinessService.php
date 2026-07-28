<?php

declare(strict_types=1);

namespace Dono\Forms;

use Dono\Gateways\GatewayManager;
use Dono\Gateways\Stripe\StripeAccount;
use Dono\Gateways\TestMode;
use Dono\Settings\SettingsService;

/**
 * Builds the "is this form safe to publish?" checklist for the form editor.
 *
 * @version 1.0.0
 */
final class FormReadinessService
{
    /** @internal */
    public function __construct(
        private SettingsService $settings,
        private GatewayManager $gateways,
        private StripeAccount $stripeAccount,
        private TestMode $testMode,
    ) {
    }

    /**
     * Run every readiness check for the given form.
     *
     * @return list<array{
     *   id:string,
     *   status:'pass'|'warn'|'fail',
     *   label:string,
     *   detail?:string,
     *   action_url?:string,
     *   action_label?:string,
     * }>
     */
    public function check(Form $form): array
    {
        return [
            $this->gatewayCheck($form),
            $this->testModeCheck($form),
            $this->receiptSenderCheck(),
            $this->receiptTemplateCheck(),
            $this->httpsCheck($form),
            $this->recurringSupportCheck($form),
            $this->recurringToggleFrequenciesCheck($form),
        ];
    }

    /**
     * The recurring-toggle block silently vanishes at render time when fewer
     * than 2 effective frequencies are configured. Surface that as a warn so
     * the author notices instead of saving + publishing a phantom block.
     *
     * @return array<string,mixed>
     */
    private function recurringToggleFrequenciesCheck(Form $form): array
    {
        $blocks = parse_blocks((string) $form->blocks);
        $stub   = $this->findRecurringToggleAttrs($blocks);
        if ($stub === null) {
            return [
                'id'     => 'recurring-toggle-frequencies',
                'status' => 'pass',
                'label'  => __('No recurring toggle on this form', 'dono'),
            ];
        }
        $freqs = Blocks\RecurringToggleBlock::normalizeFrequencies($stub['frequencies'] ?? Blocks\RecurringToggleBlock::DEFAULT_FREQUENCIES);
        if (! in_array('one-time', $freqs, true) && ! empty($freqs)) {
            array_unshift($freqs, 'one-time');
        }
        if (count($freqs) >= 2) {
            return [
                'id'     => 'recurring-toggle-frequencies',
                'status' => 'pass',
                'label'  => __('Recurring toggle offers at least two frequencies', 'dono'),
            ];
        }
        return [
            'id'           => 'recurring-toggle-frequencies',
            'status'       => 'warn',
            'label'        => __('Recurring toggle has fewer than two frequencies', 'dono'),
            'detail'       => __('The block needs at least two frequencies to render; with one or none, it is silently hidden on the form. Add a frequency in the block settings.', 'dono'),
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $blocks
     * @return array<string,mixed>|null
     */
    private function findRecurringToggleAttrs(array $blocks): ?array
    {
        foreach ($blocks as $b) {
            if (! is_array($b)) continue;
            if (($b['blockName'] ?? null) === 'dono/recurring-toggle') {
                return is_array($b['attrs'] ?? null) ? $b['attrs'] : [];
            }
            $inner = $b['innerBlocks'] ?? null;
            if (is_array($inner)) {
                $hit = $this->findRecurringToggleAttrs($inner);
                if ($hit !== null) return $hit;
            }
        }
        return null;
    }

    /** @return array<string,mixed> */
    private function gatewayCheck(Form $form): array
    {
        $gw = $this->settings->get('gateways');
        $allowed = $this->formAllowedGateways($form);
        // Stripe lives behind the Connect onboarding flow, not the gateway
        // option's `enabled` flag, so treat a connected Connect account as
        // "stripe enabled" here.
        $stripeEnabled = $this->stripeAccount->isConnected();
        $enabled = [];
        foreach ($this->gateways->all() as $id => $gateway) {
            if ($allowed !== [] && ! in_array($id, $allowed, true)) continue;
            $on = $id === 'stripe' ? $stripeEnabled : ! empty($gw[$id]['enabled']);
            if ($on) {
                $enabled[] = $gateway->label();
            }
        }

        if (empty($enabled)) {
            return [
                'id'           => 'gateway',
                'status'       => 'fail',
                'label'        => __('No payment gateway enabled for this form', 'dono'),
                'detail'       => __('Donors cannot complete a donation without a gateway. Enable one, or widen the gateways this form allows.', 'dono'),
                'action_url'   => admin_url('admin.php?page=dono-settings#gateways'),
                'action_label' => __('Configure gateways', 'dono'),
            ];
        }

        if ($stripeEnabled && ($allowed === [] || in_array('stripe', $allowed, true))) {
            if (! $this->stripeAccount->canCharge()) {
                return [
                    'id'           => 'gateway',
                    'status'       => 'fail',
                    'label'        => __('Stripe account is not ready to take donations', 'dono'),
                    'detail'       => __('Finish the remaining Stripe verification steps or donations will fail.', 'dono'),
                    'action_url'   => admin_url('admin.php?page=dono-settings#gateways'),
                    'action_label' => __('Open settings', 'dono'),
                ];
            }
        }

        return [
            'id'     => 'gateway',
            'status' => 'pass',
            /* translators: %s: comma-separated list of enabled gateway names. */
            'label'  => sprintf(__('Payment gateways enabled: %s', 'dono'), implode(', ', $enabled)),
        ];
    }

    /** @return array<string,mixed> */
    private function testModeCheck(Form $form): array
    {
        if (! $this->testMode->forForm($form)) {
            return [
                'id'     => 'test-mode',
                'status' => 'pass',
                'label'  => __('Test mode is off', 'dono'),
            ];
        }

        return [
            'id'           => 'test-mode',
            'status'       => 'warn',
            'label'        => __('This form is in test mode', 'dono'),
            'detail'       => __('Donations will not be charged and are excluded from reporting. Turn test mode off before going live.', 'dono'),
            'action_url'   => admin_url('admin.php?page=dono-settings#gateways'),
            'action_label' => __('Open settings', 'dono'),
        ];
    }

    /**
     * Public because the org-wide readiness check asks the same question and
     * neither of these depends on a form.
     *
     * @return array<string,mixed>
     */
    public function receiptSenderCheck(): array
    {
        $email = $this->settings->get('email');
        $from  = trim((string) ($email['from_email'] ?? ''));
        if ($from === '' || ! is_email($from)) {
            // Without a valid sender WP falls back to wordpress@<host> and fails SPF/DKIM.
            return [
                'id'           => 'receipt-sender',
                'status'       => 'warn',
                'label'        => __('Receipt sender uses WordPress fallback', 'dono'),
                'detail'       => __('Set a sender on your site domain (e.g. donations@yoursite.org) so receipts pass SPF and DKIM instead of being marked as spam.', 'dono'),
                'action_url'   => admin_url('admin.php?page=dono-settings#email'),
                'action_label' => __('Set sender', 'dono'),
            ];
        }
        return [
            'id'     => 'receipt-sender',
            'status' => 'pass',
            /* translators: %s: from-email address used for donation receipts. */
            'label'  => sprintf(__('Receipts sent from %s', 'dono'), $from),
        ];
    }

    /** @return array<string,mixed> */
    public function receiptTemplateCheck(): array
    {
        $email = $this->settings->get('email');
        $tpl   = is_array($email['templates']['donation_receipt'] ?? null)
            ? $email['templates']['donation_receipt']
            : [];
        if (empty($tpl['enabled'])) {
            return [
                'id'           => 'receipt-template',
                'status'       => 'fail',
                'label'        => __('Donation receipt email is disabled', 'dono'),
                'detail'       => __('Donors will not receive a confirmation after paying.', 'dono'),
                'action_url'   => admin_url('admin.php?page=dono-settings#email'),
                'action_label' => __('Enable template', 'dono'),
            ];
        }
        return [
            'id'     => 'receipt-template',
            'status' => 'pass',
            'label'  => __('Donation receipt email is enabled', 'dono'),
        ];
    }

    /** @return array<string,mixed> */
    private function httpsCheck(Form $form): array
    {
        if (is_ssl()) {
            return [
                'id'     => 'https',
                'status' => 'pass',
                'label'  => __('Site is served over HTTPS', 'dono'),
            ];
        }
        // Test mode doesn't move real money, so HTTPS is a warning rather than
        // a publish-blocker. Live mode still fails because live Stripe charges
        // would be rejected by Stripe on non-HTTPS sites.
        if ($this->testMode->forForm($form)) {
            return [
                'id'     => 'https',
                'status' => 'warn',
                'label'  => __('Site is not on HTTPS', 'dono'),
                'detail' => __('Fine for test mode, but live Stripe charges will be rejected. Install an SSL certificate before turning test mode off.', 'dono'),
            ];
        }
        return [
            'id'     => 'https',
            'status' => 'fail',
            'label'  => __('Site is not on HTTPS', 'dono'),
            'detail' => __('Stripe rejects live charges on non-HTTPS sites. Install an SSL certificate before publishing.', 'dono'),
        ];
    }

    /** @return array<string,mixed> */
    private function recurringSupportCheck(Form $form): array
    {
        if (! $this->formOffersRecurring($form)) {
            return [
                'id'     => 'recurring-gateway',
                'status' => 'pass',
                'label'  => __('Form is one-time only', 'dono'),
            ];
        }

        $gatewaySettings = $this->settings->get('gateways');
        $recurringCapable = [];
        foreach ($this->gateways->all() as $id => $gateway) {
            if (in_array('recurring', $gateway->frequencies(), true)) {
                $recurringCapable[$id] = $gateway;
            }
        }
        // Stripe is enabled through Connect, not the gateways `enabled` flag -
        // mirror gatewayCheck() so a Connect-only install does not falsely
        // report "no recurring gateway" while gatewayCheck() says it is enabled.
        $stripeEnabled    = $this->stripeAccount->isConnected();
        $allowed = $this->formAllowedGateways($form);
        $enabledRecurring = [];
        foreach ($recurringCapable as $id => $gateway) {
            if ($allowed !== [] && ! in_array($id, $allowed, true)) continue;
            $on = $id === 'stripe' ? $stripeEnabled : ! empty($gatewaySettings[$id]['enabled']);
            if ($on) {
                $enabledRecurring[$id] = $gateway;
            }
        }

        if (! empty($enabledRecurring)) {
            return [
                'id'     => 'recurring-gateway',
                'status' => 'pass',
                'label'  => __('Recurring donations are supported', 'dono'),
            ];
        }

        if (empty($recurringCapable)) {
            return [
                'id'           => 'recurring-gateway',
                'status'       => 'fail',
                'label'        => __('No gateway supports recurring donations', 'dono'),
                'detail'       => __('None of the installed gateways can charge recurring donations. Remove the recurring-toggle block from this form, or install a gateway that supports recurring.', 'dono'),
                'action_url'   => admin_url('admin.php?page=dono-settings#gateways'),
                'action_label' => __('Open gateways', 'dono'),
            ];
        }

        $names = [];
        foreach ($recurringCapable as $g) { $names[] = $g->label(); }
        return [
            'id'           => 'recurring-gateway',
            'status'       => 'fail',
            'label'        => __('None of your enabled gateways supports recurring', 'dono'),
            'detail'       => sprintf(
                /* translators: %s: comma-separated list of recurring-capable gateway names. */
                __('Enable one of %s in Settings → Gateways, or remove the recurring-toggle block from this form.', 'dono'),
                implode(', ', $names)
            ),
            'action_url'   => admin_url('admin.php?page=dono-settings#gateways'),
            'action_label' => __('Open gateways', 'dono'),
        ];
    }

    /** @return list<string> the form's allowed gateway ids, or [] for no restriction */
    private function formAllowedGateways(Form $form): array
    {
        $allowed = $form->settings['gateways']['allowed'] ?? null;
        if (! is_array($allowed)) return [];
        return array_values(array_filter(array_map('strval', $allowed), static fn ($s) => $s !== ''));
    }

    /** Whether the form exposes more than one donation frequency. */
    private function formOffersRecurring(Form $form): bool
    {
        $blocks = parse_blocks((string) $form->blocks);
        return $this->walkForRecurring($blocks);
    }

    /**
     * Recursively scan blocks for a recurring-toggle offering two or more frequencies.
     *
     * @param array<int,array<string,mixed>> $blocks
     */
    private function walkForRecurring(array $blocks): bool
    {
        foreach ($blocks as $b) {
            if (! is_array($b)) continue;
            if (($b['blockName'] ?? null) === 'dono/recurring-toggle') {
                $freqs = Blocks\RecurringToggleBlock::normalizeFrequencies($b['attrs']['frequencies'] ?? Blocks\RecurringToggleBlock::DEFAULT_FREQUENCIES);
                if (! in_array('one-time', $freqs, true) && ! empty($freqs)) {
                    array_unshift($freqs, 'one-time');
                }
                if (count($freqs) >= 2) return true;
            }
            $inner = $b['innerBlocks'] ?? null;
            if (is_array($inner) && $this->walkForRecurring($inner)) {
                return true;
            }
        }
        return false;
    }
}
