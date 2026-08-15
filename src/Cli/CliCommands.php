<?php

declare(strict_types=1);

namespace Dono\Cli;

use Dono\Campaigns\Campaign;
use Dono\Campaigns\CampaignService;
use Dono\Currency\FxRates;
use Dono\Donations\AggregateSyncer;
use Dono\Donations\DonationIntent;
use Dono\Donations\DonationService;
use Dono\Donors\Donor;
use Dono\Donors\DonorService;
use Dono\Forms\Form;
use Dono\Forms\FormService;
use Dono\Foundation\Plugin;
use Dono\Foundation\Time\Clock;
use Dono\Funds\Fund;
use Dono\Funds\FundService;
use Dono\Onboarding\Onboarding;
use Dono\Recurring\RecurringPlanRepository;
use Dono\Settings\SettingsService;
use WP_CLI;

/**
 * `wp dono ...` commands. Registered only under WP-CLI (see dono.php).
 * Operational commands (migrate, recompute-aggregates) are production-safe;
 * seed writes fake data and is gated on org-wide test mode.
 *
 * @since 1.0.0
 */
final class CliCommands
{
    /** @since 1.0.0 */
    private function container(): \Dono\Foundation\Container\Container
    {
        return Plugin::instance()->container;
    }

    /**
     * Run every registered model's schema migration (idempotent). Safe to run
     * on production after a deploy that changed a schema closure.
     *
     * @when after_wp_load
     * @since 1.0.0
     */
    public function migrate(array $args, array $assoc): void
    {
        $count = 0;
        foreach (Plugin::instance()->modules->allMigrations() as $model) {
            if (! method_exists($model, 'migrate')) {
                continue;
            }
            $t0 = microtime(true);
            $model::migrate(true);
            WP_CLI::log(sprintf('  %-52s %8.1f ms', $model, (microtime(true) - $t0) * 1000));
            $count++;
        }
        WP_CLI::success("Ran {$count} model migrations.");
    }

    /**
     * Recompute denormalized aggregates from the source-of-truth donation
     * rows. Production-safe (read-then-write of derived counters only).
     *
     * ## OPTIONS
     *
     * [--scope=<scope>]
     * : Which aggregates to recompute.
     * ---
     * default: all
     * options:
     *   - all
     *   - donors
     *   - funds
     *   - campaigns
     *   - forms
     * ---
     *
     * @when after_wp_load
     * @since 1.0.0
     */
    public function recompute_aggregates(array $args, array $assoc): void
    {
        $scope = (string) ($assoc['scope'] ?? 'all');
        $agg   = $this->container()->get(AggregateSyncer::class);

        $run = function (string $label, array $ids, callable $sync): void {
            if ($ids === []) {
                WP_CLI::log("  {$label}: nothing to do");
                return;
            }
            $bar = \WP_CLI\Utils\make_progress_bar("  {$label}", count($ids));
            foreach ($ids as $id) {
                $sync((int) $id);
                $bar->tick();
            }
            $bar->finish();
        };

        if ($scope === 'all' || $scope === 'donors') {
            $run('donors', $this->ids(Donor::class), fn (int $id) => $agg->syncDonor($id));
        }
        if ($scope === 'all' || $scope === 'funds') {
            $run('funds', $this->ids(Fund::class), fn (int $id) => $agg->syncFund($id));
        }
        if ($scope === 'all' || $scope === 'campaigns') {
            $run('campaigns', $this->ids(Campaign::class), fn (int $id) => $agg->syncCampaign($id));
        }
        if ($scope === 'all' || $scope === 'forms') {
            $run('forms', $this->ids(Form::class), fn (int $id) => $agg->syncForm($id));
        }

        WP_CLI::success('Aggregates recomputed.');
    }

