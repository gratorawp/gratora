<?php

declare(strict_types=1);

namespace FundKit\Forms;

use FundKit\Gateways\GatewayManager;
use FundKit\Gateways\Stripe\StripeAccount;
use FundKit\Gateways\TestMode;
use FundKit\Settings\SettingsService;

/** @since 1.0.0 */
final class FormReadinessService
{
    /** @since 1.0.0 */
    public function __construct(
        private SettingsService $settings,
        private GatewayManager $gateways,
        private StripeAccount $stripeAccount,
        private TestMode $testMode,
    ) {
    }

    /**
     * @return list<array{
     *   id:string,
     *   status:'pass'|'warn'|'fail',
     *   label:string,
     *   detail?:string,
     *   action_url?:string,
     *   action_label?:string,
     * }>
     *
     * @since 1.0.0
     */
    public function check(Form $form): array
    {
        return [
            $this->gatewayCheck($form),
            $this->gatewayBlockCheck($form),
            $this->testModeCheck($form),
            $this->receiptSenderCheck(),
            $this->receiptTemplateCheck(),
            $this->httpsCheck($form),
            $this->recurringSupportCheck($form),
            $this->recurringToggleFrequenciesCheck($form),
        ];
    }

    /**
     * The recurring-toggle block silently vanishes at render time with fewer
     * than 2 effective frequencies, so warn instead of publishing a phantom block.
     *
     * @since 1.0.0
     */
    private function recurringToggleFrequenciesCheck(Form $form): array
    {
        $blocks = parse_blocks((string) $form->blocks);
        $stub   = $this->findRecurringToggleAttrs($blocks);
        if ($stub === null) {
            return [
                'id'     => 'recurring-toggle-frequencies',
                'status' => 'pass',
                'label'  => __('No recurring toggle on this form', 'fundraising-toolkit'),
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
                'label'  => __('Recurring toggle offers at least two frequencies', 'fundraising-toolkit'),
            ];
        }
        return [
            'id'           => 'recurring-toggle-frequencies',
            'status'       => 'warn',
            'label'        => __('Recurring toggle has fewer than two frequencies', 'fundraising-toolkit'),
            'detail'       => __('The block needs at least two frequencies to render; with one or none, it is silently hidden on the form. Add a frequency in the block settings.', 'fundraising-toolkit'),
        ];
    }

    /**
     * Blocks render where they are dropped, so a form offering a choice of
     * gateway with no gateways block picks one for the donor without asking.
     * Say so to the author rather than letting it surface as a donor complaint.
     *
     * @since 1.0.0
     */
    private function gatewayBlockCheck(Form $form): array
    {
        $allowed = $this->formAllowedGateways($form);
        $offered = [];
        foreach ($this->gateways->all() as $id => $gateway) {
            if ($allowed !== [] && ! in_array($id, $allowed, true)) continue;
            if ($this->gateways->isOn($id)) {
                $offered[] = $gateway->label();
            }
        }

        if (count($offered) < 2 || $this->hasBlock(parse_blocks((string) $form->blocks), 'fundkit/payment-gateways')) {
            return [
                'id'     => 'gateway-block',
                'status' => 'pass',
                'label'  => __('Donors can pick how to pay', 'fundraising-toolkit'),
            ];
        }

        return [
            'id'           => 'gateway-block',
            'status'       => 'warn',
            'label'        => __('This form does not let the donor choose a payment method', 'fundraising-toolkit'),
            'detail'       => sprintf(
                /* translators: %s: comma-separated list of enabled gateway names. */
                __('%s are available, but the form has no payment methods block, so donors get whichever comes first. Add the block where you want the choice to appear.', 'fundraising-toolkit'),
                implode(', ', $offered)
            ),
            'action_url'   => admin_url('admin.php?page=fundkit-forms&form=' . (int) $form->id),
            'action_label' => __('Edit the form', 'fundraising-toolkit'),
        ];
    }

    /** @since 1.0.0 */
    private function hasBlock(array $blocks, string $name): bool
    {
        foreach ($blocks as $b) {
            if (! is_array($b)) continue;
            if (($b['blockName'] ?? null) === $name) return true;
            $inner = $b['innerBlocks'] ?? null;
            if (is_array($inner) && $this->hasBlock($inner, $name)) return true;
        }
        return false;
    }

