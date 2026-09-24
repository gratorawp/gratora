<?php

declare(strict_types=1);

namespace Gratora\Tests\E2e;

use Gratora\Campaigns\Campaign;
use Gratora\Campaigns\CampaignService;
use Gratora\Currency\FxRates;
use Gratora\Donations\AggregateSyncer;
use Gratora\Donations\Donation;
use Gratora\Donations\DonationIntent;
use Gratora\Donations\DonationService;
use Gratora\Donors\DonorService;
use Gratora\Donors\MagicLinkService;
use Gratora\Donors\Portal\PortalPage;
use Gratora\Donors\Portal\PortalSession;
use Gratora\Forms\Form;
use Gratora\Forms\FormService;
use Gratora\Foundation\Container\Container;
use Gratora\Foundation\Helpers\Money;
use Gratora\Foundation\Plugin;
use Gratora\Foundation\Time\Clock;
use Gratora\Funds\Fund;
use Gratora\Funds\FundService;
use Gratora\Gateways\GatewayManager;
use Gratora\Gateways\Stripe\StripeAccount;
use Gratora\Onboarding\Onboarding;
use Gratora\Settings\SettingsService;
use Throwable;
use WP_CLI;
use WP_User;

/**
 * `wp gratora e2e-seed`, the fixture the Playwright suites run against.
 *
 * Development only: tests-e2e/ is not in the release zip, so the command exists
 * only where this file is loaded with `wp --require`.
 */
final class E2eSeedCommand
{
    /** Marks the administrator this seed created, so it never adopts anyone else's account. */
    public const FIXTURE_META = 'gratora_e2e_fixture';

    /** Fixture credentials for a throwaway site: they charge nothing and reach no network. */
    private const STRIPE_SECRET      = 'sk_test_gratora_e2e_fixture';
    private const STRIPE_PUBLISHABLE = 'pk_test_gratora_e2e_fixture';

    private const BRAND_OPTION = 'gratora_org_brand';
    private const BRAND_BACKUP = 'gratora_org_brand_e2e_backup';

    /**
     * A dark card under a pale accent: the pairing where ink chosen for one
     * ground lands on another and reads at 1:1. Classic carries a mid-dark
     * accent on a red-brown card and Bold a red card.
     */
    private const QA_BRAND = [
        'presets'    => [
            [
                'id'      => 'classic',
                'tokens'  => ['gratora-accent' => '#452ef5', 'gratora-bg' => '#804242'],
                'builtin' => true,
            ],
            [
                'id'      => 'bold',
                'tokens'  => ['gratora-bg' => '#f55151'],
                'builtin' => true,
            ],
            [
                'id'          => 'qa-dark-pale',
                'name'        => 'QA Dark Pale',
                'description' => '',
                'tokens'      => [
                    'gratora-bg'        => '#15142b',
                    'gratora-bg-soft'   => '#221f3d',
                    'gratora-accent'    => '#fde68a',
                    'gratora-border'    => '#3a3660',
                    'gratora-radius'    => '10px',
                    'gratora-radius-sm' => '6px',
                ],
            ],
        ],
        'default_id' => 'qa-dark-pale',
    ];

    private function container(): Container
    {
        return Plugin::instance()->container;
    }