    /**
     * Seed fake test donations so the admin has data to explore. Refuses
     * unless org-wide test mode is on, so it can never pollute a live site.
     * Seeded donations are flagged is_test and excluded from money reporting.
     *
     * ## OPTIONS
     *
     * [--donations=<n>]
     * : How many paid donations to create.
     * ---
     * default: 20
     * ---
     *
     * [--yes]
     * : Skip the confirmation prompt.
     *
     * @when after_wp_load
     * @since 1.0.0
     */
    public function seed(array $args, array $assoc): void
    {
        $gateways = $this->container()->get(SettingsService::class)->get('gateways');
        if (empty($gateways['test_mode'])) {
            WP_CLI::error(
                'Refusing to seed: org-wide test mode is off. Turn it on in '
                . 'Settings, Payment gateways (it is also set automatically by '
                . 'the "just exploring" onboarding path).'
            );
        }

        $n = max(1, min(1000, (int) ($assoc['donations'] ?? 20)));

        $campaign = Campaign::query()->where('status', 'published')->get()
            ?? Campaign::query()->get();
        if (! $campaign) {
            WP_CLI::error('No campaign found. Complete onboarding first.');
        }

        WP_CLI::confirm("Create {$n} fake test donations on campaign \"{$campaign->title}\"?", $assoc);

        $service  = $this->container()->get(DonationService::class);
        $agg      = $this->container()->get(AggregateSyncer::class);
        $currency = strtoupper((string) ($campaign->currency ?: 'USD'));
        $formId   = ((int) ($campaign->default_form_id ?? 0)) ?: null;
        $amounts  = [500, 1000, 1500, 2500, 5000, 10000, 25000];
        $stamp    = time();

        $donorIds = [];
        $bar = \WP_CLI\Utils\make_progress_bar('  seeding', $n);
        for ($i = 1; $i <= $n; $i++) {
            $intent = new DonationIntent(
                email:        "seed+{$stamp}-{$i}@example.test",
                amount_cents: $amounts[array_rand($amounts)],
                currency:     $currency,
                gateway:      'offline',
                frequency:    'one_time',
                form_id:      $formId,
                campaign_id:  (int) $campaign->id,
                profile:      ['first_name' => 'Seed', 'last_name' => 'Donor ' . $i],
            );

            $created  = $service->createPending($intent);
            $donation = $created['donation'];
            $service->confirm($donation, [
                'gateway_txn_id' => 'seed_txn_' . $donation->reference,
                'payment_method' => 'offline',
            ]);

            // Spread paid_at over the last ~90 days so time-series views
            // have something to show.
            $when = gmdate('Y-m-d H:i:s', $stamp - random_int(0, 90 * 86400));
            $donation->created_at = $when;
            $donation->paid_at    = $when;
            $donation->save();

            $donorIds[$donation->donor_id] = true;
            $bar->tick();
        }
        $bar->finish();

        // Backdating bypassed the event-driven sync; recompute what we touched.
        $agg->syncCampaign((int) $campaign->id);
        if ($formId !== null) {
            $agg->syncForm($formId);
        }
        foreach (array_keys($donorIds) as $donorId) {
            $agg->syncDonor((int) $donorId);
        }

        WP_CLI::success("Seeded {$n} test donations on \"{$campaign->title}\".");
    }

    /**
     * Seed a year of plausible fundraising history so the admin screens can be
     * screenshotted against something that looks like a real organisation.
     *
     * Unlike `wp dono seed`, the rows are live (is_test = 0): test-mode rows
     * are excluded from money reporting by design, so a dashboard seeded with
     * them renders empty. That makes this unsafe anywhere real money is
     * recorded, and it refuses when it finds any.
     *
     * Idempotent: every row carries a stable key, and a second run converges
     * instead of duplicating.
     *
     * Creates 7 campaigns at different progress levels and statuses, 5 funds,
     * 140 donors, ~620 one-time donations spread over the last 12 months
     * (paid, pending, failed, refunded, partially refunded, disputed), and 44
     * recurring plans (active, paused, past_due, cancelled) with their renewal
     * donations.
     *
     * ## OPTIONS
     *
     * [--force]
     * : Seed even though the install already holds live donations this command
     * did not write. Only for a throwaway install.
     *
     * [--purge]
     * : Remove the rows this command wrote instead of writing more. Matched on
     * the demo key, so nothing the org recorded itself is in range.
     *
     * [--yes]
     * : Skip the confirmation prompt.
     *
     * ## EXAMPLES
     *
     *     wp dono demo-seed
     *     wp dono demo-seed --yes
     *     wp dono demo-seed --purge
     *
     * @when after_wp_load
     * @since 1.0.0
     */
    public function demo_seed(array $args, array $assoc): void
    {
        if (! empty($assoc['purge'])) {
            $this->demo_purge($assoc);
            return;
        }

        $foreign = DemoSeeder::foreignLiveDonations();
        if ($foreign > 0 && empty($assoc['force'])) {
            WP_CLI::error(sprintf(
                'Refusing to seed: this install already holds %d live donations that this '
                . 'command did not write. Demo data is written live, so it would mix into '
                . 'the org\'s own reporting. Pass --force only on a throwaway install.',
                $foreign
            ));
        }

        WP_CLI::confirm(
            'Write ~1,000 live demo donations, 140 donors, 7 campaigns and 44 recurring '
            . 'plans into this install?',
            $assoc
        );

        // Onboarding gates every Dono admin screen while it is pending, and a
        // screenshot run needs the screens, not the wizard.
        update_option(Onboarding::OPTION, 'completed', false);

        $t0     = microtime(true);
        $counts = $this->demoSeeder()->run(static fn (string $line) => WP_CLI::log('  ' . $line));

        WP_CLI::success(sprintf(
            'Demo data ready in %.1fs: %d campaigns, %d funds, %d donors, %d donations '
            . '(%d recurring renewals), %d recurring plans, %d rows already present.',
            microtime(true) - $t0,
            $counts['campaigns'],
            $counts['funds'],
            $counts['donors'],
            $counts['donations'],
            $counts['renewals'],
            $counts['recurring_plans'],
            $counts['skipped'],
        ));
    }