    /** @since 1.0.0 */
    private function findRecurringToggleAttrs(array $blocks): ?array
    {
        foreach ($blocks as $b) {
            if (! is_array($b)) continue;
            if (($b['blockName'] ?? null) === 'fundkit/recurring-toggle') {
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

    /** @since 1.0.0 */
    private function gatewayCheck(Form $form): array
    {
        $allowed = $this->formAllowedGateways($form);

        // Specific before generic: the generic message would send the admin
        // looking for a gateway to switch on that is already there.
        if ($this->stripeAccount->isConnected()
            && ($allowed === [] || in_array('stripe', $allowed, true))
            && ! $this->stripeAccount->canCharge()) {
            return [
                'id'           => 'gateway',
                'status'       => 'fail',
                'label'        => __('Stripe account is not ready to take donations', 'fundraising-toolkit'),
                'detail'       => __('Finish the remaining Stripe verification steps or donations will fail.', 'fundraising-toolkit'),
                'action_url'   => admin_url('admin.php?page=fundkit-settings#gateways'),
                'action_label' => __('Open settings', 'fundraising-toolkit'),
            ];
        }

        // GatewayManager::isOn() is what the donor form resolves against, so
        // asking it here is what keeps this check honest.
        $enabled = [];
        foreach ($this->gateways->all() as $id => $gateway) {
            if ($allowed !== [] && ! in_array($id, $allowed, true)) continue;
            if ($this->gateways->isOn($id)) {
                $enabled[] = $gateway->label();
            }
        }

        if (empty($enabled)) {
            return [
                'id'           => 'gateway',
                'status'       => 'fail',
                'label'        => __('No payment gateway enabled for this form', 'fundraising-toolkit'),
                'detail'       => __('Donors cannot complete a donation without a gateway. Enable one, or widen the gateways this form allows.', 'fundraising-toolkit'),
                'action_url'   => admin_url('admin.php?page=fundkit-settings#gateways'),
                'action_label' => __('Configure gateways', 'fundraising-toolkit'),
            ];
        }

        return [
            'id'     => 'gateway',
            'status' => 'pass',
            /* translators: %s: comma-separated list of enabled gateway names. */
            'label'  => sprintf(__('Payment gateways enabled: %s', 'fundraising-toolkit'), implode(', ', $enabled)),
        ];
    }

    /** @since 1.0.0 */
    private function testModeCheck(Form $form): array
    {
        if (! $this->testMode->forForm($form)) {
            return [
                'id'     => 'test-mode',
                'status' => 'pass',
                'label'  => __('Test mode is off', 'fundraising-toolkit'),
            ];
        }

        // Two different switches raise this warning, and only one of them is
        // in Settings. Sending an author there for the form's own checkbox
        // showed them an org switch already off, and nothing they touched
        // cleared the warning.
        $settings = is_array($form->settings ?? null) ? $form->settings : [];
        $ownSwitch = ! empty($settings['test_mode']);

        return [
            'id'           => 'test-mode',
            'status'       => 'warn',
            'label'        => __('This form is in test mode', 'fundraising-toolkit'),
            'detail'       => $ownSwitch
                ? __('Donations will not be charged and are excluded from reporting. Turn test mode off in this form\'s gateway settings before going live.', 'fundraising-toolkit')
                : __('Donations will not be charged and are excluded from reporting. The whole site is in test mode; turn it off before going live.', 'fundraising-toolkit'),
            'action_url'   => $ownSwitch
                ? admin_url('admin.php?page=fundkit-forms&form=' . (int) $form->id)
                : admin_url('admin.php?page=fundkit-settings#gateways'),
            'action_label' => $ownSwitch
                ? __('Open this form', 'fundraising-toolkit')
                : __('Open settings', 'fundraising-toolkit'),
        ];
    }

    /**
     * Public because the org-wide readiness check asks the same question.
     *
     * @since 1.0.0
     */
    public function receiptSenderCheck(): array
    {
        $email = $this->settings->get('email');
        $from  = trim((string) ($email['from_email'] ?? ''));
        if ($from === '' || ! is_email($from)) {
            // A sender address is a From header, and no header decides
            // deliverability: SPF is evaluated against the envelope sender the
            // host stamps, and DKIM needs a signature the site cannot produce
            // through PHP mail(). Setting this makes receipts identifiable, not
            // deliverable, and the copy must not promise otherwise.
            return [
                'id'           => 'receipt-sender',
                'status'       => 'warn',
                'label'        => __('Receipt sender uses WordPress fallback', 'fundraising-toolkit'),
                'detail'       => __('Set a sender on your site domain, for example donations@yoursite.org, so receipts are recognisable. Delivery itself depends on your mail transport: see Settings, Email.', 'fundraising-toolkit'),
                'action_url'   => admin_url('admin.php?page=fundkit-settings#email'),
                'action_label' => __('Set sender', 'fundraising-toolkit'),
            ];
        }
        return [
            'id'     => 'receipt-sender',
            'status' => 'pass',
            /* translators: %s: from-email address used for donation receipts. */
            'label'  => sprintf(__('Receipts sent from %s', 'fundraising-toolkit'), $from),
            // Deliberately not a clean bill of health. This check can only see
            // the address; a receipt sent from a perfectly valid one still
            // bounces when the host has no authenticated transport, which is
            // the usual shape of "no donor ever got a receipt".
            'detail' => __('This confirms the address only. Whether receipts arrive depends on your mail transport.', 'fundraising-toolkit'),
        ];
    }

    /** @since 1.0.0 */
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
                'label'        => __('Donation receipt email is disabled', 'fundraising-toolkit'),
                'detail'       => __('Donors will not receive a confirmation after paying.', 'fundraising-toolkit'),
                'action_url'   => admin_url('admin.php?page=fundkit-settings#email'),
                'action_label' => __('Enable template', 'fundraising-toolkit'),
            ];
        }
        return [
            'id'     => 'receipt-template',
            'status' => 'pass',
            'label'  => __('Donation receipt email is enabled', 'fundraising-toolkit'),
        ];
    }