    /**
     * Create / refresh a canonical "kitchen sink" donation form for the
     * Playwright e2e suite. Idempotent: re-running keeps the same slugs and
     * just updates the form blocks + page so the canonical form converges to
     * whatever the current spec set expects.
     *
     * Sets up:
     *   - Campaign "Gratora E2E" (status=published)
     *   - Form "Gratora E2E Form" (status=published) with every donor block the
     *     spec suite asserts against
     *   - WP page "Gratora E2E" containing [gratora_donation_form slug="..."]
     *   - An administrator for the admin specs, with a password generated on
     *     every run unless GRATORA_E2E_ADMIN_PASS is set
     *
     * Rewrites org-wide money settings, so it refuses on an install that
     * reports itself as production.
     *
     * ## OPTIONS
     *
     * [--force]
     * : Seed even though this install reports itself as production. Only for a
     * throwaway install.
     *
     * [--yes]
     * : Skip the confirmation prompt.
     *
     * ## EXAMPLES
     *
     *     wp --require=tests-e2e/cli/E2eSeedCommand.php gratora e2e-seed
     *     # then in your shell:
     *     export GRATORA_E2E_URL="http://localhost:10075"
     *     export GRATORA_E2E_FORM_PATH="/gratora-e2e/"
     *     export GRATORA_E2E_MULTI_STEP_FORM_PATH="/gratora-e2e-wizard/"
     *
     * @when after_wp_load
     */
    public function seed(array $args, array $assoc): void
    {
        // WordPress answers production for anything that has not said
        // otherwise, which is the direction this has to fail in: the fixture
        // is worth nothing on a live site and costs it every donation taken
        // while test mode is on.
        if (wp_get_environment_type() === 'production' && empty($assoc['force'])) {
            WP_CLI::error(
                'Refusing to seed: this install reports itself as production. The '
                . 'fixture overwrites the org currency and number format, pins '
                . 'invented FX rates, turns org-wide test mode on and publishes '
                . 'five public pages. Set WP_ENVIRONMENT_TYPE to local, '
                . 'development or staging in wp-config.php, or pass --force on a '
                . 'throwaway install.'
            );
        }

        // The account is written last, so a login this seed must not adopt is
        // refused here, before anything else is overwritten.
        self::fixtureAdmin(self::adminLogin());

        WP_CLI::confirm(
            'Overwrite the org currency with EUR and its number format, pin '
            . 'invented FX rates, turn org-wide test mode on and publish five '
            . 'e2e pages on this install?',
            $assoc
        );

        $forms      = $this->container()->get(FormService::class);
        $campaigns  = $this->container()->get(CampaignService::class);
        $settings   = $this->container()->get(SettingsService::class);

        // Activation leaves onboarding pending, and while it is pending every
        // admin screen redirects to it. A spec that drives a Gratora admin page
        // never arrives, and the failure reads as a missing control rather
        // than a redirect.
        update_option(Onboarding::OPTION, 'completed', false);

        // Enable a few currencies so the currency-switcher specs have
        // something to switch between.
        //
        // The number format is pinned rather than left to defaults. It is not
        // derived from the currency or the locale, it is a stored org setting,
        // so an unpinned fixture renders whatever the last person to touch
        // settings chose and every amount in every visual golden moves with it.
        $settings->update('currency-locale', [
            'default_currency'     => 'EUR',
            'supported_currencies' => ['EUR', 'USD', 'GBP'],
            'format'               => [
                'decimal_sep'     => ',',
                'thousand_sep'    => '.',
                'decimal_places'  => 2,
                'symbol_position' => 'before',
            ],
        ]);

        $this->pinFxRates();

        // Enable relaxed test quotas for automation.
        $gatewayConfig = get_option('gratora_gateway_config', []);
        if (! is_array($gatewayConfig)) $gatewayConfig = [];
        $gatewayConfig['test_mode'] = true;

        // The specs pick "offline" by name, and OfflineGateway::canCharge()
        // answers no until it has something to tell the donor. Without this it
        // is registered, allowed by the form, and still never offered, so every
        // spec that submits fails at the thank-you card with nothing saying the
        // payment step was unreachable.
        $gatewayConfig['offline'] = array_merge(
            is_array($gatewayConfig['offline'] ?? null) ? $gatewayConfig['offline'] : [],
            [
                'enabled'      => true,
                'instructions' => 'Transfer to the account below and quote your reference.',
                'bank_details' => "Wildwater Trust\nIBAN NL00 BANK 0123 4567 89",
            ]
        );

        update_option('gratora_gateway_config', $gatewayConfig, false);

        $this->seedStripeFixtureKeys();

        global $wpdb;
        $wpdb->query(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_gratora_donate_%' OR option_name LIKE '_transient_timeout_gratora_donate_%'"
        );

        $campaign = Campaign::query()->where('slug', 'gratora-e2e')->get();
        if (! $campaign) {
            $campaign = $campaigns->create([
                'title'         => 'Gratora E2E',
                'slug'          => 'gratora-e2e',
                'status'        => 'published',
                'skip_template' => true,
            ]);
            WP_CLI::log("  campaign created: id={$campaign->id}");
        } else {
            $campaign->status = 'published';
            $campaign->save();
            WP_CLI::log("  campaign reused: id={$campaign->id}");
        }

        $this->registerConsentPurposes();
        $this->seedFundsAndGoal($campaign);

        $singleUrl = $this->upsertFormAndPage(
            $forms,
            (int) $campaign->id,
            'gratora-e2e-form',
            'Gratora E2E Form',
            'gratora-e2e',
            'Gratora E2E',
            self::canonicalBlocks()
        );
        $multiUrl = $this->upsertFormAndPage(
            $forms,
            (int) $campaign->id,
            'gratora-e2e-wizard',
            'Gratora E2E Wizard',
            'gratora-e2e-wizard',
            'Gratora E2E Wizard',
            self::multiStepBlocks()
        );
        $condUrl = $this->upsertFormAndPage(
            $forms,
            (int) $campaign->id,
            'gratora-e2e-conditional',
            'Gratora E2E Conditional',
            'gratora-e2e-conditional',
            'Gratora E2E Conditional',
            self::conditionalBlocks()
        );
        $customUrl = $this->upsertFormAndPage(
            $forms,
            (int) $campaign->id,
            'gratora-e2e-custom-fields',
            'Gratora E2E Custom Fields',
            'gratora-e2e-custom-fields',
            'Gratora E2E Custom Fields',
            self::customFieldsBlocks()
        );
        $layoutUrl = $this->upsertFormAndPage(
            $forms,
            (int) $campaign->id,
            'gratora-e2e-layout',
            'Gratora E2E Layout',
            'gratora-e2e-layout',
            'Gratora E2E Layout',
            self::layoutBlocks()
        );
        // Its own form, not the canonical one: only a gateway that pays in the
        // browser can reach the payment step, and offering Stripe on every
        // fixture form would load Stripe.js into every other spec.
        $paymentUrl = $this->upsertFormAndPage(
            $forms,
            (int) $campaign->id,
            'gratora-e2e-payment',
            'Gratora E2E Payment',
            'gratora-e2e-payment',
            'Gratora E2E Payment',
            self::paymentBlocks(),
            ['offline', 'sandbox', 'stripe']
        );

        WP_CLI::success("Canonical forms ready.");
        WP_CLI::log('  export GRATORA_E2E_URL="' . untrailingslashit(home_url()) . '"');
        // The specs select gateways by name, and a form that offers none still
        // renders perfectly: the failure only shows up much later, as a
        // thank-you card that never arrives. Check here, while there is still
        // something useful to say.
        //
        // Config, not the registry: CoreModule registers SandboxGateway at boot
        // if test mode is on, and boot already happened in this process. Asking
        // the registry now would report sandbox missing however right the
        // fixture is. What the next request will see is the option.
        $written = get_option('gratora_gateway_config', []);

        if (empty($written['test_mode'])) {
            WP_CLI::error('Test mode did not stick, so the sandbox gateway will not be registered.');
        }

        // From the registry: offline is registered whatever test mode says, so
        // this asks the same object the request will, rather than restating its
        // rule here and letting the two drift.
        $offline = $this->container()->get(GatewayManager::class)->get('offline');

        if ($offline === null || ! $offline->canCharge()) {
            WP_CLI::error(
                'The offline gateway has neither instructions nor bank details, so it reports itself '
                . 'unable to charge and is never offered. Every spec that submits would fail at the '
                . 'thank-you card without saying why.'
            );
        }

        [$adminUser, $adminPass] = $this->ensureAdmin();

        WP_CLI::log('  export GRATORA_E2E_ADMIN_USER="' . $adminUser . '"');
        WP_CLI::log('  export GRATORA_E2E_ADMIN_PASS="' . $adminPass . '"');
        WP_CLI::log('  export GRATORA_E2E_FORM_PATH="' . wp_parse_url($singleUrl, PHP_URL_PATH) . '"');
        WP_CLI::log('  export GRATORA_E2E_MULTI_STEP_FORM_PATH="' . wp_parse_url($multiUrl, PHP_URL_PATH) . '"');
        WP_CLI::log('  export GRATORA_E2E_CONDITIONAL_FORM_PATH="' . wp_parse_url($condUrl, PHP_URL_PATH) . '"');
        WP_CLI::log('  export GRATORA_E2E_CUSTOM_FIELDS_FORM_PATH="' . wp_parse_url($customUrl, PHP_URL_PATH) . '"');
        WP_CLI::log('  export GRATORA_E2E_LAYOUT_FORM_PATH="' . wp_parse_url($layoutUrl, PHP_URL_PATH) . '"');
        WP_CLI::log('  export GRATORA_E2E_PAYMENT_FORM_PATH="' . wp_parse_url($paymentUrl, PHP_URL_PATH) . '"');
        WP_CLI::log('  export GRATORA_E2E_PORTAL_REOPEN_URL="' . $this->mintPortalLink() . '"');
    }

