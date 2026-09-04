<?php

declare(strict_types=1);

namespace FundKit\Core;

use FundKit\Analytics\ErrorLog;
use FundKit\Admin\AdminFooter;
use FundKit\Admin\AdminGlobals;
use FundKit\Admin\AdminMenu;
use FundKit\Admin\Pages\CampaignsPage;
use FundKit\Admin\Pages\DonationsPage;
use FundKit\Admin\Pages\SubscriptionsPage;
use FundKit\Admin\Pages\DonorsPage;
use FundKit\Admin\Pages\FormsPage;
use FundKit\Admin\Pages\FundsPage;
use FundKit\Admin\Pages\ToolsPage;
use FundKit\Admin\DeactivationDialog;
use FundKit\Admin\ManagedPageStates;
use FundKit\Admin\ProxyNotice;
use FundKit\Admin\TestModeBadge;
use FundKit\Admin\Pages\SettingsPage;
use FundKit\Analytics\Event;
use FundKit\Analytics\EventRecorder;
use FundKit\Async\AsyncDispatcher;
use FundKit\Campaigns\CampaignPermalinks;
use FundKit\Campaigns\CampaignTypeRegistry;
use FundKit\Campaigns\DefaultCampaignTypeHandler;
use FundKit\Currency\FxBackfill;
use FundKit\Currency\FxRates;
use FundKit\Currency\FxRatesUpdater;
use FundKit\Campaigns\Campaign;
use FundKit\Campaigns\CampaignChrome;
use FundKit\Campaigns\CampaignPageTemplate;
use FundKit\Campaigns\Styling\PageStyle;
use FundKit\Campaigns\CampaignMetricsService;
use FundKit\Campaigns\CampaignStatMetrics;
use FundKit\Campaigns\CampaignRepository;
use FundKit\Campaigns\CampaignService;
use FundKit\Campaigns\SocialMeta;
use FundKit\Dashboard\DashboardMetricsService;
use FundKit\Donations\AggregateSyncer;
use FundKit\Donations\AntiSpamGuard;
use FundKit\Donations\Donation;
use FundKit\Donations\DonationEmails;
use FundKit\Donations\DonationNote;
use FundKit\Donations\DonationNoteRepository;
use FundKit\Donations\DonationRepository;
use FundKit\Donations\DonationService;
use FundKit\Donations\Refund;
use FundKit\Donors\Consent;
use FundKit\Donors\ConsentService;
use FundKit\Donors\Donor;
use FundKit\Donors\DonorAggregateSyncer;
use FundKit\Donors\DonorEmailRehasher;
use FundKit\Donors\DonorMetricsService;
use FundKit\Donors\DonorNote;
use FundKit\Donors\DonorNoteRepository;
use FundKit\Donors\DonorPurge;
use FundKit\Donors\DonorAvatarUploader;
use FundKit\Donors\DonorAvatars;
use FundKit\Donors\DonorRepository;
use FundKit\Donors\DonorService;
use FundKit\Donors\Erasure\AnalyticsEventHandler;
use FundKit\Foundation\Transfer\CsvImporter;
use FundKit\Foundation\Transfer\DataExporter;
use FundKit\Foundation\Transfer\DataImporter;
use FundKit\Foundation\Upgrade\RestoreReceiptsRetainingMoney;
use FundKit\Foundation\Upgrade\UpgradeRunner;
use FundKit\Foundation\Upgrade\UpgradeJob;
use FundKit\Foundation\Upgrade\UpgradeNotice;
use FundKit\Donors\Erasure\CoreDonorDataHandler;
use FundKit\Donors\Erasure\ErasureRegistry;
use FundKit\Donors\MagicLinkService;
use FundKit\Donors\MagicLinkToken;
use FundKit\Donors\PendingSignup;
use FundKit\Donors\PendingSignupRepository;
use FundKit\Donors\SignupRedemption;
use FundKit\Donors\Portal\AnnualStatementBuilder;
use FundKit\Donors\Portal\PortalPage;
use FundKit\Donors\Portal\PortalSession;
use FundKit\Donors\Portal\PortalShortcode;
use FundKit\Campaigns\Blocks\BlockEditorIntegration as CampaignBlockEditorIntegration;
use FundKit\Campaigns\Blocks\CampaignBindingPreviewController;
use FundKit\Campaigns\Blocks\CampaignBindings;
use FundKit\Campaigns\Blocks\CampaignGridBlock;
use FundKit\Campaigns\Blocks\CampaignImageBlock;
use FundKit\Campaigns\Blocks\CampaignProgressBlock;
use FundKit\Campaigns\Blocks\CampaignStatBlock;
use FundKit\Campaigns\Blocks\DonateButtonBlock;
use FundKit\Campaigns\Blocks\DonationFormBlock;
use FundKit\Campaigns\Blocks\RecentDonationsBlock;
use FundKit\Campaigns\Blocks\SupporterWallBlock;
use FundKit\Campaigns\Blocks\TopDonorsBlock;
use FundKit\Forms\Blocks\AddressBlock;
use FundKit\Forms\Blocks\AnonymousToggleBlock;
use FundKit\Forms\Blocks\BlockRegistry;
use FundKit\Forms\Blocks\CommentBlock;
use FundKit\Forms\Blocks\ConsentBlock;
use FundKit\Forms\Blocks\DonationSummaryBlock;
use FundKit\Forms\Blocks\TermsBlock;
use FundKit\Forms\Blocks\CountryBlock;
use FundKit\Forms\Blocks\CoverFeesBlock;
use FundKit\Forms\Blocks\CurrencySwitcherBlock;
use FundKit\Forms\Blocks\DividerBlock;
use FundKit\Forms\Blocks\DonationAmountBlock;
use FundKit\Forms\Blocks\PaymentGatewaysBlock;
use FundKit\Forms\Blocks\EmailBlock;
use FundKit\Forms\Blocks\FundPickerBlock;
use FundKit\Forms\Blocks\GoalBlock;
use FundKit\Forms\Blocks\HeadingBlock;
use FundKit\Forms\Blocks\NameBlock;
use FundKit\Forms\Blocks\ParagraphBlock;
use FundKit\Forms\Blocks\PhoneBlock;
use FundKit\Forms\Blocks\HiddenBlock;
use FundKit\Forms\Blocks\HtmlBlock;
use FundKit\Forms\Blocks\PrivacyNoticeBlock;
use FundKit\Forms\Blocks\RowBlock;
use FundKit\Forms\Blocks\ColumnsBlock;
use FundKit\Forms\Blocks\SectionBlock;
use FundKit\Forms\Blocks\StepBlock;
use FundKit\Forms\Blocks\StepsBlock;
use FundKit\Forms\Blocks\SubmitButtonBlock;
use FundKit\Forms\Blocks\DateBlock;
use FundKit\Forms\Blocks\TextInputBlock;
use FundKit\Forms\Blocks\NumberInputBlock;
use FundKit\Forms\Blocks\RecurringToggleBlock;
use FundKit\Forms\Blocks\DropdownBlock;
use FundKit\Forms\Blocks\RadioBlock;
use FundKit\Forms\Blocks\CheckboxBlock;
use FundKit\Forms\Blocks\MultiSelectBlock;
use FundKit\Forms\DefaultFormTypeHandler;
use FundKit\Forms\Form;
use FundKit\Forms\FormDonationStats;
use FundKit\Forms\FormReadinessService;
use FundKit\Forms\FormRepository;
use FundKit\Forms\FormService;
use FundKit\Forms\FormTypeRegistry;
use FundKit\Foundation\Config\SystemSetting;
use FundKit\Campaigns\Styling\CampaignStyleResolver;
use FundKit\Core\Commands\CoreCommandProvider;
use FundKit\Forms\Shortcode\DonationFormShortcode;
use FundKit\Foundation\Commands\CommandRegistry;
use FundKit\Foundation\Container\Container;
use FundKit\Foundation\Crypto\Crypto;
use FundKit\Foundation\Auth\Capabilities;
use FundKit\Foundation\Identity\IdentityHasher;
use FundKit\Foundation\License\LicenseNotice;
use FundKit\Foundation\License\LicenseService;
use FundKit\Foundation\Modules\FundKitModule;
use FundKit\Foundation\Modules\ModuleManager;
use FundKit\Foundation\Plugin;
use FundKit\Foundation\References\ReferenceGenerator;
use FundKit\Foundation\Time\Clock;
use FundKit\Foundation\Time\SystemClock;
use FundKit\Funds\Fund;
use FundKit\Funds\FundReassignmentJob;
use FundKit\Recurring\CampaignCancelRecurringJob;
use FundKit\Funds\FundRepository;
use FundKit\Funds\FundResolver;
use FundKit\Funds\FundService;
use FundKit\Gateways\GatewayManager;
use FundKit\Gateways\GatewayReconciler;
use FundKit\Gateways\Offline\OfflineGateway;
use FundKit\Gateways\Sandbox\SandboxGateway;
use FundKit\Gateways\Sandbox\SandboxRenewer;
use FundKit\Gateways\Stripe\StripeApi;
use FundKit\Gateways\PayPal\PayPalAccount;
use FundKit\Gateways\PayPal\PayPalApi;
use FundKit\Gateways\PayPal\PayPalGateway;
use FundKit\Gateways\PayPal\PayPalPlanRecorder;
use FundKit\Gateways\PayPal\PayPalPlans;
use FundKit\Gateways\Stripe\ApplePayDomain;
use FundKit\Gateways\Stripe\StripeAccount;
use FundKit\Gateways\Stripe\StripeWebhookNotice;
use FundKit\Gateways\Stripe\StripeGateway;
use FundKit\Gateways\TestMode;
use FundKit\Mail\Mailer;
use FundKit\Onboarding\Onboarding;
use FundKit\Onboarding\OnboardingPage;
use FundKit\Exports\DonorExporter;
use FundKit\Exports\RevenueExporter;
use FundKit\Reports\RevenueReportBuilder;
use FundKit\Receipts\PdfBuilder;
use FundKit\Reports\CampaignReportBuilder;
use FundKit\Reports\TaxStatementBuilder;
use FundKit\Receipts\Receipt;
use FundKit\Receipts\ReceiptIssuer;
use FundKit\Receipts\ReceiptRepository;
use FundKit\Receipts\Renderers\GenericReceiptRenderer;
use FundKit\Recurring\RecurringPlan;
use FundKit\Recurring\RecurringCanceller;
use FundKit\Recurring\RecurringPlanActions;
use FundKit\Recurring\RecurringPlanRepository;
use FundKit\Recurring\RecurringResumer;
use FundKit\Rest\Admin\ExportsController;
use FundKit\Rest\Admin\ToolsController;
use FundKit\Rest\Admin\NumberingController;
use FundKit\Rest\Admin\CampaignsController as AdminCampaignsController;
use FundKit\Rest\Admin\CommandsController;
use FundKit\Rest\Admin\DashboardController;
use FundKit\Rest\Admin\FundsController as AdminFundsController;
use FundKit\Rest\Admin\DonationsController as AdminDonationsController;
use FundKit\Rest\Admin\DonorsController as AdminDonorsController;
use FundKit\Rest\Admin\FormsController as AdminFormsController;
use FundKit\Rest\Admin\FxController;
use FundKit\Rest\Admin\OnboardingController;
use FundKit\Rest\Admin\ReadinessController;
use FundKit\Rest\Admin\ReportsController;
use FundKit\Rest\Admin\RecurringController;
use FundKit\Rest\Admin\RolesController;
use FundKit\Rest\Admin\SettingsController;
use FundKit\Rest\Admin\PayPalKeysController;
use FundKit\Rest\Admin\StripeKeysController;
use FundKit\Rest\Admin\UserPrefsController;
use FundKit\Rest\Portal\PortalController as PortalController;
use FundKit\Rest\DonationsController;
use FundKit\Rest\PayPalController;
use FundKit\Rest\ReceiptsController;
use FundKit\Rest\RestProvider;
use FundKit\Rest\WebhookController;
use FundKit\Settings\ReadinessService;
use FundKit\Settings\SettingsService;
use FundKit\Analytics\EventRetention;
use FundKit\Donors\DonorRetention;
use FundKit\Foundation\Maintenance\AbandonedPendingReaper;
use FundKit\Foundation\Maintenance\TransientGc;
use FundKit\Vendor\Queryable\QueryException;