    /**
     * The `--purge` half of demo-seed: take back exactly what it wrote.
     *
     * Demo rows are live by design, so the maintenance purge (which reads
     * is_test) cannot see them and the admin has no way to delete a donation.
     * Without this the only route back is hand-written SQL.
     *
     * @param array<string,mixed> $assoc
     *
     * @since 1.0.0
     */
    private function demo_purge(array $assoc): void
    {
        $seeder  = $this->demoSeeder();
        $planned = $seeder->purgePreview();

        if (array_sum($planned) === 0) {
            WP_CLI::success('Nothing to remove: this install holds no demo rows.');
            return;
        }

        WP_CLI::confirm(sprintf(
            'Remove %d demo donations, %d demo recurring plans and %d demo donors from '
            . 'this install? Demo campaigns, funds and their pages are left for you to '
            . 'delete in the admin.',
            $planned['donations'],
            $planned['recurring_plans'],
            $planned['donors'],
        ), $assoc);

        $t0      = microtime(true);
        $removed = $seeder->purge(static fn (string $line) => WP_CLI::log('  ' . $line));

        WP_CLI::success(sprintf(
            'Demo data removed in %.1fs: %d donations, %d recurring plans, %d donors.',
            microtime(true) - $t0,
            $removed['donations'],
            $removed['recurring_plans'],
            $removed['donors'],
        ));
    }

    /** @since 1.0.0 */
    private function demoSeeder(): DemoSeeder
    {
        $c = $this->container();

        return new DemoSeeder(
            $c->get(DonationService::class),
            $c->get(DonorService::class),
            $c->get(CampaignService::class),
            $c->get(FundService::class),
            $c->get(AggregateSyncer::class),
            $c->get(RecurringPlanRepository::class),
            $c->get(Clock::class),
        );
    }

    /**
     * Create / refresh a canonical "kitchen sink" donation form for the
     * Playwright e2e suite. Idempotent: re-running keeps the same slugs and
     * just updates the form blocks + page so the canonical form converges to
     * whatever the current spec set expects.
     *
     * Sets up:
     *   - Campaign "Dono E2E" (status=published)
     *   - Form "Dono E2E Form" (status=published) with every donor block the
     *     spec suite asserts against
     *   - WP page "Dono E2E" containing [dono_donation_form slug="..."]
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
     *     wp dono e2e-seed
     *     # then in your shell:
     *     export DONO_E2E_URL="http://localhost:10075"
     *     export DONO_E2E_FORM_PATH="/dono-e2e/"
     *     export DONO_E2E_MULTI_STEP_FORM_PATH="/dono-e2e-wizard/"
     *
     * @when after_wp_load
     * @since 1.0.0
     */
    public function e2e_seed(array $args, array $assoc): void
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
        // admin screen redirects to it. A spec that drives a Dono admin page
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

        $this->e2ePinFxRates();

        // Org-wide test mode on. Required for AntiSpamGuard to relax the IP
        // and email rate limits (automation bursts through the prod caps).
        $gatewayConfig = get_option('dono_gateway_config', []);
        if (! is_array($gatewayConfig)) $gatewayConfig = [];
        $gatewayConfig['test_mode'] = true;
        update_option('dono_gateway_config', $gatewayConfig, false);

        // Drop AntiSpamGuard rate-limit transients so a run isn't penalized
        // by prior attempts from the same IP / email range.
        global $wpdb;
        $wpdb->query(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_dono_donate_%' OR option_name LIKE '_transient_timeout_dono_donate_%'"
        );