    /**
     * `wp gratora e2e-seed-branding`, the fixture specs/branding-grounds.spec.ts
     * and specs/branding-admin.spec.ts run against: the QA brand, a page for
     * each surface that paints a ground of its own, and a form whose Develop
     * canvas draws the blocks that stand in for the runtime stylesheet.
     *
     * Replaces the org brand. The first run keeps the one it replaced in
     * gratora_org_brand_e2e_backup and later runs leave that backup alone, so
     * --restore always puts back the brand from before the first run. The
     * campaigns, forms, pages and three paid live donations stay, and a later
     * run reuses them. Each run prints a fresh single-use donor portal link.
     *
     * ## OPTIONS
     *
     * [--restore]
     * : Put back the brand the first run replaced, drop the backup, and touch
     * nothing else.
     *
     * [--force]
     * : Seed even though this install reports itself as production. Only for a
     * throwaway install.
     *
     * [--yes]
     * : Skip the confirmation prompt.
     *
     * ## EXAMPLES
     *
     *     wp --require=tests-e2e/cli/E2eSeedCommand.php gratora e2e-seed-branding
     *     wp --require=tests-e2e/cli/E2eSeedCommand.php gratora e2e-seed-branding --restore
     *
     * @when after_wp_load
     */
    public function seedBranding(array $args, array $assoc): void
    {
        if (! empty($assoc['restore'])) {
            $this->restoreBrand();

            return;
        }

        if (wp_get_environment_type() === 'production' && empty($assoc['force'])) {
            WP_CLI::error(
                'Refusing to seed: this install reports itself as production. The '
                . 'fixture replaces the org brand, records three paid live donations '
                . 'and publishes e2e campaigns and pages. Set WP_ENVIRONMENT_TYPE to '
                . 'local, development or staging in wp-config.php, or pass --force on '
                . 'a throwaway install.'
            );
        }

        WP_CLI::confirm(
            'Replace the org brand (kept for --restore), record three paid live '
            . 'donations and publish the branding e2e campaigns and pages on this install?',
            $assoc
        );

        $this->backUpBrand();
        update_option(self::BRAND_OPTION, self::QA_BRAND);
        WP_CLI::log('  brand: QA Dark Pale is the default');

        $page    = $this->brandingCampaign('e2e-branding-page', 'Branding Page', 'standard', null, 500000);
        $pale    = $this->brandingCampaign('e2e-branding-pale', 'Branding Pale', 'standard', null, 100000);
        $guest   = $this->brandingCampaign('e2e-branding-guest', 'Branding Guest', 'standard', null, 200000);
        $host    = $this->brandingCampaign('e2e-branding-host', 'Branding Host', 'standard', null, 200000);
        $white   = $this->brandingCampaign('e2e-branding-white', 'Branding White Host', 'standard', ['preset_id' => 'quiet'], 200000);
        $bold    = $this->brandingCampaign('e2e-branding-bold', 'Branding Bold', 'standard', ['preset_id' => 'bold'], 200000);
        $classic = $this->brandingCampaign('e2e-branding-classic', 'Branding Classic', 'hero', ['preset_id' => 'classic'], 200000);
        $cover   = $this->brandingCampaign('e2e-branding-cover', 'Branding Cover', 'cover', null, 200000);
        $theme   = $this->brandingCampaign('e2e-branding-theme', 'Branding Site Theme', 'standard', ['preset_id' => 'theme'], 200000);

        $this->brandingDonations([
            ['key' => 'e2e-branding-1', 'campaign' => $page, 'email' => 'e2e-branding-1@example.test', 'first' => 'Ada', 'last' => 'Branding', 'cents' => 12000],
            ['key' => 'e2e-branding-2', 'campaign' => $page, 'email' => 'e2e-branding-2@example.test', 'first' => 'Ben', 'last' => 'Branding', 'cents' => 4500],
            ['key' => 'e2e-branding-3', 'campaign' => $pale, 'email' => 'e2e-branding-1@example.test', 'first' => 'Ada', 'last' => 'Branding', 'cents' => 30000],
        ]);

        $forms = $this->container()->get(FormService::class);
        $plain = $this->brandingForm($forms, 'e2e-branding-plain-form', 'Branding Plain', (int) $page->id, 'plain');
        $frame = $this->brandingForm($forms, 'e2e-branding-frame-form', 'Branding Frame', (int) $page->id, 'frame');
        $frameBold = $this->brandingForm($forms, 'e2e-branding-frame-bold-form', 'Branding Frame Bold', (int) $bold->id, 'frame');
        $canvas    = $this->brandingForm($forms, 'e2e-branding-canvas-form', 'Branding Canvas', (int) $page->id, 'plain', self::brandingCanvasBlocks());

        $shortcode = static fn (Form $form): string => "<!-- wp:shortcode -->\n[gratora_donation_form slug=\"" . esc_attr($form->slug) . "\"]\n<!-- /wp:shortcode -->";

        $paths = [
            'CAMPAIGN'   => $this->campaignPath($page),
            'PLAIN'      => $this->brandingPage('e2e-branding-plain', 'Branding Plain', $shortcode($plain), null),
            'FRAME'      => $this->brandingPage('e2e-branding-frame', 'Branding Frame', $shortcode($frame), null),
            'FRAME_BOLD' => $this->brandingPage('e2e-branding-frame-bold', 'Branding Frame Bold', $shortcode($frameBold), null),
            'PANEL'      => $this->brandingPage('e2e-branding-panel', 'Branding Panel', self::panelBlocks((int) $host->id, (int) $guest->id), $host),
            'WHITE_HOST' => $this->brandingPage('e2e-branding-white-host', 'Branding White Host Page', self::whiteHostBlocks((int) $page->id), $white),
            'BOLD_HOST'  => $this->brandingPage('e2e-branding-bold-host', 'Branding Bold Host', self::boldHostBlocks((int) $page->id, (int) $bold->id), $bold),
            'WHITE_GRID' => $this->brandingPage('e2e-branding-white-grid', 'Branding White Grid', '<!-- wp:gratora/campaign-grid {"count":12} /-->', null),
            'MODAL'      => $this->brandingPage('e2e-branding-modal', 'Branding Modal', '<!-- wp:gratora/donate-button {"campaignId":' . (int) $page->id . ',"label":"Donate in a modal"} /-->', $guest),
            'QUIET_HOST' => $this->brandingPage('e2e-branding-quiet-host', 'Branding Quiet Host', self::quietHostBlocks((int) $page->id, $shortcode($plain)), $white),
            'CLASSIC'    => $this->campaignPath($classic),
            'COVER'      => $this->campaignPath($cover),
            'THEME'      => $this->campaignPath($theme),
        ];

        WP_CLI::success('Branding fixture ready. Restore the brand with --restore.');
        WP_CLI::log('  export GRATORA_E2E_URL="' . untrailingslashit(home_url()) . '"');
        foreach ($paths as $name => $path) {
            WP_CLI::log('  export GRATORA_E2E_BRANDING_' . $name . '_PATH="' . $path . '"');
        }
        WP_CLI::log('  export GRATORA_E2E_BRANDING_CAMPAIGN_ID="' . (int) $page->id . '"');
        WP_CLI::log('  export GRATORA_E2E_BRANDING_CANVAS_FORM_ID="' . (int) $canvas->id . '"');
        WP_CLI::log('  export GRATORA_E2E_BRANDING_PORTAL_URL="'
            . $this->mintPortalLink('e2e-branding-1@example.test', ['first_name' => 'Ada', 'last_name' => 'Branding']) . '"');
    }