    /** @since 1.0.0 */
    private function httpsCheck(Form $form): array
    {
        if (is_ssl()) {
            return [
                'id'     => 'https',
                'status' => 'pass',
                'label'  => __('Site is served over HTTPS', 'fundraising-toolkit'),
            ];
        }
        // Test mode moves no real money, so HTTPS only warns. Live mode fails:
        // Stripe rejects live charges on non-HTTPS sites.
        if ($this->testMode->forForm($form)) {
            return [
                'id'     => 'https',
                'status' => 'warn',
                'label'  => __('Site is not on HTTPS', 'fundraising-toolkit'),
                'detail' => __('Fine for test mode, but live Stripe charges will be rejected. Install an SSL certificate before turning test mode off.', 'fundraising-toolkit'),
            ];
        }
        return [
            'id'     => 'https',
            'status' => 'fail',
            'label'  => __('Site is not on HTTPS', 'fundraising-toolkit'),
            'detail' => __('Stripe rejects live charges on non-HTTPS sites. Install an SSL certificate before publishing.', 'fundraising-toolkit'),
        ];
    }

    /** @since 1.0.0 */
    private function recurringSupportCheck(Form $form): array
    {
        if (! $this->formOffersRecurring($form)) {
            return [
                'id'     => 'recurring-gateway',
                'status' => 'pass',
                'label'  => __('Form is one-time only', 'fundraising-toolkit'),
            ];
        }

        $recurringCapable = [];
        foreach ($this->gateways->all() as $id => $gateway) {
            if (in_array('recurring', $gateway->frequencies(), true)) {
                $recurringCapable[$id] = $gateway;
            }
        }
        $allowed = $this->formAllowedGateways($form);
        $enabledRecurring = [];
        foreach ($recurringCapable as $id => $gateway) {
            if ($allowed !== [] && ! in_array($id, $allowed, true)) continue;
            if ($this->gateways->isOn($id)) {
                $enabledRecurring[$id] = $gateway;
            }
        }

        if (! empty($enabledRecurring)) {
            return [
                'id'     => 'recurring-gateway',
                'status' => 'pass',
                'label'  => __('Recurring donations are supported', 'fundraising-toolkit'),
            ];
        }

        if (empty($recurringCapable)) {
            return [
                'id'           => 'recurring-gateway',
                'status'       => 'fail',
                'label'        => __('No gateway supports recurring donations', 'fundraising-toolkit'),
                'detail'       => __('None of the installed gateways can charge recurring donations. Remove the recurring-toggle block from this form, or install a gateway that supports recurring.', 'fundraising-toolkit'),
                'action_url'   => admin_url('admin.php?page=fundkit-settings#gateways'),
                'action_label' => __('Open gateways', 'fundraising-toolkit'),
            ];
        }

        $names = [];
        foreach ($recurringCapable as $g) { $names[] = $g->label(); }
        return [
            'id'           => 'recurring-gateway',
            'status'       => 'fail',
            'label'        => __('None of your enabled gateways supports recurring', 'fundraising-toolkit'),
            'detail'       => sprintf(
                /* translators: %s: comma-separated list of recurring-capable gateway names. */
                __('Enable one of %s in Settings → Payment gateways, or remove the recurring-toggle block from this form.', 'fundraising-toolkit'),
                implode(', ', $names)
            ),
            'action_url'   => admin_url('admin.php?page=fundkit-settings#gateways'),
            'action_label' => __('Open gateways', 'fundraising-toolkit'),
        ];
    }

    /**
     * [] means no restriction.
     *
     * @since 1.0.0
     */
    private function formAllowedGateways(Form $form): array
    {
        $allowed = $form->settings['gateways']['allowed'] ?? null;
        if (! is_array($allowed)) return [];
        return array_values(array_filter(array_map('strval', $allowed), static fn ($s) => $s !== ''));
    }

    /** @since 1.0.0 */
    private function formOffersRecurring(Form $form): bool
    {
        $blocks = parse_blocks((string) $form->blocks);
        return $this->walkForRecurring($blocks);
    }

    /** @since 1.0.0 */
    private function walkForRecurring(array $blocks): bool
    {
        foreach ($blocks as $b) {
            if (! is_array($b)) continue;
            if (($b['blockName'] ?? null) === 'fundkit/recurring-toggle') {
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
