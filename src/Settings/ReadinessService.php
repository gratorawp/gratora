<?php

declare(strict_types=1);

namespace Dono\Settings;

use ActionScheduler;
use ActionScheduler_Store;
use Dono\Async\AsyncDispatcher;
use Dono\Campaigns\Campaign;
use Dono\Donors\Portal\PortalPage;
use Dono\Forms\Form;
use Dono\Forms\FormReadinessService;
use Dono\Foundation\License\LicenseService;
use Dono\Gateways\GatewayManager;
use Dono\Gateways\PayPal\PayPalAccount;
use Dono\Gateways\Stripe\ApplePayDomain;
use Dono\Gateways\Stripe\StripeAccount;
use Dono\Gateways\Stripe\StripeApi;

/**
 * Answers "can this site take a donation today, and will the donor hear back?"
 *
 * Every row is derived, never stored: the wizard collects the facts nobody can
 * compute, this reports the ones that drift afterwards. A key gets rotated, a
 * page gets trashed, test mode stays on, the queue wedges. Each of those is
 * otherwise silent.
 *
 * @since 1.0.0
 */
final class ReadinessService
{
    public const PASS = 'pass';
    public const WARN = 'warn';
    public const FAIL = 'fail';

    /** Group ids, in the order the Setup screen renders them. */
    public const GROUPS = ['money', 'page', 'receipts', 'jobs', 'portal', 'licenses'];

    /** Past this, a queue is not merely busy. */
    private const QUEUE_STALE_SECONDS = 900;

    /** Enough to say "more than a hundred" without counting a runaway table. */
    private const QUEUE_PROBE = 101;

    /** @since 1.0.0 */
    public function __construct(
        private SettingsService $settings,
        private FormReadinessService $formReadiness,
        private StripeAccount $stripe,
        private StripeApi $stripeApi,
        private ApplePayDomain $applePay,
        private PayPalAccount $payPal,
        private GatewayManager $gateways,
        private PortalPage $portal,
        private LicenseService $license,
    ) {
    }

    /**
     * @return list<array{
     *   id:string,
     *   group:string,
     *   status:'pass'|'warn'|'fail',
     *   label:string,
     *   detail?:string,
     *   action_url?:string,
     *   action_label?:string,
     *   blocker?:bool,
     * }>
     *
     * @since 1.0.0
     */
    public function check(): array
    {
        $checks = array_merge(
            $this->moneyChecks(),
            [$this->donationPageCheck()],
            $this->receiptChecks(),
            [$this->backgroundJobsCheck()],
            [$this->donorPortalCheck()],
            $this->licenseChecks(),
        );

        return array_values(array_filter($checks));
    }

    /**
     * True when nothing on the list blocks a live donation.
     *
     * @since 1.0.0
     */
    public function isLive(array $checks): bool
    {
        foreach ($checks as $check) {
            if (! empty($check['blocker']) && $check['status'] === self::FAIL) {
                return false;
            }
        }

        return true;
    }

    // -- money ---------------------------------------------------------------

    /**
     * @return list<array<string,mixed>>
     *
     * @since 1.0.0
     */
    private function moneyChecks(): array
    {
        $checks = [$this->gatewayCheck(), $this->modeCheck(), $this->httpsCheck()];

        foreach ([$this->stripeWebhookCheck(), $this->payPalWebhookCheck(), $this->applePayCheck()] as $optional) {
            if ($optional !== null) {
                $checks[] = $optional;
            }
        }

        return $checks;
    }

    /**
     * @return array<string,mixed>
     *
     * @since 1.0.0
     */
    private function gatewayCheck(): array
    {
        // Asked of the registry, not a fixed list of names: an organization
        // whose only payment method ships in an add-on can still take money.
        //
        // isOn() rather than canCharge(): a gateway the org switched off is
        // never offered to a donor, so naming it here says money can arrive by
        // a route that is closed.
        $ready = [];
        foreach ($this->gateways->all() as $gateway) {
            if ($this->gateways->isOn($gateway->id())) {
                $ready[] = $gateway->label();
            }
        }
        if ($this->offlineReady()) $ready[] = __('offline donations', 'dono');

        $ready = array_values(array_unique($ready));

        if ($ready === []) {
            return $this->fail(
                'gateway',
                'money',
                __('No way to take a donation', 'dono'),
                __('Add keys for a payment gateway, or switch on offline donations and write the instructions donors will follow.', 'dono'),
                'gateways',
                __('Set up payments', 'dono'),
                true
            );
        }

        // Stripe is the only gateway that can hold keys and still refuse to
        // charge, so it is the only one worth calling out separately.
        if ($this->switchedOn('stripe') && $this->stripe->isConnected() && ! $this->stripe->canCharge()) {
            return $this->warn(
                'gateway',
                'money',
                __('Stripe has keys but cannot charge yet', 'dono'),
                __('Stripe has not enabled charges on this account. Finish the remaining verification steps in your Stripe dashboard.', 'dono'),
                'gateways',
                __('Open payments', 'dono')
            );
        }

        return $this->pass(
            'gateway',
            'money',
            sprintf(
                /* translators: %s: comma-separated list of payment methods that can take a donation. */
                __('Donations can be taken through %s', 'dono'),
                implode(', ', $ready)
            )
        );
    }