    /**
     * A dedicated administrator for the admin specs, so they run the same on a
     * Local site as in CI and never lean on whatever real account the install
     * happens to have.
     *
     * The password is never a constant: a known password on an administrator is
     * a way into any site the seed ever ran on. GRATORA_E2E_ADMIN_PASS pins one
     * for an operator who wants it stable; otherwise each run generates and
     * prints a new one.
     *
     * @return array{0:string,1:string} login, password
     */
    public function ensureAdmin(): array
    {
        $login = self::adminLogin();
        $pass  = (string) (getenv('GRATORA_E2E_ADMIN_PASS') ?: wp_generate_password(24, false));
        $user  = self::fixtureAdmin($login);

        if ($user === null) {
            $id = wp_insert_user([
                'user_login' => $login,
                'user_pass'  => $pass,
                'user_email' => $login . '@gratora.test',
                'role'       => 'administrator',
                'meta_input' => [self::FIXTURE_META => 1],
            ]);

            if (is_wp_error($id)) {
                WP_CLI::warning('e2e admin not created: ' . $id->get_error_message());

                return [$login, $pass];
            }

            WP_CLI::log('  admin created: ' . $login);

            return [$login, $pass];
        }

        wp_set_password($pass, (int) $user->ID);
        $user->set_role('administrator');
        WP_CLI::log('  admin reused: ' . $login);

        return [$login, $pass];
    }

    private static function adminLogin(): string
    {
        return (string) (getenv('GRATORA_E2E_ADMIN_USER') ?: 'gratora-e2e-admin');
    }

    /**
     * The account this seed created on an earlier run, or null when the login
     * is free. The login comes from the environment, so it can name any real
     * account, and resetting that account's password and role is not a fixture.
     */
    private static function fixtureAdmin(string $login): ?WP_User
    {
        $user = get_user_by('login', $login);

        if ($user === false) {
            return null;
        }

        if (! get_user_meta((int) $user->ID, self::FIXTURE_META, true)) {
            WP_CLI::error(sprintf(
                'Refusing to seed: the user "%1$s" already exists and this seed did not create it, '
                . 'so its password and role are not touched. Delete that user, or set '
                . 'GRATORA_E2E_ADMIN_USER to a login that does not exist yet.',
                $login
            ));
        }

        return $user;
    }

    /**
     * A fresh single-use sign-in link for the portal spec.
     *
     * Minted here because it is single use: a link left in a shell from an
     * earlier run is already spent, and the spec would then skip itself and
     * read as coverage.
     */
    private function mintPortalLink(
        string $email = 'gratora-e2e-portal@example.test',
        array $profile = ['first_name' => 'Portal', 'last_name' => 'Tester']
    ): string {
        $donor = $this->container()->get(DonorService::class)->findOrCreate($email, $profile);

        $token = $this->container()->get(MagicLinkService::class)
            ->issue((int) $donor->id, PortalSession::PORTAL_PURPOSE, null, 3600);

        $page = new PortalPage();
        $page->ensure();

        return add_query_arg('token', $token, $page->url());
    }

    /**
     * Test credentials for the fixture site, so a browser-paying gateway is on
     * offer at all. Without one the payment-step specs cannot reach the phase
     * they exist to check and skip themselves in every run.
     *
     * No network call: nothing here charges anything. The specs answer the
     * donation POST themselves, and Stripe.js failing on the invented secret is
     * past every claim they make.
     */
    private function seedStripeFixtureKeys(): void
    {
        $account = $this->container()->get(StripeAccount::class);

        if ($account->isConnected() && $account->publishableKeyFor(true) !== self::STRIPE_PUBLISHABLE) {
            WP_CLI::warning('  stripe: keeping the keys this install already has');

            return;
        }

        try {
            $account->saveKeys(true, self::STRIPE_SECRET, self::STRIPE_PUBLISHABLE);
            $account->refresh([
                'id'                => 'acct_gratora_e2e',
                'charges_enabled'   => true,
                'details_submitted' => true,
                'country'           => 'NL',
                'business_profile'  => ['name' => 'Wildwater Trust'],
            ]);
            WP_CLI::log('  stripe: fixture test keys written');
        } catch (Throwable $e) {
            WP_CLI::warning('  stripe: could not write fixture keys (' . $e->getMessage() . ')');
        }
    }

    private static function paymentBlocks(): string
    {
        return implode("\n", [
            '<!-- wp:gratora/donation-amount {"presets":[1000,2500,5000,10000],"allowCustom":true,"currency":"EUR"} /-->',
            '<!-- wp:gratora/name {"requireFirst":true,"requireLast":true} /-->',
            '<!-- wp:gratora/email {"required":true} /-->',
            '<!-- wp:gratora/payment-gateways {"style":"radio","allowed":["offline","sandbox","stripe"]} /-->',
            '<!-- wp:gratora/donation-summary /-->',
            '<!-- wp:gratora/submit-button {"label":"Donate now"} /-->',
        ]);
    }