        $campaign = Campaign::query()->where('slug', 'dono-e2e')->get();
        if (! $campaign) {
            $campaign = $campaigns->create([
                'title'         => 'Dono E2E',
                'slug'          => 'dono-e2e',
                'status'        => 'published',
                'skip_template' => true,
            ]);
            WP_CLI::log("  campaign created: id={$campaign->id}");
        } else {
            $campaign->status = 'published';
            $campaign->save();
            WP_CLI::log("  campaign reused: id={$campaign->id}");
        }

        $this->e2eRegisterConsentPurposes();
        $this->e2eSeedFundsAndGoal($campaign);

        $singleUrl = $this->e2eUpsertFormAndPage(
            $forms,
            (int) $campaign->id,
            'dono-e2e-form',
            'Dono E2E Form',
            'dono-e2e',
            'Dono E2E',
            self::e2eCanonicalBlocks()
        );
        $multiUrl = $this->e2eUpsertFormAndPage(
            $forms,
            (int) $campaign->id,
            'dono-e2e-wizard',
            'Dono E2E Wizard',
            'dono-e2e-wizard',
            'Dono E2E Wizard',
            self::e2eMultiStepBlocks()
        );
        $condUrl = $this->e2eUpsertFormAndPage(
            $forms,
            (int) $campaign->id,
            'dono-e2e-conditional',
            'Dono E2E Conditional',
            'dono-e2e-conditional',
            'Dono E2E Conditional',
            self::e2eConditionalBlocks()
        );
        $customUrl = $this->e2eUpsertFormAndPage(
            $forms,
            (int) $campaign->id,
            'dono-e2e-custom-fields',
            'Dono E2E Custom Fields',
            'dono-e2e-custom-fields',
            'Dono E2E Custom Fields',
            self::e2eCustomFieldsBlocks()
        );
        $layoutUrl = $this->e2eUpsertFormAndPage(
            $forms,
            (int) $campaign->id,
            'dono-e2e-layout',
            'Dono E2E Layout',
            'dono-e2e-layout',
            'Dono E2E Layout',
            self::e2eLayoutBlocks()
        );