    /**
     * @return array<string,mixed>
     *
     * @since 1.0.0
     */
    private function modeCheck(): array
    {
        if ($this->testMode()) {
            return $this->warn(
                'mode',
                'money',
                __('Test mode is on for every form', 'dono'),
                __('No real payment is taken and these donations are excluded from reporting. Turn it off when you are ready to go live.', 'dono'),
                'gateways',
                __('Open payments', 'dono')
            );
        }

        // Live mode reading a test key charges nobody, and the donor sees a
        // success page for a payment that never happened.
        $missing = [];
        if ($this->switchedOn('stripe') && $this->stripe->isConnected() && ! $this->stripe->hasKeysFor(false)) $missing[] = __('Stripe', 'dono');
        if ($this->switchedOn('paypal') && $this->payPal->isConnected() && ! $this->payPal->hasKeysFor(false)) $missing[] = __('PayPal', 'dono');

        /**
         * A gateway that ships in an add-on owns its own credentials, so it
         * reports its own gap. Without this the screen is silent about a
         * processor set up for test and never for live.
         *
         * @param list<string> $missing gateway labels with no live credentials
         *
         * @since 1.0.0
         */
        $missing = (array) apply_filters('dono.readiness.live_mode_gaps', $missing);

        if ($missing !== []) {
            return $this->fail(
                'mode',
                'money',
                sprintf(
                    /* translators: %s: comma-separated list of gateway names holding only test keys. */
                    __('Live mode, but %s only has test keys', 'dono'),
                    implode(', ', $missing)
                ),
                __('Donations through it will fail. Add the live key pair, or turn test mode back on.', 'dono'),
                'gateways',
                __('Add live keys', 'dono'),
                true
            );
        }

        return $this->pass('mode', 'money', __('Live mode, with live keys on file', 'dono'));
    }

    /**
     * @return array<string,mixed>
     *
     * @since 1.0.0
     */
    private function httpsCheck(): array
    {
        if (is_ssl()) {
            return $this->pass('https', 'money', __('The site is served over HTTPS', 'dono'));
        }

        if ($this->testMode()) {
            return $this->warn(
                'https',
                'money',
                __('The site is not on HTTPS', 'dono'),
                __('Fine while you are rehearsing, but live card charges are rejected without it.', 'dono')
            );
        }

        return $this->fail(
            'https',
            'money',
            __('The site is not on HTTPS', 'dono'),
            __('Card gateways reject live charges on plain HTTP. Install a certificate before taking donations.', 'dono'),
            null,
            null,
            true
        );
    }

    /**
     * @return array<string,mixed>|null null when Stripe is not in play
     *
     * @since 1.0.0
     */
    private function stripeWebhookCheck(): ?array
    {
        if (! $this->switchedOn('stripe') || ! $this->stripe->isConnected()) {
            return null;
        }
        if ($this->stripeApi->hasWebhookSecret()) {
            return $this->pass('stripe-webhook', 'money', __('Stripe webhooks are signed', 'dono'));
        }

        return $this->warn(
            'stripe-webhook',
            'money',
            __('Stripe has no webhook signing secret', 'dono'),
            __('Without it Dono cannot trust what Stripe reports, so renewals, refunds and cancellations made in Stripe never reach this site.', 'dono'),
            'gateways',
            __('Add the secret', 'dono')
        );
    }

    /**
     * @return array<string,mixed>|null null when PayPal is not in play
     *
     * @since 1.0.0
     */
    private function payPalWebhookCheck(): ?array
    {
        if (! $this->switchedOn('paypal') || ! $this->payPal->isConnected()) {
            return null;
        }
        if ($this->payPal->webhookId($this->testMode()) !== '') {
            return $this->pass('paypal-webhook', 'money', __('PayPal webhooks are registered', 'dono'));
        }

        return $this->warn(
            'paypal-webhook',
            'money',
            __('PayPal has no webhook registered', 'dono'),
            __('Every PayPal notification will be rejected. Donations PayPal settles after checkout will stay unpaid, and refunds, disputes and renewals will not reach this site.', 'dono'),
            'gateways',
            __('Register the webhook', 'dono')
        );
    }