    /**
     * Idempotent create-or-update for an e2e form + its embedding page.
     * Returns the public URL of the page.
     */
    private function upsertFormAndPage(
        FormService $forms,
        int $campaignId,
        string $formSlug,
        string $formTitle,
        string $pageSlug,
        string $pageTitle,
        string $blocks,
        array $allowedGateways = ['offline', 'sandbox']
    ): string {
        // Pinned rather than left to the shortcode default. The default is a
        // product decision that is allowed to change, and every visual golden
        // moves with it: the fixture asserts its own appearance.
        $settings = [
            'gateways'  => ['allowed' => $allowedGateways],
            'container' => ['style' => 'frame', 'width' => 540],
        ];

        $form = Form::query()->where('slug', $formSlug)->get();
        if (! $form) {
            $form = $forms->create([
                'title'       => $formTitle,
                'slug'        => $formSlug,
                'status'      => 'published',
                'campaign_id' => $campaignId,
                'blocks'      => $blocks,
                'settings'    => $settings,
            ]);
            WP_CLI::log("  form created: slug={$form->slug} id={$form->id}");
        } else {
            $forms->update($form, [
                'campaign_id' => $campaignId,
                'blocks'      => $blocks,
                'status'      => 'published',
                'settings'    => $settings,
            ]);
            WP_CLI::log("  form updated: slug={$form->slug} id={$form->id}");
        }

        $content = '[gratora_donation_form slug="' . esc_attr($form->slug) . '"]';
        $page    = get_page_by_path($pageSlug, OBJECT, 'page');

        // A campaign owns its page, and a campaign slug can collide with a form
        // page slug. Reusing one replaces a campaign page with a bare shortcode
        // and the campaign loses the page it points at, so leave it alone and
        // take the next slug instead.
        if ($page && Campaign::query()->where('page_id', (int) $page->ID)->get()) {
            WP_CLI::warning("  page {$page->ID} belongs to a campaign; using {$pageSlug}-form instead");
            $pageSlug .= '-form';
            $page = get_page_by_path($pageSlug, OBJECT, 'page');
        }

        if (! $page) {
            $pageId = wp_insert_post([
                'post_title'   => $pageTitle,
                'post_name'    => $pageSlug,
                'post_status'  => 'publish',
                'post_type'    => 'page',
                'post_content' => $content,
            ], true);
            if (is_wp_error($pageId)) {
                WP_CLI::error('Page insert failed: ' . $pageId->get_error_message());
            }
            WP_CLI::log("  page created: slug={$pageSlug} id={$pageId}");
        } else {
            wp_update_post([
                'ID'           => $page->ID,
                'post_status'  => 'publish',
                'post_content' => $content,
            ]);
            WP_CLI::log("  page reused: slug={$pageSlug} id={$page->ID}");
        }

        return trailingslashit(home_url('/' . $pageSlug));
    }

    /** Fixed FX rates with auto-refresh off, so conversion screenshots stay reproducible. */
    private function pinFxRates(): void
    {
        update_option(FxRates::OPTION, [
            'base'       => 'EUR',
            'date'       => '2026-01-02',
            'fetched_at' => '2026-01-02 00:00:00',
            'auto'       => false,
            'rates'      => ['USD' => 1.10, 'GBP' => 0.85],
        ], false);
        WP_CLI::log('  fx rates pinned: EUR base, USD 1.10, GBP 0.85');
    }

    /**
     * Funds to pick between and a goal to fill.
     *
     * The layout form seeds a fund-picker and a goal block, and both render
     * empty against a site with one fund and no goal, so the fixture has to
     * supply the data those blocks exist to display.
     */
    private function seedFundsAndGoal(Campaign $campaign): void
    {
        $funds = $this->container()->get(FundService::class);

        $wanted = [
            ['code' => 'e2e-water',     'name' => 'Clean water',      'sort_order' => 1],
            ['code' => 'e2e-education', 'name' => 'Education',        'sort_order' => 2],
            ['code' => 'e2e-emergency', 'name' => 'Emergency relief', 'sort_order' => 3, 'is_restricted' => true],
        ];

        foreach ($wanted as $spec) {
            if (Fund::query()->where('code', $spec['code'])->get()) {
                continue;
            }
            $funds->create($spec + ['is_active' => true]);
            WP_CLI::log("  fund created: {$spec['code']}");
        }

        if (($campaign->goal_cents ?? null) === null) {
            $campaign->goal_cents = 500000;
            $campaign->save();
            WP_CLI::log('  campaign goal set: 500000');
        }
    }

    /** Consent purposes the canonical form's consent block points at. */
    private function registerConsentPurposes(): void
    {
        $this->container()->get(SettingsService::class)->update('consents', [
            'purposes' => [
                ['key' => 'tos',     'label' => 'I accept the terms',               'description' => '', 'required' => true,  'default' => false, 'version' => 1],
                ['key' => 'updates', 'label' => 'Send me updates about this cause', 'description' => '', 'required' => false, 'default' => false, 'version' => 1],
            ],
        ]);
        WP_CLI::log('  consent purposes registered: tos, updates');
    }

    private static function canonicalBlocks(): string
    {
        $consent = wp_json_encode([
            'label'       => 'Consent',
            'purposeKeys' => ['tos', 'updates'],
        ]);
        $dropdown = wp_json_encode([
            'label'   => 'How did you hear about us?',
            'field'   => 'referral_source',
            'options' => [
                ['value' => 'friend', 'label' => 'A friend'],
                ['value' => 'social', 'label' => 'Social media'],
                ['value' => 'event',  'label' => 'An event'],
            ],
        ]);

        return implode("\n", [
            '<!-- wp:gratora/heading {"text":"Support our work","level":2} /-->',
            '<!-- wp:gratora/currency-switcher {"currencies":["EUR","USD","GBP"]} /-->',
            '<!-- wp:gratora/donation-amount {"presets":[1000,2500,5000,10000],"allowCustom":true,"currency":"EUR"} /-->',
            '<!-- wp:gratora/name {"requireFirst":true,"requireLast":true} /-->',
            '<!-- wp:gratora/email {"required":true} /-->',
            '<!-- wp:gratora/country /-->',
            '<!-- wp:gratora/address {"requireLine1":false,"requireCity":false,"requireRegion":false,"requirePostal":false,"requireCountry":false} /-->',
            '<!-- wp:gratora/phone /-->',
            '<!-- wp:gratora/comment {"label":"Add a message"} /-->',
            '<!-- wp:gratora/anonymous-toggle /-->',
            '<!-- wp:gratora/cover-fees /-->',
            '<!-- wp:gratora/date {"label":"Preferred call date","field":"call_date"} /-->',
            '<!-- wp:gratora/dropdown ' . $dropdown . ' /-->',
            '<!-- wp:gratora/consent ' . $consent . ' /-->',
            '<!-- wp:gratora/payment-gateways {"style":"radio","allowed":["offline","sandbox"]} /-->',
            '<!-- wp:gratora/donation-summary /-->',
            '<!-- wp:gratora/submit-button {"label":"Donate now"} /-->',
        ]);
    }