/**
 * Always-on module: migrations, service bindings, admin/REST/asset wiring.
 *
 * @since 1.0.0
 */
final class CoreModule implements FundKitModule
{
    /** @since 1.0.0 */
    public function id(): string
    {
        return 'core';
    }

    /** @since 1.0.0 */
    public function name(): string
    {
        return __('Fundraising Toolkit Core', 'fundraising-toolkit');
    }

    /** @since 1.0.0 */
    public function version(): string
    {
        return FUNDKIT_VERSION;
    }

    /** @since 1.0.0 */
    public function requires(): array
    {
        return [];
    }

    /** @since 1.0.0 */
    public function isLicensed(): bool
    {
        return true;
    }

    /** @since 1.0.0 */
    public function tier(): string
    {
        return self::TIER_CORE;
    }

    /**
     * Core's data migrations, in the order they must run.
     *
     * Static and container-free because activation needs them too, and
     * register_activation_hook fires before plugins_loaded: resolving this from
     * the container there asks for a binding that boot() has not made yet, and
     * the plugin dies on activation with WordPress reporting only "triggered a
     * fatal error".
     *
     * @since 1.0.0
     */
    public static function upgradeRoutines(): array
    {
        return [
            new RestoreReceiptsRetainingMoney(),
        ];
    }