    /**
     * @return array<string,mixed>|null null when Stripe is not in play
     *
     * @since 1.0.0
     */
    private function applePayCheck(): ?array
    {
        if (! $this->switchedOn('stripe') || ! $this->stripe->isConnected()) {
            return null;
        }
        if ($this->applePay->isFileReady()) {
            return $this->pass('apple-pay', 'money', __('Apple Pay is verified for this domain', 'dono'));
        }

        return $this->warn(
            'apple-pay',
            'money',
            __('Apple Pay is not verified for this domain', 'dono'),
            __('The Apple Pay button simply does not appear until the domain association file is in place. Everything else keeps working.', 'dono'),
            'gateways',
            __('Verify the domain', 'dono')
        );
    }

    // -- the public page -----------------------------------------------------

    /**
     * @return array<string,mixed>
     *
     * @since 1.0.0
     */
    private function donationPageCheck(): array
    {
        $campaigns = Campaign::query()->where('status', 'published')->getAll();

        $live = [];
        foreach ($campaigns as $campaign) {
            $formId = (int) ($campaign->default_form_id ?? 0);
            if ($formId <= 0) {
                continue;
            }
            $form = Form::query()->where('id', $formId)->get();
            if ($form && (string) $form->status === 'published') {
                $live[] = $campaign;
            }
        }

        if ($live !== []) {
            return $this->pass(
                'donation-page',
                'page',
                sprintf(
                    /* translators: %d: number of campaigns with a published donation form. */
                    _n('%d campaign is live and can take donations', '%d campaigns are live and can take donations', count($live), 'dono'),
                    count($live)
                )
            );
        }

        // Publishing a campaign whose form is still a draft leaves its page as a
        // draft too, so the operator sees "published" and the public sees a 404.
        $detail = $campaigns === []
            ? __('Create a campaign, then publish it together with its donation form.', 'dono')
            : __('Your published campaigns have no published donation form, so their pages stay drafts and donors see nothing.', 'dono');

        return [
            'id'           => 'donation-page',
            'group'        => 'page',
            'status'       => self::FAIL,
            'label'        => __('No campaign a donor can reach', 'dono'),
            'detail'       => $detail,
            'action_url'   => admin_url('admin.php?page=dono-campaigns'),
            'action_label' => __('Open campaigns', 'dono'),
            'blocker'      => true,
        ];
    }

    // -- receipts ------------------------------------------------------------

    /**
     * @return list<array<string,mixed>>
     *
     * @since 1.0.0
     */
    private function receiptChecks(): array
    {
        $sender   = $this->formReadiness->receiptSenderCheck() + ['group' => 'receipts'];
        $template = $this->formReadiness->receiptTemplateCheck() + ['group' => 'receipts'];

        return [$sender, $template, $this->orgIdentityCheck()];
    }

    /**
     * What the receipt template actually prints in the header. A receipt with
     * no address is the kind of thing nobody notices until a donor's accountant
     * asks for one.
     *
     * @return array<string,mixed>
     *
     * @since 1.0.0
     */
    private function orgIdentityCheck(): array
    {
        $org   = $this->settings->get('org-profile');
        $lines = array_filter(array_map('trim', array_map('strval', (array) ($org['address_lines'] ?? []))));
        $name  = trim((string) ($org['legal_name'] ?? '')) !== ''
            ? trim((string) $org['legal_name'])
            : trim((string) ($org['name'] ?? ''));

        $missing = [];
        if ($name === '')  $missing[] = __('a name', 'dono');
        if ($lines === []) $missing[] = __('a postal address', 'dono');

        if ($this->showTaxId() && trim((string) ($org['tax_id'] ?? '')) === '') {
            $missing[] = __('a tax number', 'dono');
        }

        if ($missing === []) {
            return $this->pass('org-identity', 'receipts', __('Receipts carry your name and address', 'dono'));
        }

        return $this->warn(
            'org-identity',
            'receipts',
            sprintf(
                /* translators: %s: comma-separated list of missing organization details. */
                __('Receipts are missing %s', 'dono'),
                $this->join($missing)
            ),
            __('Receipts print your organization details at the top. Donors claiming tax relief usually need them.', 'dono'),
            'organization',
            __('Add the details', 'dono')
        );
    }

    // -- background jobs -----------------------------------------------------