    private static function multiStepBlocks(): string
    {
        return <<<'BLOCKS'
<!-- wp:gratora/steps -->
<!-- wp:gratora/step {"title":"Your donation"} -->
<!-- wp:gratora/donation-amount {"presets":[1000,2500,5000,10000],"allowCustom":true,"currency":"EUR"} /-->
<!-- /wp:gratora/step -->

<!-- wp:gratora/step {"title":"Your info"} -->
<!-- wp:gratora/name {"requireFirst":true,"requireLast":true} /-->
<!-- wp:gratora/email {"required":true} /-->
<!-- /wp:gratora/step -->

<!-- wp:gratora/step {"title":"Confirm"} -->
<!-- wp:gratora/payment-gateways {"style":"radio","allowed":["offline","sandbox"]} /-->
<!-- wp:gratora/donation-summary /-->
<!-- wp:gratora/submit-button {"label":"Donate now"} /-->
<!-- /wp:gratora/step -->
<!-- /wp:gratora/steps -->
BLOCKS;
    }

    /**
     * Layout + content blocks: heading, paragraph, html, divider, columns,
     * row, section, recurring-toggle, fund-picker, privacy-notice, goal.
     * Each gets a unique marker so the spec can assert the block survived to
     * the public render in the right shape.
     */
    private static function layoutBlocks(): string
    {
        return <<<'BLOCKS'
<!-- wp:gratora/heading {"text":"LAYOUT_HEADING_TEXT","level":2} /-->
<!-- wp:gratora/paragraph {"text":"LAYOUT_PARAGRAPH_TEXT"} /-->
<!-- wp:gratora/html {"content":"<span class=\"layout-html-marker\">LAYOUT_HTML_TEXT</span>"} /-->
<!-- wp:gratora/divider {"marginTop":24,"marginBottom":24,"thickness":2,"color":"#cccccc"} /-->

<!-- wp:gratora/section {"label":"LAYOUT_SECTION_LABEL"} -->
<!-- wp:gratora/paragraph {"text":"Inside a section"} /-->
<!-- /wp:gratora/section -->

<!-- wp:gratora/columns {"columns":2,"gap":20,"gapUnit":"px"} -->
<!-- wp:gratora/heading {"text":"LAYOUT_COL_LEFT","level":4} /-->
<!-- wp:gratora/heading {"text":"LAYOUT_COL_RIGHT","level":4} /-->
<!-- /wp:gratora/columns -->

<!-- wp:gratora/row {"columns":2,"gap":14,"gapUnit":"px"} -->
<!-- wp:gratora/name /-->
<!-- wp:gratora/email /-->
<!-- /wp:gratora/row -->

<!-- wp:gratora/donation-amount {"presets":[1000,2500,5000,10000],"allowCustom":true,"currency":"EUR"} /-->
<!-- wp:gratora/recurring-toggle {"label":"LAYOUT_RECURRING_LABEL","frequencies":["one-time","monthly"]} /-->
<!-- wp:gratora/fund-picker {"label":"LAYOUT_FUND_LABEL"} /-->
<!-- wp:gratora/goal {"showAmount":true} /-->
<!-- wp:gratora/privacy-notice {"text":"LAYOUT_PRIVACY_TEXT"} /-->
<!-- wp:gratora/payment-gateways {"style":"radio","allowed":["offline","sandbox"]} /-->
<!-- wp:gratora/donation-summary /-->
<!-- wp:gratora/submit-button {"label":"Donate now"} /-->
BLOCKS;
    }

    private static function customFieldsBlocks(): string
    {
        $radio = wp_json_encode([
            'label'   => 'CUSTOM_RADIO_LABEL',
            'field'   => 'cf_radio',
            'options' => [
                ['value' => 'alpha', 'label' => 'Alpha'],
                ['value' => 'beta',  'label' => 'Beta'],
                ['value' => 'gamma', 'label' => 'Gamma'],
            ],
        ]);
        $multi = wp_json_encode([
            'label'   => 'CUSTOM_MULTISELECT_LABEL',
            'field'   => 'cf_multi',
            'options' => [
                ['value' => 'one',   'label' => 'One'],
                ['value' => 'two',   'label' => 'Two'],
                ['value' => 'three', 'label' => 'Three'],
            ],
        ]);

        return implode("\n", [
            '<!-- wp:gratora/heading {"text":"Custom Fields Form","level":2} /-->',
            '<!-- wp:gratora/donation-amount {"presets":[1000,2500,5000,10000],"allowCustom":true,"currency":"EUR"} /-->',
            '<!-- wp:gratora/name {"requireFirst":true,"requireLast":true} /-->',
            '<!-- wp:gratora/email {"required":true} /-->',
            '<!-- wp:gratora/text-input {"label":"CUSTOM_TEXT_LABEL","field":"cf_text","placeholder":"Type something"} /-->',
            '<!-- wp:gratora/number-input {"label":"CUSTOM_NUMBER_LABEL","field":"cf_number","min":1,"max":100} /-->',
            '<!-- wp:gratora/radio ' . $radio . ' /-->',
            '<!-- wp:gratora/checkbox {"label":"CUSTOM_CHECKBOX_LABEL","field":"cf_check"} /-->',
            '<!-- wp:gratora/multi-select ' . $multi . ' /-->',
            '<!-- wp:gratora/hidden {"field":"cf_hidden","defaultValue":"hidden-default"} /-->',
            '<!-- wp:gratora/payment-gateways {"style":"radio","allowed":["offline","sandbox"]} /-->',
            '<!-- wp:gratora/donation-summary /-->',
            '<!-- wp:gratora/submit-button {"label":"Donate now"} /-->',
        ]);
    }

    /**
     * Written once: a later run would otherwise back up its own fixture, and
     * --restore would put the QA brand back instead of the org's.
     */
    private function backUpBrand(): void
    {
        if (get_option(self::BRAND_BACKUP, null) !== null) {
            WP_CLI::log('  brand: keeping the backup an earlier run made');

            return;
        }

        $current = get_option(self::BRAND_OPTION, null);
        update_option(self::BRAND_BACKUP, ['exists' => $current !== null, 'value' => $current], false);
        WP_CLI::log('  brand: backed up to ' . self::BRAND_BACKUP);
    }

    private function restoreBrand(): void
    {
        $backup = get_option(self::BRAND_BACKUP, null);
        if (! is_array($backup) || ! array_key_exists('exists', $backup)) {
            WP_CLI::error('Nothing to restore: ' . self::BRAND_BACKUP . ' is not set.');
        }

        if ($backup['exists']) {
            update_option(self::BRAND_OPTION, $backup['value']);
        } else {
            delete_option(self::BRAND_OPTION);
        }
        delete_option(self::BRAND_BACKUP);

        WP_CLI::success('Brand restored.');
    }