        WP_CLI::success("Canonical forms ready.");
        WP_CLI::log('  export DONO_E2E_URL="' . untrailingslashit(home_url()) . '"');
        WP_CLI::log('  export DONO_E2E_FORM_PATH="' . wp_parse_url($singleUrl, PHP_URL_PATH) . '"');
        WP_CLI::log('  export DONO_E2E_MULTI_STEP_FORM_PATH="' . wp_parse_url($multiUrl, PHP_URL_PATH) . '"');
        WP_CLI::log('  export DONO_E2E_CONDITIONAL_FORM_PATH="' . wp_parse_url($condUrl, PHP_URL_PATH) . '"');
        WP_CLI::log('  export DONO_E2E_CUSTOM_FIELDS_FORM_PATH="' . wp_parse_url($customUrl, PHP_URL_PATH) . '"');
        WP_CLI::log('  export DONO_E2E_LAYOUT_FORM_PATH="' . wp_parse_url($layoutUrl, PHP_URL_PATH) . '"');
    }

    /**
     * Idempotent create-or-update for an e2e form + its embedding page.
     * Returns the public URL of the page.
     *
     * @since 1.0.0
     */
    private function e2eUpsertFormAndPage(
        FormService $forms,
        int $campaignId,
        string $formSlug,
        string $formTitle,
        string $pageSlug,
        string $pageTitle,
        string $blocks
    ): string {
        // Pinned rather than left to the shortcode default. The default is a
        // product decision that is allowed to change, and every visual golden
        // moves with it: the fixture asserts its own appearance.
        $settings = [
            'gateways'  => ['allowed' => ['offline', 'sandbox']],
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

        $content = '[dono_donation_form slug="' . esc_attr($form->slug) . '"]';
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

    /**
     * A fixed FX snapshot, so a currency switch converts.
     *
     * The rates normally arrive from a daily cron making a live call, which
     * means a fixture that has never run it has no rates at all: the switcher
     * still changes the symbol, conversion silently no-ops, and USD renders
     * identical to EUR. A visual golden captured in that state stops testing
     * conversion without ever failing. auto is off so the cron cannot replace
     * these with live rates mid-run and move every amount.
     *
     * @since 1.0.0
     */
    private function e2ePinFxRates(): void
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
     *
     * @since 1.0.0
     */
    private function e2eSeedFundsAndGoal(Campaign $campaign): void
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

    /**
     * The consent block names purposes; the org defines them. Core ships none,
     * so the fixture registers its own before seeding a form that asks for them.
     *
     * @since 1.0.0
     */
    private function e2eRegisterConsentPurposes(): void
    {
        $this->container()->get(SettingsService::class)->update('consents', [
            'purposes' => [
                ['key' => 'tos',     'label' => 'I accept the terms',               'description' => '', 'required' => true,  'default' => false, 'version' => 1],
                ['key' => 'updates', 'label' => 'Send me updates about this cause', 'description' => '', 'required' => false, 'default' => false, 'version' => 1],
            ],
        ]);
        WP_CLI::log('  consent purposes registered: tos, updates');
    }

    /** @since 1.0.0 */
    private static function e2eCanonicalBlocks(): string
    {
        // Keys only. The purposes themselves are registered on the org, which
        // e2eRegisterConsentPurposes does before the form is seeded.
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
            '<!-- wp:dono/heading {"text":"Support our work","level":2} /-->',
            '<!-- wp:dono/currency-switcher {"currencies":["EUR","USD","GBP"]} /-->',
            '<!-- wp:dono/donation-amount {"presets":[1000,2500,5000,10000],"allowCustom":true,"currency":"EUR"} /-->',
            '<!-- wp:dono/name {"requireFirst":true,"requireLast":true} /-->',
            '<!-- wp:dono/email {"required":true} /-->',
            '<!-- wp:dono/country /-->',
            '<!-- wp:dono/address {"requireLine1":false,"requireCity":false,"requireRegion":false,"requirePostal":false,"requireCountry":false} /-->',
            '<!-- wp:dono/phone /-->',
            '<!-- wp:dono/comment {"label":"Add a message"} /-->',
            '<!-- wp:dono/anonymous-toggle /-->',
            '<!-- wp:dono/cover-fees /-->',
            '<!-- wp:dono/date {"label":"Preferred call date","field":"call_date"} /-->',
            '<!-- wp:dono/dropdown ' . $dropdown . ' /-->',
            '<!-- wp:dono/consent ' . $consent . ' /-->',
            '<!-- wp:dono/payment-gateways {"style":"radio","allowed":["offline","sandbox"]} /-->',
            '<!-- wp:dono/donation-summary /-->',
            '<!-- wp:dono/submit-button {"label":"Donate now"} /-->',
        ]);
    }

    /**
     * Multi-step variant: a dono/steps wizard with at least an amount step
     * and a donor step so multi-step.spec.ts can exercise the Continue flow.
     *
     * @since 1.0.0
     */
    private static function e2eMultiStepBlocks(): string
    {
        return <<<'BLOCKS'
<!-- wp:dono/steps -->
<!-- wp:dono/step {"title":"Your donation"} -->
<!-- wp:dono/donation-amount {"presets":[1000,2500,5000,10000],"allowCustom":true,"currency":"EUR"} /-->
<!-- /wp:dono/step -->

<!-- wp:dono/step {"title":"Your info"} -->
<!-- wp:dono/name {"requireFirst":true,"requireLast":true} /-->
<!-- wp:dono/email {"required":true} /-->
<!-- /wp:dono/step -->

<!-- wp:dono/step {"title":"Confirm"} -->
<!-- wp:dono/payment-gateways {"style":"radio","allowed":["offline","sandbox"]} /-->
<!-- wp:dono/donation-summary /-->
<!-- wp:dono/submit-button {"label":"Donate now"} /-->
<!-- /wp:dono/step -->
<!-- /wp:dono/steps -->
BLOCKS;
    }

    /**
     * Layout + content blocks: heading, paragraph, html, divider, columns,
     * row, section, recurring-toggle, fund-picker, privacy-notice, goal.
     * Each gets a unique marker so the spec can assert the block survived to
     * the public render in the right shape.
     *
     * @since 1.0.0
     */
    private static function e2eLayoutBlocks(): string
    {
        return <<<'BLOCKS'
<!-- wp:dono/heading {"text":"LAYOUT_HEADING_TEXT","level":2} /-->
<!-- wp:dono/paragraph {"text":"LAYOUT_PARAGRAPH_TEXT"} /-->
<!-- wp:dono/html {"content":"<span class=\"layout-html-marker\">LAYOUT_HTML_TEXT</span>"} /-->
<!-- wp:dono/divider {"marginTop":24,"marginBottom":24,"thickness":2,"color":"#cccccc"} /-->

<!-- wp:dono/section {"label":"LAYOUT_SECTION_LABEL"} -->
<!-- wp:dono/paragraph {"text":"Inside a section"} /-->
<!-- /wp:dono/section -->

<!-- wp:dono/columns {"columns":2,"gap":20,"gapUnit":"px"} -->
<!-- wp:dono/heading {"text":"LAYOUT_COL_LEFT","level":4} /-->
<!-- wp:dono/heading {"text":"LAYOUT_COL_RIGHT","level":4} /-->
<!-- /wp:dono/columns -->

<!-- wp:dono/row {"columns":2,"gap":14,"gapUnit":"px"} -->
<!-- wp:dono/name /-->
<!-- wp:dono/email /-->
<!-- /wp:dono/row -->

<!-- wp:dono/donation-amount {"presets":[1000,2500,5000,10000],"allowCustom":true,"currency":"EUR"} /-->
<!-- wp:dono/recurring-toggle {"label":"LAYOUT_RECURRING_LABEL","frequencies":["one-time","monthly"]} /-->
<!-- wp:dono/fund-picker {"label":"LAYOUT_FUND_LABEL"} /-->
<!-- wp:dono/goal {"showAmount":true} /-->
<!-- wp:dono/privacy-notice {"text":"LAYOUT_PRIVACY_TEXT"} /-->
<!-- wp:dono/payment-gateways {"style":"radio","allowed":["offline","sandbox"]} /-->
<!-- wp:dono/donation-summary /-->
<!-- wp:dono/submit-button {"label":"Donate now"} /-->
BLOCKS;
    }

    /** @since 1.0.0 */
    private static function e2eCustomFieldsBlocks(): string
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
            '<!-- wp:dono/heading {"text":"Custom Fields Form","level":2} /-->',
            '<!-- wp:dono/donation-amount {"presets":[1000,2500,5000,10000],"allowCustom":true,"currency":"EUR"} /-->',
            '<!-- wp:dono/name {"requireFirst":true,"requireLast":true} /-->',
            '<!-- wp:dono/email {"required":true} /-->',
            '<!-- wp:dono/text-input {"label":"CUSTOM_TEXT_LABEL","field":"cf_text","placeholder":"Type something"} /-->',
            '<!-- wp:dono/number-input {"label":"CUSTOM_NUMBER_LABEL","field":"cf_number","min":1,"max":100} /-->',
            '<!-- wp:dono/radio ' . $radio . ' /-->',
            '<!-- wp:dono/checkbox {"label":"CUSTOM_CHECKBOX_LABEL","field":"cf_check"} /-->',
            '<!-- wp:dono/multi-select ' . $multi . ' /-->',
            '<!-- wp:dono/hidden {"field":"cf_hidden","defaultValue":"hidden-default"} /-->',
            '<!-- wp:dono/payment-gateways {"style":"radio","allowed":["offline","sandbox"]} /-->',
            '<!-- wp:dono/donation-summary /-->',
            '<!-- wp:dono/submit-button {"label":"Donate now"} /-->',
        ]);
    }

    /** @since 1.0.0 */
    private static function e2eConditionalBlocks(): string
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
            '<!-- wp:dono/heading {"text":"Support our work","level":2} /-->',
            '<!-- wp:dono/donation-amount {"presets":[1000,2500,5000,10000],"allowCustom":true,"currency":"EUR"} /-->',
            '<!-- wp:dono/name {"requireFirst":true,"requireLast":true} /-->',
            '<!-- wp:dono/email {"required":true} /-->',
            '<!-- wp:dono/dropdown ' . $dropdown . ' /-->',
            '<!-- wp:dono/heading ' . $headingShownForSocial . ' /-->',
            '<!-- wp:dono/text-input ' . $hiddenRequiredTextInput . ' /-->',
            '<!-- wp:dono/comment ' . $commentVisibleWhenAnyValue . ' /-->',
            '<!-- wp:dono/payment-gateways {"style":"radio","allowed":["offline","sandbox"]} /-->',
            '<!-- wp:dono/donation-summary /-->',
            '<!-- wp:dono/submit-button {"label":"Donate now"} /-->',
        ]);
    }

    /**
     * @param class-string $model
     * @return list<int>
     * @since 1.0.0
     */
    private function ids(string $model): array
    {
        $rows = $model::query()->getAll();
        return array_values(array_map(static fn ($r) => (int) $r->id, $rows));
    }
}