    /**
     * Receipts are queued, not sent inline, so a wedged queue means donors stop
     * hearing back with nothing else looking wrong.
     *
     * @return array<string,mixed>
     *
     * @since 1.0.0
     */
    private function backgroundJobsCheck(): array
    {
        if (! class_exists(ActionScheduler::class) || ! function_exists('as_get_scheduled_actions')) {
            return $this->warn(
                'background-jobs',
                'jobs',
                __('Cannot tell whether background jobs are running', 'dono'),
                __('Action Scheduler is not available, so receipts and other queued work cannot be checked from here.', 'dono')
            );
        }

        $oldest = $this->oldestPendingJob();
        if ($oldest === null) {
            return $this->pass('background-jobs', 'jobs', __('No background work is waiting', 'dono'));
        }

        [$count, $ageSeconds] = $oldest;
        if ($ageSeconds < self::QUEUE_STALE_SECONDS) {
            return $this->pass(
                'background-jobs',
                'jobs',
                sprintf(
                    /* translators: %d: number of queued background jobs. */
                    _n('%d job is queued and moving', '%d jobs are queued and moving', $count, 'dono'),
                    $count
                )
            );
        }

        return $this->warn(
            'background-jobs',
            'jobs',
            sprintf(
                /* translators: %s: human-readable duration, e.g. "3 hours". */
                __('Background jobs have been waiting %s', 'dono'),
                human_time_diff(time() - $ageSeconds)
            ),
            __('Queued receipts and emails are not going out. WP-Cron is usually the cause: check that it is not disabled, or run it from a real cron job.', 'dono'),
            null,
            null
        );
    }

    /**
     * @return array{0:int,1:int}|null [count, age of the oldest in seconds]
     *
     * @since 1.0.0
     */
    private function oldestPendingJob(): ?array
    {
        $query = [
            'group'    => AsyncDispatcher::GROUP,
            'status'   => ActionScheduler_Store::STATUS_PENDING,
            'date'     => gmdate('Y-m-d H:i:s'),
            'date_compare' => '<=',
            'per_page' => self::QUEUE_PROBE,
            'orderby'  => 'date',
            'order'    => 'ASC',
        ];

        /** @var array<int,\ActionScheduler_Action> $actions */
        $actions = as_get_scheduled_actions($query);
        if ($actions === []) {
            return null;
        }

        $first    = reset($actions);
        $schedule = is_object($first) && method_exists($first, 'get_schedule') ? $first->get_schedule() : null;
        $date     = $schedule && method_exists($schedule, 'get_date') ? $schedule->get_date() : null;
        $due      = $date ? $date->getTimestamp() : time();

        return [count($actions), max(0, time() - $due)];
    }

    // -- donor portal --------------------------------------------------------

    /**
     * Magic-link and receipt emails already link here; PortalPage::url() falls
     * back to a pretty permalink whether or not the page exists, so a trashed
     * page turns every one of those links into a 404 silently.
     *
     * @return array<string,mixed>
     *
     * @since 1.0.0
     */
    private function donorPortalCheck(): array
    {
        if ($this->portal->resolve() !== 0) {
            return $this->pass('donor-portal', 'portal', __('The donor portal page is published', 'dono'));
        }

        return [
            'id'           => 'donor-portal',
            'group'        => 'portal',
            'status'       => self::FAIL,
            'label'        => __('The donor portal page is missing', 'dono'),
            'detail'       => __('Receipt and sign-in emails link to it. Until it is published, every one of those links leads to a 404.', 'dono'),
            'action_url'   => admin_url('edit.php?post_type=page'),
            'action_label' => __('Open pages', 'dono'),
            'blocker'      => true,
        ];
    }

    // -- licenses ------------------------------------------------------------

    /**
     * @return list<array<string,mixed>>
     *
     * @since 1.0.0
     */
    private function licenseChecks(): array
    {
        $addons = $this->license->entitlements();
        if ($addons === []) {
            return [];
        }

        $refused = $this->license->unlicensed();
        if ($refused !== []) {
            return [$this->warn(
                'licenses',
                'licenses',
                sprintf(
                    /* translators: %s: comma-separated add-on names. */
                    __('Your license does not cover %s', 'dono'),
                    $this->names($refused)
                ),
                __('They keep running, but they will not receive updates or security fixes.', 'dono'),
                'licenses',
                __('Manage licenses', 'dono')
            )];
        }

        $lapsing = $this->license->lapsing();
        if ($lapsing !== []) {
            return [$this->warn(
                'licenses',
                'licenses',
                sprintf(
                    /* translators: %s: comma-separated add-on names. */
                    __('The license for %s has lapsed', 'dono'),
                    $this->names($lapsing)
                ),
                __('Renew to keep receiving updates and security fixes.', 'dono'),
                'licenses',
                __('Manage licenses', 'dono')
            )];
        }

        // "unknown" means nobody asked the server, which is not the same as a
        // pass, so say what is installed rather than claiming it is licensed.
        $unchecked = array_filter($addons, static fn (array $a): bool => $a['status'] === 'unknown');
        if (count($unchecked) === count($addons)) {
            return [$this->warn(
                'licenses',
                'licenses',
                __('Your add-ons are not linked to a license key', 'dono'),
                __('They keep running, but they will not receive updates or security fixes.', 'dono'),
                'licenses',
                __('Add a key', 'dono')
            )];
        }

        return [$this->pass(
            'licenses',
            'licenses',
            sprintf(
                /* translators: %d: number of licensed add-ons. */
                _n('%d add-on is licensed', '%d add-ons are licensed', count($addons), 'dono'),
                count($addons)
            )
        )];
    }