    /**
     * @param array<string,string>|null $style null follows the org default preset
     */
    private function brandingCampaign(string $slug, string $title, string $template, ?array $style, int $goalCents): Campaign
    {
        $service  = $this->container()->get(CampaignService::class);
        $campaign = Campaign::query()->where('slug', $slug)->get();

        if (! $campaign) {
            $campaign = $service->create([
                'title'         => $title,
                'slug'          => $slug,
                'status'        => 'published',
                'description'   => 'A campaign the branding suite measures text contrast on.',
                'goal_type'     => 'amount',
                'goal_cents'    => $goalCents,
                'page_template' => $template,
            ]);
            WP_CLI::log("  campaign created: {$slug} id={$campaign->id}");
        } else {
            WP_CLI::log("  campaign reused: {$slug} id={$campaign->id}");
        }

        $campaign = $service->update($campaign, [
            'status'  => 'published',
            'style'   => $style,
            'ends_at' => gmdate('Y-m-d H:i:s', time() + 60 * DAY_IN_SECONDS),
        ]);

        // The grids list the newest campaigns first, and the spec reads these cards.
        $campaign->created_at = $this->container()->get(Clock::class)->now()->format('Y-m-d H:i:s');
        $campaign->save();

        return $campaign;
    }

    private function campaignPath(Campaign $campaign): string
    {
        return (string) wp_parse_url((string) get_permalink((int) $campaign->page_id), PHP_URL_PATH);
    }

    /**
     * Paid live donations, so the list blocks have rows and the grid cards a
     * percentage. Test-mode rows are left out of both. Keyed by intent id, so a
     * later run writes nothing.
     *
     * @param list<array{key:string,campaign:Campaign,email:string,first:string,last:string,cents:int}> $specs
     */
    private function brandingDonations(array $specs): void
    {
        $donations = $this->container()->get(DonationService::class);

        $noMail    = static fn () => true;
        $noReceipt = static fn () => false;
        add_filter('pre_wp_mail', $noMail, 99);
        add_filter('gratora.receipt.should_issue', $noReceipt, 99);

        $campaignIds = [];
        try {
            foreach ($specs as $spec) {
                $campaignIds[(int) $spec['campaign']->id] = true;

                if (Donation::query()->where('gateway_intent_id', $spec['key'])->get() !== null) {
                    continue;
                }

                $donation = $donations->createPending(new DonationIntent(
                    email:          $spec['email'],
                    amount_cents:   $spec['cents'],
                    currency:       strtoupper(Money::defaultCurrency()),
                    gateway:        'offline',
                    form_id:        ((int) ($spec['campaign']->default_form_id ?? 0)) ?: null,
                    campaign_id:    (int) $spec['campaign']->id,
                    profile:        ['first_name' => $spec['first'], 'last_name' => $spec['last']],
                    payment_method: 'bank_transfer',
                    is_test:        false,
                ))['donation'];
                $donations->setGatewayIntent($donation, $spec['key']);
                $donations->confirm($donation, ['gateway_txn_id' => $spec['key'], 'payment_method' => 'bank_transfer']);
                WP_CLI::log("  donation recorded: {$spec['key']}");
            }
        } finally {
            remove_filter('pre_wp_mail', $noMail, 99);
            remove_filter('gratora.receipt.should_issue', $noReceipt, 99);
        }

        $aggregates = $this->container()->get(AggregateSyncer::class);
        foreach (array_keys($campaignIds) as $id) {
            $aggregates->syncCampaign($id);
        }
    }

    private function brandingForm(FormService $forms, string $slug, string $title, int $campaignId, string $container, ?string $blocks = null): Form
    {
        $input = [
            'campaign_id' => $campaignId,
            'blocks'      => $blocks ?? self::brandingFormBlocks(),
            'status'      => 'published',
            'settings'    => [
                'layout'    => 'inline',
                'container' => ['style' => $container, 'width' => 540],
                'recurring' => ['enabled' => true, 'frequencies' => ['monthly']],
                'gateways'  => ['allowed' => []],
            ],
        ];

        $form = Form::query()->where('slug', $slug)->get();
        if (! $form) {
            $form = $forms->create($input + ['title' => $title, 'slug' => $slug]);
            WP_CLI::log("  form created: {$slug} id={$form->id}");
        } else {
            $forms->update($form, $input);
            WP_CLI::log("  form updated: {$slug} id={$form->id}");
        }

        return $form;
    }

    /** @return string the page's path */
    private function brandingPage(string $slug, string $title, string $content, ?Campaign $campaign): string
    {
        $page = get_page_by_path($slug, OBJECT, 'page');
        if ($page && Campaign::query()->where('page_id', (int) $page->ID)->get()) {
            WP_CLI::error("Page {$slug} belongs to a campaign. Rename that campaign's page and run again.");
        }

        $post = [
            'post_title'   => $title,
            'post_name'    => $slug,
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_content' => $content,
        ];
        $id = $page ? wp_update_post(['ID' => $page->ID] + $post, true) : wp_insert_post($post, true);
        if (is_wp_error($id)) {
            WP_CLI::error("Page {$slug} not written: " . $id->get_error_message());
        }

        if ($campaign) {
            update_post_meta((int) $id, '_gratora_campaign_id', (int) $campaign->id);
        } else {
            delete_post_meta((int) $id, '_gratora_campaign_id');
        }
        WP_CLI::log("  page ready: {$slug} id={$id}");

        return (string) wp_parse_url((string) get_permalink((int) $id), PHP_URL_PATH);
    }

    /** Every surface the form paints text on: goal, tiles with an impact line, tabs, fund options, fields, checks, summary. */
    private static function brandingFormBlocks(): string
    {
        return implode("\n", [
            '<!-- wp:gratora/heading {"text":"Support the branding fixture","level":2} /-->',
            '<!-- wp:gratora/goal {"showDeadline":true} /-->',
            '<!-- wp:gratora/donation-amount {"presets":[{"cents":2500,"impact":"A week of meals","preselected":false},{"cents":5000,"impact":"","preselected":true},{"cents":10000,"impact":"","preselected":false},{"cents":25000,"impact":"","preselected":false}]} /-->',
            '<!-- wp:gratora/recurring-toggle {"label":"Make this a recurring donation","style":"tabs","frequencies":["one-time","monthly"]} /-->',
            '<!-- wp:gratora/fund-picker {"label":"Where it goes"} /-->',
            '<!-- wp:gratora/name {"requireFirst":true,"requireLast":true} /-->',
            '<!-- wp:gratora/email {"required":true} /-->',
            '<!-- wp:gratora/comment {"label":"Add a message of support"} /-->',
            '<!-- wp:gratora/anonymous-toggle {"label":"Hide my name from the supporter wall"} /-->',
            '<!-- wp:gratora/cover-fees {"label":"Cover the processing fee","defaultOn":true} /-->',
            '<!-- wp:gratora/payment-gateways /-->',
            '<!-- wp:gratora/donation-summary /-->',
            '<!-- wp:gratora/submit-button {"label":"Donate {amount}"} /-->',
        ]);
    }