    /** @since 1.0.0 */
    public function boot(Container $c): void
    {
        // Cache-bust every FundKit build/ stylesheet by file mtime instead of
        // FUNDKIT_VERSION, so CSS changes show on a normal reload without a plugin
        // version bump (JS already busts via its content-hashed asset.php).
        add_filter('style_loader_src', static function ($src) {
            if (! is_string($src) || strpos($src, FUNDKIT_URL . 'build/') !== 0) {
                return $src;
            }
            $clean = strtok($src, '?');
            $file  = FUNDKIT_DIR . substr($clean, strlen(FUNDKIT_URL));
            return file_exists($file) ? $clean . '?ver=' . filemtime($file) : $src;
        }, 20);

        $c->bind(Clock::class, fn () => new SystemClock());
        $c->bind(Crypto::class, fn () => new Crypto());
        $c->bind(AsyncDispatcher::class, fn () => new AsyncDispatcher());
        $c->bind(IdentityHasher::class, fn (Container $c) => new IdentityHasher(
            $c->get(AsyncDispatcher::class)
        ));

        // Both read fundkit_system_settings the moment they are constructed, and
        // boot constructs them. plugins_loaded is far ahead of the wp_loaded
        // migration, so on an install whose tables are absent (a subsite of a
        // network activation, a half-restored database) that read throws and
        // takes down every front-end page and wp-admin with it. Build the
        // schema here instead and carry on with the request.
        try {
            $c->get(IdentityHasher::class);
            $c->get(Crypto::class);
        } catch (QueryException $e) {
            try {
                Plugin::migrateSchema();
                $c->get(IdentityHasher::class);
                $c->get(Crypto::class);
            } catch (\Throwable $t) {
                ErrorLog::toDebugLog('boot could not reach or create the schema: ' . $t->getMessage());
            }
        }

        $c->bind(PdfBuilder::class, fn () => new PdfBuilder());
        $c->bind(LicenseService::class, fn (Container $c) => new LicenseService($c->get(ModuleManager::class)));
        // Bound, not built at the one call site: the tools screen renders it and
        // the assistant's support commands answer from it.
        $c->bind(\FundKit\Admin\SystemReport::class, fn (Container $c) => new \FundKit\Admin\SystemReport(
            $c->get(ModuleManager::class),
            $c->get(GatewayManager::class),
        ));

        $c->bind(ReferenceGenerator::class, fn (Container $c) => new ReferenceGenerator(
            $c->get(Clock::class)
        ));

        $c->bind(EventRecorder::class, fn (Container $c) => new EventRecorder(
            $c->get(IdentityHasher::class),
            $c->get(Clock::class),
            $c->get(SettingsService::class)
        ));

        $c->bind(DonorRepository::class, fn () => new DonorRepository());
        $c->bind(DonationRepository::class, fn () => new DonationRepository());
        $c->bind(ReceiptRepository::class, fn () => new ReceiptRepository());
        $c->bind(FundRepository::class, fn () => new FundRepository());
        $c->bind(CampaignRepository::class, fn () => new CampaignRepository());
        $c->bind(FormRepository::class, fn () => new FormRepository());
        $c->bind(RecurringPlanRepository::class, fn () => new RecurringPlanRepository());

        $c->bind(DonorNoteRepository::class, fn (Container $c) => new DonorNoteRepository(
            $c->get(Crypto::class),
            $c->get(Clock::class)
        ));

        $c->bind(DonationNoteRepository::class, fn (Container $c) => new DonationNoteRepository(
            $c->get(Crypto::class),
            $c->get(Clock::class)
        ));

        (new DonorAggregateSyncer())->register();
        // Prunes our own expired rate-limit transients independently of WP core's wp_scheduled_delete.
        (new TransientGc($c->get(AsyncDispatcher::class)))->register();
        (new AbandonedPendingReaper($c->get(AsyncDispatcher::class), $c->get(Clock::class)))->register();

        // Purge expired magic-link tokens daily to prevent unbounded table growth.
        $async = $c->get(AsyncDispatcher::class);
        add_action('fundkit.cron.magic_link_gc', function () use ($c): void {
            $c->get(MagicLinkService::class)->purgeExpired();
            // An address nobody proved is not kept past its window. Same job,
            // because a pending row and its link expire together.
            $c->get(PendingSignupRepository::class)->purgeExpired();
        });
        add_action('init', fn () => $async->scheduleRecurring('fundkit.cron.magic_link_gc', 86400));

        // Daily FX snapshot; last-good value on failure.
        $c->bind(FxRates::class, fn () => new FxRates());
        (new FxRatesUpdater($c->get(AsyncDispatcher::class)))->register();

        // GDPR retention: donor PII wiped after inactivity where the org has
        // switched that on, events pruned by age.
        // DonorRetention bound further down once DonorService exists.
        (new EventRetention($c->get(AsyncDispatcher::class)))->register();
        $c->bind(DonorRetention::class, fn (Container $c) => new DonorRetention(
            $c->get(DonorService::class),
            $c->get(AsyncDispatcher::class),
        ));

        // Rehashes donor email hashes in batches when the pepper is regenerated.
        $c->bind( DonorEmailRehasher::class, fn (Container $c) => new DonorEmailRehasher(
            $c->get(IdentityHasher::class),
            $c->get(Crypto::class),
            $c->get(AsyncDispatcher::class),
        ));
        $c->get( DonorEmailRehasher::class)->register();


        $c->bind(FormService::class, fn (Container $c) => new FormService(
            $c->get(FormRepository::class),
            $c->get(CampaignRepository::class),
            $c->get(Clock::class)
        ));

        $c->bind(CampaignStyleResolver::class, fn () => new CampaignStyleResolver());

        $c->bind(CampaignService::class, fn (Container $c) => new CampaignService(
            $c->get(CampaignRepository::class),
            $c->get(FormService::class),
            $c->get(Clock::class)
        ));

        $c->bind(FundService::class, fn (Container $c) => new FundService(
            $c->get(FundRepository::class),
            $c->get(Clock::class),
            $c->get(AsyncDispatcher::class)
        ));

        $c->bind( FundResolver::class, fn (Container $c) => new FundResolver(
            $c->get(FundRepository::class)
        ));

        (new FundReassignmentJob($c->get(AsyncDispatcher::class), new AggregateSyncer()))->register();

        (new CampaignPermalinks())->register();

        (new CampaignChrome($c->get(CampaignRepository::class)))->register();

        (new CampaignPageTemplate())->register();
        (new PageStyle())->register();

        (new SocialMeta($c->get(CampaignRepository::class)))->register();

        // Keep campaign page visibility in sync with form status.
        add_action('fundkit.form.updated', static function ($form) use ($c) {
            $c->get(CampaignService::class)->onFormUpdated($form);
        }, 10, 1);

        // Drop linked campaign to draft when its WP page is trashed or deleted.
        add_action('wp_trash_post', static function ($postId) use ($c) {
            $c->get(CampaignService::class)->onPageDeleted((int) $postId);
        }, 10, 1);
        add_action('before_delete_post', static function ($postId) use ($c) {
            $c->get(CampaignService::class)->onPageDeleted((int) $postId);
        }, 10, 1);
        // Re-link a campaign to its page when the page is restored from trash.
        add_action('untrashed_post', static function ($postId) use ($c) {
            $c->get(CampaignService::class)->onPageRestored((int) $postId);
        }, 10, 1);

        // Publishing the campaign's page (the editor "Publish" button) also
        // publishes the campaign, so an admin doesn't end up with a live page
        // whose campaign, and gated blocks like the donation form, stay draft.
        add_action('transition_post_status', static function ($new, $old, $post) use ($c) {
            if (! $post instanceof \WP_Post) return;
            $c->get(CampaignService::class)->onPagePublished((string) $new, (string) $old, $post);
        }, 10, 3);

        $c->bind(DataExporter::class, fn (Container $c) => new DataExporter($c->get(Crypto::class)));

        $c->bind(CsvImporter::class, fn (Container $c) => new CsvImporter(
            $c->get(DonorService::class),
            $c->get(IdentityHasher::class)
        ));

        $c->bind(DataImporter::class, fn (Container $c) => new DataImporter(
            $c->get(Crypto::class),
            $c->get(IdentityHasher::class)
        ));

        $c->bind(DonorAvatarUploader::class, fn () => new DonorAvatarUploader());

        $c->bind(DonorAvatars::class, fn (Container $c) => new DonorAvatars(
            $c->get(Crypto::class),
            $c->get(SettingsService::class)
        ));

        $c->bind(CampaignMetricsService::class, fn (Container $c) => new CampaignMetricsService(
            $c->get(Clock::class),
            $c->get(DonationRepository::class),
            $c->get(DonorRepository::class)
        ));

        $c->bind(CampaignStatMetrics::class, fn (Container $c) => new CampaignStatMetrics(
            $c->get(DonationRepository::class),
            $c->get(Clock::class)
        ));

        $c->bind(Activator::class, fn (Container $c) => new Activator(
            $c->get(FundRepository::class),
            $c->get(Clock::class)
        ));

        $c->bind(ErasureRegistry::class, fn (Container $c) => new ErasureRegistry());

        // Core erases through the same registry add-ons use, so there is one
        // mechanism and one order rather than core's inline copy plus a hook
        // everyone else is expected to remember.
        add_filter('fundkit.donor.erasure_handlers', static function (array $handlers) use ($c): array {
            $handlers[] = new CoreDonorDataHandler();
            $handlers[] = new AnalyticsEventHandler();
            return $handlers;
        });

        $c->bind(DonorPurge::class, fn (Container $c) => new DonorPurge(
            $c->get(AsyncDispatcher::class),
            $c->get(Clock::class)
        ));
        $c->get(DonorPurge::class)->register();

        $c->bind(DonorService::class, fn (Container $c) => new DonorService(
            $c->get(DonorRepository::class),
            $c->get(IdentityHasher::class),
            $c->get(Crypto::class),
            $c->get(Clock::class),
            $c->get(ErasureRegistry::class),
            $c->get(DonorPurge::class)
        ));

        $c->get(DonorRetention::class)->register();

        $c->bind(DonorMetricsService::class, fn (Container $c) => new DonorMetricsService(
            $c->get(DonorRepository::class),
            $c->get(DonorService::class),
            $c->get(RecurringPlanRepository::class),
            $c->get(DonorNoteRepository::class),
            $c->get(MagicLinkService::class),
            $c->get(Clock::class),
            $c->get(GatewayManager::class),
            $c->get(DonorAvatars::class)
        ));

        $c->bind(MagicLinkService::class, fn (Container $c) => new MagicLinkService(
            $c->get(Clock::class)
        ));

        $c->bind(PendingSignupRepository::class, fn (Container $c) => new PendingSignupRepository(
            $c->get(Crypto::class),
            $c->get(IdentityHasher::class),
            $c->get(Clock::class),
        ));

        $c->bind(ConsentService::class, fn (Container $c) => new ConsentService(
            $c->get(IdentityHasher::class),
            $c->get(Clock::class)
        ));

        $c->bind(SignupRedemption::class, fn (Container $c) => new SignupRedemption(
            $c->get(MagicLinkService::class),
            $c->get(PendingSignupRepository::class),
            $c->get(DonorService::class),
        ));

        $c->bind(PortalSession::class, fn (Container $c) => new PortalSession(
            $c->get(MagicLinkService::class),
            $c->get(DonorRepository::class),
            $c->get(SignupRedemption::class),
            $c->get(PendingSignupRepository::class),
        ));

        $c->bind(AnnualStatementBuilder::class, fn (Container $c) => new AnnualStatementBuilder(
            $c->get(PdfBuilder::class)
        ));

        $c->bind(RevenueExporter::class, fn (Container $c) => new RevenueExporter(
            $c->get(DonationRepository::class),
        ));

        $c->bind(DonorExporter::class, fn (Container $c) => new DonorExporter(
            $c->get(DonorService::class),
        ));

        $c->bind(RevenueReportBuilder::class, fn (Container $c) => new RevenueReportBuilder(
            $c->get(PdfBuilder::class),
            $c->get(RevenueExporter::class),
        ));

        $c->bind(CampaignReportBuilder::class, fn (Container $c) => new CampaignReportBuilder(
            $c->get(PdfBuilder::class),
            $c->get(CampaignMetricsService::class),
        ));

        $c->bind(TaxStatementBuilder::class, fn (Container $c) => new TaxStatementBuilder(
            $c->get(PdfBuilder::class),
            $c->get(DonationRepository::class),
            $c->get(DonorService::class),
        ));

        $c->bind( SettingsService::class, fn (Container $c) => new SettingsService());
        $c->bind(Mailer::class, fn (Container $c) => new Mailer(
            $c->get( SettingsService::class),
        ));

        // Sends non-receipt donation emails (offline instructions, refund
        // notice, pending notice). Receipt emails are handled by ReceiptIssuer.
        $c->bind(DonationEmails::class, fn (Container $c) => new DonationEmails(
            $c->get(Mailer::class),
            $c->get(DonorRepository::class),
            $c->get(DonorService::class),
            $c->get(SettingsService::class),
            $c->get(CampaignRepository::class),
        ));
        $c->get(DonationEmails::class)->register();

        $c->bind(PortalController::class, fn (Container $c) => new PortalController(
            $c->get(PortalSession::class),
            $c->get(DonorRepository::class),
            $c->get(DonorService::class),
            $c->get(DonationRepository::class),
            $c->get(MagicLinkService::class),
            $c->get(IdentityHasher::class),
            $c->get(AnnualStatementBuilder::class),
            $c->get(ConsentService::class),
            $c->get(Mailer::class),
            $c->get(AsyncDispatcher::class),
            $c->get(DonorMetricsService::class),
            $c->get(RecurringPlanActions::class),
            $c->get(GatewayManager::class),
            $c->get(AntiSpamGuard::class),
            $c->get(PendingSignupRepository::class),
            $c->get(DonorAvatarUploader::class),
            $c->get(DonorAvatars::class),
        ));

        $c->bind(AggregateSyncer::class, fn () => new AggregateSyncer());

        $c->bind( FormTypeRegistry::class, function (): FormTypeRegistry {
            $r = new FormTypeRegistry();
            $r->register(new DefaultFormTypeHandler());
            do_action('fundkit.form_types.register', $r);
            return $r;
        });

        $c->bind( CampaignTypeRegistry::class, function (): CampaignTypeRegistry {
            $r = new CampaignTypeRegistry();
            $r->register(new DefaultCampaignTypeHandler());
            do_action('fundkit.campaign_types.register', $r);
            return $r;
        });

        $c->bind( TestMode::class, fn (Container $c) => new TestMode(
            $c->get(FormRepository::class)
        ));

        $c->bind(DonationService::class, fn (Container $c) => new DonationService(
            $c->get(DonationRepository::class),
            $c->get(DonorService::class),
            $c->get(ReferenceGenerator::class),
            $c->get(EventRecorder::class),
            $c->get(GatewayManager::class),
            $c->get(Clock::class),
            $c->get(AggregateSyncer::class),
            $c->get( FundResolver::class),
            $c->get(FxRates::class),
            $c->get( FormTypeRegistry::class),
            $c->get(Crypto::class),
            $c->get( TestMode::class)
        ));

        $c->bind(GatewayManager::class, fn () => new GatewayManager());
        $c->bind(StripeAccount::class, fn (Container $c) => new StripeAccount(
            $c->get(Crypto::class)
        ));
        $c->bind(StripeApi::class, fn (Container $c) => new StripeApi(
            $c->get(StripeAccount::class)
        ));
        $c->bind(ApplePayDomain::class, fn (Container $c) => new ApplePayDomain(
            $c->get(StripeApi::class),
            $c->get(StripeAccount::class)
        ));
        $c->bind(PayPalAccount::class, fn (Container $c) => new PayPalAccount(
            $c->get(Crypto::class)
        ));
        $c->bind(PayPalApi::class, fn (Container $c) => new PayPalApi(
            $c->get(PayPalAccount::class)
        ));
        $c->bind(PayPalPlanRecorder::class, fn (Container $c) => new PayPalPlanRecorder(
            $c->get(DonationRepository::class),
            $c->get(Clock::class),
        ));

        $c->bind(PayPalPlans::class, fn (Container $c) => new PayPalPlans(
            $c->get(PayPalApi::class),
            $c->get(PayPalAccount::class)
        ));

        $gateways = $c->get(GatewayManager::class);
        $gateways->register(new OfflineGateway($c->get(Clock::class)));

        $stripeApi     = $c->get(StripeApi::class);
        $stripeAccount = $c->get(StripeAccount::class);
        // Register Stripe whenever an account is connected (mode-independent,
        // same check the readiness UI uses). The boot context has no mode set,
        // so a mode-specific gate here only ever sees the test token and skips
        // a live-only connection. Per-charge calls still fail closed via
        // StripeApi::request()->isConfigured() for the active mode.
        if ($stripeAccount->isConnected()) {
            $gateways->register(new StripeGateway(
                $stripeApi,
                $c->get(DonationRepository::class),
                $c->get(DonationService::class),
                $c->get(StripeAccount::class),
                $c->get(DonorRepository::class),
                $c->get(DonorService::class),
                $c->get(Clock::class),
                $c->get(RecurringPlanRepository::class),
            ));
        }

        // Same rule as Stripe: register whenever either mode has credentials,
        // and let the per-charge calls fail closed for a mode that has none.
        $paypalAccount = $c->get(PayPalAccount::class);
        if ($paypalAccount->isConnected()) {
            $gateways->register(new PayPalGateway(
                $c->get(PayPalApi::class),
                $paypalAccount,
                $c->get(DonationRepository::class),
                $c->get(DonationService::class),
                $c->get(PayPalPlans::class),
                $c->get(RecurringPlanRepository::class),
                $c->get(Clock::class),
                $c->get(PayPalPlanRecorder::class),
            ));
        }

        // Reads PayPal for donations it may already hold money for. The
        // settling webhook is the only other way out of processing, so a
        // refused or lost delivery strands the money with nothing polling.
        $c->bind(GatewayReconciler::class, fn (Container $c) => new GatewayReconciler(
            $c->get(PayPalApi::class),
            $c->get(PayPalAccount::class),
            $c->get(DonationService::class),
            $c->get(Clock::class),
            $c->get(AsyncDispatcher::class),
        ));
        $c->get(GatewayReconciler::class)->register();

        // Sandbox gateway only available when org-wide test mode is on.
        $gwCfg = get_option('fundkit_gateway_config', []);
        if (is_array($gwCfg) && ! empty($gwCfg['test_mode'])) {
            $gateways->register(new SandboxGateway($c->get(Clock::class), $c->get(RecurringPlanRepository::class)));
        }

        do_action('fundkit.gateways.register', $gateways, $c);

        $c->bind( AntiSpamGuard::class, fn (Container $c) => new AntiSpamGuard(
            $c->get(IdentityHasher::class),
            $c->get( TestMode::class),
        ));

        $c->bind(DonationsController::class, fn (Container $c) => new DonationsController(
            $c->get(DonationService::class),
            $c->get(DonationRepository::class),
            $c->get(GatewayManager::class),
            $c->get( AntiSpamGuard::class),
            $c->get(ConsentService::class),
        ));

        $c->bind(WebhookController::class, fn (Container $c) => new WebhookController(
            $c->get(GatewayManager::class),
            $c->get(EventRecorder::class),
            $c->get(AntiSpamGuard::class)
        ));

        $c->bind(ReceiptsController::class, fn (Container $c) => new ReceiptsController(
            $c->get(ReceiptRepository::class),
            $c->get(DonationRepository::class),
            $c->get(DonorRepository::class),
            $c->get(DonorService::class),
            $c->get(MagicLinkService::class),
            $c->get(AntiSpamGuard::class)
        ));

        // Bound after ReceiptIssuer; order matters for eager route registration.
        $c->bind(GenericReceiptRenderer::class, fn (Container $c) => new GenericReceiptRenderer(
            $c->get(PdfBuilder::class)
        ));

        $c->bind(ReceiptIssuer::class, fn (Container $c) => new ReceiptIssuer(
            $c->get(DonationRepository::class),
            $c->get(DonorRepository::class),
            $c->get(DonorService::class),
            $c->get(ReceiptRepository::class),
            $c->get(ReferenceGenerator::class),
            $c->get(EventRecorder::class),
            $c->get(AsyncDispatcher::class),
            $c->get(MagicLinkService::class),
            $c->get(Clock::class),
            $c->get(Mailer::class),
            $c->get( SettingsService::class),
            $c->get( Crypto::class)
        ));

        // Add-ons can register additional renderers via the same filter.
        $genericRenderer = $c->get(GenericReceiptRenderer::class);
        add_filter('fundkit.receipt.renderers', function (array $renderers) use ($genericRenderer): array {
            $renderers[] = $genericRenderer;
            return $renderers;
        });

        $c->get(ReceiptIssuer::class)->register();

        $c->bind(AdminDonationsController::class, fn (Container $c) => new AdminDonationsController(
            $c->get(DonationRepository::class),
            $c->get(DonorRepository::class),
            $c->get(DonorService::class),
            $c->get(DonationService::class),
            $c->get(ReceiptRepository::class),
            $c->get(ReceiptIssuer::class),
            $c->get(DonationNoteRepository::class),
            $c->get( GenericReceiptRenderer::class),
            $c->get(GatewayManager::class),
        ));

        $c->bind(AdminDonorsController::class, fn (Container $c) => new AdminDonorsController(
            $c->get(DonorRepository::class),
            $c->get(DonorService::class),
            $c->get(DonorMetricsService::class),
            $c->get(DonorNoteRepository::class),
            $c->get(DonationService::class),
            $c->get(DonorAvatars::class)
        ));

        $c->bind( FormReadinessService::class, fn (Container $c) => new FormReadinessService(
            $c->get( SettingsService::class),
            $c->get( GatewayManager::class),
            $c->get(StripeAccount::class),
            $c->get( TestMode::class),
        ));

        $c->bind(AdminFormsController::class, fn (Container $c) => new AdminFormsController(
            $c->get(FormRepository::class),
            $c->get(FormService::class),
            $c->get(CampaignRepository::class),
            $c->get(GatewayManager::class),
            $c->get(CampaignStyleResolver::class),
            $c->get( FormReadinessService::class),
            $c->get(FundRepository::class)
        ));

        $c->bind(RecurringCanceller::class, fn (Container $c) => new RecurringCanceller(
            $c->get(RecurringPlanRepository::class),
            $c->get(DonationService::class),
            $c->get(GatewayManager::class)
        ));

        // The one way a plan changes. The portal, the admin screen and the
        // command registry all go through it, so the three cannot drift apart
        // and every change leaves an event behind.
        $c->bind(RecurringPlanActions::class, fn (Container $c) => new RecurringPlanActions(
            $c->get(GatewayManager::class),
            $c->get(RecurringCanceller::class),
            $c->get(EventRecorder::class)
        ));

        // Lifts a pause when its window closes. PayPal cannot
        // schedule their own resume, so without this a donor's "skip next
        // payment" would stop the subscription for good.
        $c->bind(RecurringResumer::class, fn (Container $c) => new RecurringResumer(
            $c->get(GatewayManager::class),
            $c->get(Clock::class),
            $c->get(AsyncDispatcher::class)
        ));
        $c->get(RecurringResumer::class)->register();

        // Registered whatever test mode says: this sweep is also what ends a
        // sandbox rehearsal once test mode goes off, and the gateway that
        // could cancel those plans deregisters itself at that point.
        $c->bind(SandboxRenewer::class, fn (Container $c) => new SandboxRenewer(
            $c->get(RecurringPlanRepository::class),
            $c->get(DonationService::class),
            $c->get(TestMode::class),
            $c->get(Clock::class),
            $c->get(AsyncDispatcher::class)
        ));
        $c->get(SandboxRenewer::class)->register();

        // Data migrations. dbDelta reconciles shape and nothing else, so
        // anything that has to touch contents lives here.
        $c->bind(UpgradeRunner::class, fn (Container $c) => new UpgradeRunner(self::upgradeRoutines()));

        $c->bind(UpgradeJob::class, fn (Container $c) => new UpgradeJob(
            $c->get(AsyncDispatcher::class),
            $c->get(UpgradeRunner::class),
        ));
        $c->get(UpgradeJob::class)->register();

        if (is_admin()) {
            (new UpgradeNotice($c->get(UpgradeRunner::class)))->register();
        }

        $c->bind(CampaignCancelRecurringJob::class, fn (Container $c) => new CampaignCancelRecurringJob(
            $c->get(AsyncDispatcher::class),
            $c->get(RecurringCanceller::class),
        ));
        $c->get(CampaignCancelRecurringJob::class)->register();

        $c->bind(AdminCampaignsController::class, fn (Container $c) => new AdminCampaignsController(
            $c->get(CampaignRepository::class),
            $c->get(CampaignService::class),
            $c->get(CampaignMetricsService::class),
            $c->get(RecurringPlanRepository::class),
            $c->get(CampaignCancelRecurringJob::class)
        ));

        $c->bind(AdminFundsController::class, fn (Container $c) => new AdminFundsController(
            $c->get(FundRepository::class),
            $c->get(FundService::class)
        ));

        // Bound after domain services and before RestProvider so the command endpoint shares this instance.
        $c->bind(CommandRegistry::class, fn (Container $c) => new CommandRegistry(
            $c->get(EventRecorder::class)
        ));
        // Built on init, not here: every command summary and field label goes
        // through __(), and translating before init asks WordPress for a
        // catalogue in the site locale rather than the reader's, on top of the
        // _doing_it_wrong it logs for the domain on every request.
        //
        // Priority 4 keeps core ahead of the fundkit.commands.register broadcast
        // Plugin::boot fires at 5, which is where add-on packs land.
        add_action('init', static function () use ($c): void {
            // init can fire more than once, and the registry refuses a name it
            // already holds, which is what catches two packs claiming one
            // command. Core's own pack must not trip that guard against itself.
            static $registered = false;
            if ($registered) {
                return;
            }
            $registered = true;

            (new CoreCommandProvider())->register($c->get(CommandRegistry::class), $c);
        }, 4);

        (new RestProvider(
            $c->get(DonationsController::class),
            $c->get(WebhookController::class),
            $c->get(ReceiptsController::class),
            $c->get(AdminDonationsController::class),
            $c->get(AdminDonorsController::class),
            $c->get(AdminFormsController::class),
            $c->get(AdminCampaignsController::class),
            $c->get(AdminFundsController::class),
            new UserPrefsController(),
            new DashboardController(
                new DashboardMetricsService(
                    $c->get( Clock::class),
                    $c->get(DonationRepository::class),
                    $c->get(RecurringPlanRepository::class),
                )
            ),
            new SettingsController(new SettingsService(), $c->get(DonorRetention::class)),
            $c->get(PortalController::class),
            new RecurringController(
                $c->get(RecurringPlanActions::class),
                $c->get(RecurringPlanRepository::class),
                $c->get(DonorRepository::class),
                $c->get(DonorService::class),
                $c->get(CampaignRepository::class),
                $c->get(GatewayManager::class),
            ),
            new RolesController(),
            new ToolsController(
                $c->get( AggregateSyncer::class),
                $c->get( Mailer::class),
                new FxBackfill($c->get( FxRates::class)),
                $c->get(UpgradeRunner::class),
                $c->get(DataExporter::class),
                $c->get(DataImporter::class),
                $c->get(CsvImporter::class),
                new \FundKit\Foundation\Maintenance\TestDataPurger($c->get(DonorService::class)),
                $c->get(\FundKit\Admin\SystemReport::class),
            ),
            new ExportsController(
                $c->get(DonorExporter::class),
                $c->get(RevenueExporter::class),
                $c->get(RevenueReportBuilder::class),
                $c->get(DonationRepository::class),
            ),
            new OnboardingController(
                $c->get( SettingsService::class),
            ),
            new StripeKeysController(
                $c->get(StripeApi::class),
                $c->get(StripeAccount::class),
                $c->get(ApplePayDomain::class),
            ),
            new PayPalKeysController(
                $c->get(PayPalApi::class),
                $c->get(PayPalAccount::class),
            ),
            new PayPalController(
                $c->get(DonationRepository::class),
                $c->get(DonationService::class),
                $c->get(GatewayManager::class),
                $c->get(PayPalApi::class),
                $c->get(PayPalAccount::class),
                $c->get(PayPalPlanRecorder::class),
                $c->get(AntiSpamGuard::class),
            ),
            new FxController(
                $c->get(FxRates::class),
                new FxRatesUpdater($c->get(AsyncDispatcher::class)),
                new SettingsService(),
                $c->get(GatewayManager::class)
            ),
            new CommandsController($c->get(CommandRegistry::class)),
            new NumberingController($c->get(ReferenceGenerator::class)),
            new ReportsController(
                $c->get(CampaignRepository::class),
                $c->get(CampaignReportBuilder::class),
                $c->get(DonorRepository::class),
                $c->get(TaxStatementBuilder::class),
            ),
            new ReadinessController(new ReadinessService(
                $c->get(SettingsService::class),
                $c->get(FormReadinessService::class),
                $c->get(StripeAccount::class),
                $c->get(StripeApi::class),
                $c->get(ApplePayDomain::class),
                $c->get(PayPalAccount::class),
                $c->get(GatewayManager::class),
                new PortalPage(),
                $c->get(LicenseService::class),
            ))
        ))->register();

        $c->get(PortalController::class)->registerHooks();

        $c->bind(BlockRegistry::class, fn () => new BlockRegistry());
        $blocks = $c->get(BlockRegistry::class);
        $blocks->add(new HeadingBlock());
        $blocks->add(new ParagraphBlock());
        $blocks->add(new DividerBlock());
        $blocks->add(new RowBlock());
        $blocks->add(new ColumnsBlock());
        $blocks->add(new SectionBlock());
        $blocks->add(new StepsBlock());
        $blocks->add(new StepBlock());
        $blocks->add(new GoalBlock($c->get(CampaignRepository::class), $c->get(FormRepository::class)));
        $blocks->add(new DonationAmountBlock());
        $blocks->add(new PaymentGatewaysBlock($c->get(GatewayManager::class)));
        $blocks->add(new NameBlock());
        $blocks->add(new EmailBlock());
        $blocks->add(new CountryBlock());
        $blocks->add(new PhoneBlock());
        $blocks->add(new CommentBlock());
        $blocks->add(new AnonymousToggleBlock());
        $blocks->add(new CoverFeesBlock());
        $blocks->add(new CurrencySwitcherBlock());
        $blocks->add(new FundPickerBlock());
        $blocks->add(new AddressBlock());
        $blocks->add(new ConsentBlock($c->get(ConsentService::class)));
        $blocks->add(new TermsBlock());
        $blocks->add(new DonationSummaryBlock());
        $blocks->add(new PrivacyNoticeBlock());
        $blocks->add(new SubmitButtonBlock());
        $blocks->add(new DateBlock());
        $blocks->add(new TextInputBlock());
        $blocks->add(new NumberInputBlock());
        $blocks->add(new RecurringToggleBlock());
        $blocks->add(new HiddenBlock());
        $blocks->add(new HtmlBlock());
        $blocks->add(new DropdownBlock());
        $blocks->add(new RadioBlock());
        $blocks->add(new CheckboxBlock());
        $blocks->add(new MultiSelectBlock());

        add_filter(
            'fundkit.settings.groups',
            [$c->get(GatewayManager::class), 'declareSettings']
        );

        $blocks->add(new CampaignImageBlock($c->get(CampaignRepository::class)));
        $blocks->add(new CampaignProgressBlock($c->get(CampaignRepository::class)));
        $blocks->add(new CampaignStatBlock(
            $c->get(CampaignRepository::class),
            $c->get(CampaignStatMetrics::class),
        ));
        $blocks->add(new CampaignGridBlock($c->get(CampaignRepository::class)));
        $blocks->add(new DonateButtonBlock(
            $c->get(CampaignRepository::class),
            $c->get(FormRepository::class),
        ));
        // Bound rather than built here: an add-on rendering a donation form in
        // the editor needs this to build the preview document, and it holds
        // per-request state that a second instance would not share.
        $c->bind(DonationFormShortcode::class, fn (Container $c) => new DonationFormShortcode(
            $c->get(FormRepository::class),
            $c->get(CampaignStyleResolver::class),
            $c->get(CampaignRepository::class),
            $c->get(AntiSpamGuard::class),
            $c->get(GatewayManager::class),
            $c->get(TestMode::class),
        ));
        $formShortcode = $c->get(DonationFormShortcode::class);
        $blocks->add(new DonationFormBlock(
            $c->get(CampaignRepository::class),
            $c->get(FormRepository::class),
            $formShortcode,
        ));
        $blocks->add(new TopDonorsBlock(
            $c->get(CampaignRepository::class),
            $c->get(DonationRepository::class),
            $c->get(DonorAvatars::class),
        ));
        $blocks->add(new RecentDonationsBlock(
            $c->get(CampaignRepository::class),
            $c->get(DonationRepository::class),
            $c->get(DonorAvatars::class),
        ));
        $blocks->add(new SupporterWallBlock(
            $c->get(CampaignRepository::class),
            $c->get(DonorAvatars::class),
        ));

        // Broadcast on init, not here: add-on modules boot after core, so a
        // handler they attach during their own boot would miss a broadcast
        // fired inside this method and their block would never register.
        add_action('init', static function () use ($blocks): void {
            do_action('fundkit.blocks.register_server', $blocks);
            $blocks->register();
        });

        // WordPress's own Tools, Export and Erase Personal Data. They answered
        // nothing for donors until this, which is the screen a site owner is
        // told to use when a request arrives.
        (new \FundKit\Donors\Privacy\WordPressPrivacy(
            $c->get(DonorRepository::class),
            $c->get(DonorService::class),
            $c->get(IdentityHasher::class),
        ))->register();

        (new CampaignBlockEditorIntegration())->register();

        // Publishes every registered command as a WordPress ability, which is
        // what an MCP server reads. Add-on packs are included because the
        // bridge reads the registry when the abilities hook fires, after the
        // command broadcast on init:5.
        (new \FundKit\Foundation\Commands\AbilitiesBridge(
            $c->get(CommandRegistry::class)
        ))->register();
        $campaignBindings = new CampaignBindings($c->get(CampaignRepository::class));
        $campaignBindings->register();

        // The editor resolves bindings on the client, so it needs the same
        // values handed to it rather than computed a second time in JS.
        (new CampaignBindingPreviewController(
            $c->get(CampaignRepository::class),
            $campaignBindings,
        ))->register();

        $formShortcode->register();

        (new PortalShortcode($c->get(AntiSpamGuard::class)))->register();
        // Serves Apple's domain association file on the front end, so it must
        // register outside any is_admin() gate.
        $c->get(ApplePayDomain::class)->register();

        add_action('fundkit.settings.updated', static function (string $group, array $next): void {
            if ($group === 'roles') {
                Capabilities::applyMapping(is_array($next['mapping'] ?? null) ? $next['mapping'] : []);
            }
            if ($group === 'currency-locale') {
                // Campaigns report in the single org currency; keep their stored
                // currency in lockstep when the org default currency changes.
                $cur = strtoupper((string) ($next['default_currency'] ?? ''));
                if ($cur !== '') {
                    Campaign::query()->where('currency', $cur, '!=')->update(['currency' => $cur]);
                }
            }
        }, 10, 2);

        // Activation applies Capabilities::currentMapping(), which reads the
        // raw fundkit_roles option: until something writes it, no role holds a
        // single fundkit_* capability while the Roles screen shows the defaults as
        // granted, and an administrator is refused refunds and receipt resends
        // by command dispatch on a screen that offers no way to grant them.
        // Writing the option once puts the two in agreement, through the
        // handler above.
        add_action('admin_init', static function () use ($c): void {
            if (get_option('fundkit_roles') !== false) return;
            $c->get(SettingsService::class)->update('roles', []);
        });


        // Outside the is_admin guard on purpose: the admin bar renders on the
        // front end too, and a campaign page you are looking at is exactly
        // where "this form takes no real money" needs saying.
        (new TestModeBadge())->register();

        if (is_admin()) {
            (new ManagedPageStates())->register();
            (new DeactivationDialog())->register();
            (new AdminMenu())->register();
            (new CampaignsPage())->register();
            (new DonationsPage())->register();
            (new SubscriptionsPage())->register();
            (new DonorsPage())->register();
            (new FormsPage())->register();
            (new FundsPage())->register();
            (new ToolsPage())->register();
            (new SettingsPage())->register();
            (new OnboardingPage())->register();
            (new Onboarding())->register();
            (new AdminGlobals($c->get(LicenseService::class)))->register();
            (new AdminFooter())->register();
            (new LicenseNotice($c->get(LicenseService::class)))->register();

            // Persist admin notice until the lost-key flag is cleared.
            add_action('admin_notices', static function (): void {
                if (! current_user_can('manage_options')) return;
                $lostAt = Crypto::keyLostAt();
                if ($lostAt === null) return;
                echo '<div class="notice fundkit-admin-notice" role="alert" style="'
                    . 'border:1px solid #e5e7eb;border-left:3px solid #b42318;border-radius:8px;'
                    . 'background:#fff7f7;color:#b42318;padding:11px 14px;'
                    . 'font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Oxygen,Ubuntu,sans-serif;'
                    . 'font-size:13px;line-height:1.45;">'
                    . '<strong>Fundraising Toolkit:</strong> '
                    . esc_html(sprintf(
                        /* translators: %s: timestamp the key loss was detected */
                        __('Encryption key missing since %s. Donor PII written before this point cannot be decrypted. Restore fundkit_system_settings from a backup, or accept that historical PII is gone. New donations are encrypting against a freshly generated key.', 'fundraising-toolkit'),
                        $lostAt
                    ))
                    . '</div>';
            });

            // Stripe connected but no webhook signing secret: verifyWebhookSignature
            // fails closed, so every webhook is silently rejected and recurring
            // renewals and async confirmations never process.
            (new StripeWebhookNotice(
                $c->get(StripeAccount::class),
                $c->get(StripeApi::class),
            ))->register();
        }

        // Every per-address limit becomes a limit for the whole site at once
        // when something in front terminates the connection, and nothing else
        // would say so: the limits do not fail loudly, they refuse a donor.
        (new ProxyNotice())->register();

    }

    /** @since 1.0.0 */
    public function migrations(): array
    {
        return [
            SystemSetting::class,
            Donor::class,
            Consent::class,
            DonorNote::class,
            MagicLinkToken::class,
            PendingSignup::class,
            Campaign::class,
            Fund::class,
            Donation::class,
            DonationNote::class,
            Refund::class,
            RecurringPlan::class,
            Receipt::class,
            Form::class,
            FormDonationStats::class,
            Event::class,
        ];
    }
}