    // -- helpers -------------------------------------------------------------

    /** @since 1.0.0 */
    private function testMode(): bool
    {
        $cfg = get_option('dono_gateway_config', []);

        return is_array($cfg) && ! empty($cfg['test_mode']);
    }

    /** @since 1.0.0 */
    private function offlineReady(): bool
    {
        $gw = $this->settings->get('gateways');

        return ! empty($gw['offline']['enabled']) && trim((string) ($gw['offline']['instructions'] ?? '')) !== '';
    }

    /**
     * Whether the org has this gateway switched on, which is a different
     * question from whether it could charge.
     *
     * A gateway that is off is not offered to a donor, so its credentials,
     * webhook and domain verification are nobody's problem. Reporting them
     * tells an org it is blocked by a processor it deliberately turned off.
     *
     * Deliberately not GatewayManager::isOn(), which also requires canCharge():
     * a gateway that is on but half-configured is exactly what these checks
     * exist to report.
     *
     * @since 1.0.0
     */
    private function switchedOn(string $id): bool
    {
        $cfg = get_option('dono_gateway_config', []);
        $cfg = is_array($cfg) ? $cfg : [];

        return (bool) ($cfg[$id]['enabled'] ?? true);
    }

    /** @since 1.0.0 */
    private function showTaxId(): bool
    {
        $receipts = $this->settings->get('receipts');

        return ! array_key_exists('show_tax_id', $receipts) || (bool) $receipts['show_tax_id'];
    }

    /**
     * @param array<int,array{name:string}> $addons
     *
     * @since 1.0.0
     */
    private function names(array $addons): string
    {
        return implode(', ', array_map(static fn (array $a): string => (string) $a['name'], $addons));
    }

    /**
     * @param list<string> $items
     *
     * @since 1.0.0
     */
    private function join(array $items): string
    {
        if (count($items) < 2) {
            return (string) ($items[0] ?? '');
        }
        $last = array_pop($items);

        return implode(', ', $items) . ' ' . __('and', 'dono') . ' ' . $last;
    }

    /**
     * @return array<string,mixed>
     *
     * @since 1.0.0
     */
    private function pass(string $id, string $group, string $label): array
    {
        return ['id' => $id, 'group' => $group, 'status' => self::PASS, 'label' => $label];
    }

    /**
     * @return array<string,mixed>
     *
     * @since 1.0.0
     */
    private function warn(string $id, string $group, string $label, string $detail = '', ?string $tab = null, ?string $actionLabel = null): array
    {
        return $this->row(self::WARN, $id, $group, $label, $detail, $tab, $actionLabel, false);
    }

    /**
     * @return array<string,mixed>
     *
     * @since 1.0.0
     */
    private function fail(string $id, string $group, string $label, string $detail = '', ?string $tab = null, ?string $actionLabel = null, bool $blocker = false): array
    {
        return $this->row(self::FAIL, $id, $group, $label, $detail, $tab, $actionLabel, $blocker);
    }

    /**
     * @return array<string,mixed>
     *
     * @since 1.0.0
     */
    private function row(string $status, string $id, string $group, string $label, string $detail, ?string $tab, ?string $actionLabel, bool $blocker): array
    {
        $row = ['id' => $id, 'group' => $group, 'status' => $status, 'label' => $label];
        if ($detail !== '') {
            $row['detail'] = $detail;
        }
        if ($tab !== null) {
            $row['action_url']   = admin_url('admin.php?page=dono-settings#' . $tab);
            $row['action_label'] = $actionLabel ?? __('Open settings', 'dono');
        }
        if ($blocker) {
            $row['blocker'] = true;
        }

        return $row;
    }
}