    /** The blocks the Develop canvas draws with inline styles, each in the style the runtime draws on the soft ground. */
    private static function brandingCanvasBlocks(): string
    {
        return implode("\n", [
            '<!-- wp:gratora/heading {"text":"Support the branding fixture","level":2} /-->',
            '<!-- wp:gratora/donation-amount {"presets":[{"cents":2500,"impact":"A week of meals","preselected":false},{"cents":5000,"impact":"A month of meals","preselected":true},{"cents":10000,"impact":"","preselected":false}]} /-->',
            '<!-- wp:gratora/recurring-toggle {"label":"Make this a recurring donation","style":"pills","frequencies":["one-time","monthly"]} /-->',
            '<!-- wp:gratora/currency-switcher {"style":"pills","currencies":["USD","EUR","GBP","CHF"]} /-->',
            '<!-- wp:gratora/name {"requireFirst":true,"requireLast":true} /-->',
            '<!-- wp:gratora/email {"required":true} /-->',
            '<!-- wp:gratora/payment-gateways /-->',
            '<!-- wp:gratora/submit-button {"label":"Donate {amount}"} /-->',
        ]);
    }

    /** An accent panel holding the host's own empty list and a guest campaign's. */
    private static function panelBlocks(int $host, int $guest): string
    {
        return implode("\n", [
            '<!-- wp:group {"className":"dp-panel dp-panel--accent"} -->',
            '<div class="wp-block-group dp-panel dp-panel--accent">',
            '<!-- wp:gratora/top-donors {"campaignId":' . $host . ',"title":"Top donors host"} /-->',
            '<!-- wp:gratora/top-donors {"campaignId":' . $guest . ',"title":"Top donors guest"} /-->',
            '</div>',
            '<!-- /wp:group -->',
        ]);
    }

    /** A guest campaign's figures and lists straight on the theme's white page. */
    private static function whiteHostBlocks(int $guest): string
    {
        return implode("\n", [
            '<!-- wp:gratora/campaign-progress {"campaignId":' . $guest . '} /-->',
            '<!-- wp:gratora/campaign-stat {"campaignId":' . $guest . ',"metric":"raised","size":"lg"} /-->',
            '<!-- wp:gratora/campaign-stat {"campaignId":' . $guest . ',"metric":"goal"} /-->',
            '<!-- wp:gratora/recent-donations {"campaignId":' . $guest . ',"title":"Recent guest","limit":5} /-->',
            '<!-- wp:gratora/top-donors {"campaignId":' . $guest . ',"title":"Top guest","limit":5,"layout":"list"} /-->',
        ]);
    }

    /** A guest campaign's donate button and form on Quiet, which sets the button colours the guest leaves to its accent. */
    private static function quietHostBlocks(int $guest, string $form): string
    {
        return implode("\n", [
            '<!-- wp:gratora/donate-button {"campaignId":' . $guest . ',"label":"Donate in a modal"} /-->',
            $form,
        ]);
    }

    /** A guest grid and the host's own on Bold's red card, and an empty list on that card. */
    private static function boldHostBlocks(int $guest, int $host): string
    {
        return implode("\n", [
            '<!-- wp:gratora/campaign-grid {"campaignId":' . $guest . ',"count":12,"className":"e2e-grid-guest"} /-->',
            '<!-- wp:gratora/campaign-grid {"campaignId":' . $host . ',"count":12,"className":"e2e-grid-host"} /-->',
            '<!-- wp:gratora/top-donors {"campaignId":' . $host . ',"title":"Top donors bold"} /-->',
        ]);
    }

    private static function conditionalBlocks(): string
    {
        $dropdown = wp_json_encode([
            'label'   => 'How did you hear about us?',
            'field'   => 'cond_trigger',
            'options' => [
                ['value' => 'friend', 'label' => 'A friend'],
                ['value' => 'social', 'label' => 'Social media'],
                ['value' => 'event',  'label' => 'An event'],
            ],
        ]);
        $headingShownForSocial = wp_json_encode([
            'text'      => 'CONDITIONAL_HEADING_SOCIAL',
            'level'     => 3,
            'condition' => ['field' => 'custom.cond_trigger', 'op' => '=', 'value' => 'social'],
        ]);
        $hiddenRequiredTextInput = wp_json_encode([
            'label'     => 'How did your friend hear about us?',
            'field'     => 'cond_friend_referrer',
            'required'  => true,
            'condition' => ['field' => 'custom.cond_trigger', 'op' => '=', 'value' => 'friend'],
        ]);
        $commentVisibleWhenAnyValue = wp_json_encode([
            'label'     => 'CONDITIONAL_COMMENT_ANY',
            'condition' => ['field' => 'custom.cond_trigger', 'op' => '!=', 'value' => ''],
        ]);

        return implode("\n", [
            '<!-- wp:gratora/heading {"text":"Support our work","level":2} /-->',
            '<!-- wp:gratora/donation-amount {"presets":[1000,2500,5000,10000],"allowCustom":true,"currency":"EUR"} /-->',
            '<!-- wp:gratora/name {"requireFirst":true,"requireLast":true} /-->',
            '<!-- wp:gratora/email {"required":true} /-->',
            '<!-- wp:gratora/dropdown ' . $dropdown . ' /-->',
            '<!-- wp:gratora/heading ' . $headingShownForSocial . ' /-->',
            '<!-- wp:gratora/text-input ' . $hiddenRequiredTextInput . ' /-->',
            '<!-- wp:gratora/comment ' . $commentVisibleWhenAnyValue . ' /-->',
            '<!-- wp:gratora/payment-gateways {"style":"radio","allowed":["offline","sandbox"]} /-->',
            '<!-- wp:gratora/donation-summary /-->',
            '<!-- wp:gratora/submit-button {"label":"Donate now"} /-->',
        ]);
    }
}

if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('gratora e2e-seed', [new E2eSeedCommand(), 'seed']);
    WP_CLI::add_command('gratora e2e-seed-branding', [new E2eSeedCommand(), 'seedBranding']);
}
