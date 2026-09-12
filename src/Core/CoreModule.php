<?php

declare(strict_types=1);

namespace Gratora\Core;

use Gratora\Admin\AdminFooter;
use Gratora\Admin\AdminGlobals;
use Gratora\Admin\AdminMenu;
use Gratora\Admin\DeactivationDialog;
use Gratora\Admin\ManagedPageStates;
use Gratora\Admin\Pages\CampaignsPage;
use Gratora\Admin\Pages\DonationsPage;
use Gratora\Admin\Pages\DonorsPage;
use Gratora\Admin\Pages\FormsPage;
use Gratora\Admin\Pages\FundsPage;
use Gratora\Admin\Pages\SettingsPage;
use Gratora\Admin\Pages\SubscriptionsPage;
use Gratora\Admin\Pages\ToolsPage;
use Gratora\Admin\ProxyNotice;
use Gratora\Admin\TestModeBadge;
use Gratora\Analytics\ErrorLog;
use Gratora\Analytics\Event;
use Gratora\Analytics\EventRecorder;
use Gratora\Analytics\EventRetention;
use Gratora\Async\AsyncDispatcher;
use Gratora\Campaigns\Blocks\BlockEditorIntegration as CampaignBlockEditorIntegration;
use Gratora\Campaigns\Blocks\CampaignBindingPreviewController;
use Gratora\Campaigns\Blocks\CampaignBindings;
use Gratora\Campaigns\Blocks\CampaignGridBlock;
use Gratora\Campaigns\Blocks\CampaignImageBlock;
use Gratora\Campaigns\Blocks\CampaignProgressBlock;
use Gratora\Campaigns\Blocks\CampaignStatBlock;
use Gratora\Campaigns\Blocks\DonateButtonBlock;
use Gratora\Campaigns\Blocks\DonationFormBlock;
use Gratora\Campaigns\Blocks\RecentDonationsBlock;
use Gratora\Campaigns\Blocks\SupporterWallBlock;
use Gratora\Campaigns\Blocks\TopDonorsBlock;
use Gratora\Campaigns\Campaign;
use Gratora\Campaigns\CampaignChrome;
use Gratora\Campaigns\CampaignMetricsService;
use Gratora\Campaigns\CampaignPageTemplate;
use Gratora\Campaigns\CampaignPermalinks;
use Gratora\Campaigns\CampaignRepository;
use Gratora\Campaigns\CampaignService;
use Gratora\Campaigns\CampaignStatMetrics;
use Gratora\Campaigns\CampaignTypeRegistry;
use Gratora\Campaigns\DefaultCampaignTypeHandler;
use Gratora\Campaigns\SocialMeta;
use Gratora\Campaigns\Styling\CampaignStyleResolver;
use Gratora\Campaigns\Styling\CampaignStyleVars;
use Gratora\Campaigns\Styling\PageStyle;
use Gratora\Core\Commands\CoreCommandProvider;
use Gratora\Currency\FxBackfill;
use Gratora\Currency\FxRates;
use Gratora\Currency\FxRatesUpdater;
use Gratora\Dashboard\DashboardMetricsService;
use Gratora\Donations\AggregateSyncer;
use Gratora\Donations\AntiSpamGuard;
use Gratora\Donations\Donation;
use Gratora\Donations\DonationDeleter;
use Gratora\Donations\DonationEmails;
use Gratora\Donations\DonationNote;
use Gratora\Donations\DonationNoteRepository;
use Gratora\Donations\DonationRepository;
use Gratora\Donations\DonationService;
use Gratora\Donations\DonationTrasher;
use Gratora\Donations\Refund;
use Gratora\Donors\Consent;
use Gratora\Donors\ConsentService;
use Gratora\Donors\Donor;
use Gratora\Donors\DonorAggregateSyncer;
use Gratora\Donors\DonorAvatars;
use Gratora\Donors\DonorAvatarUploader;
use Gratora\Donors\DonorEmailRehasher;
use Gratora\Donors\DonorMetricsService;
use Gratora\Donors\DonorNote;
use Gratora\Donors\DonorNoteRepository;
use Gratora\Donors\DonorPurge;
use Gratora\Donors\DonorRepository;
use Gratora\Donors\DonorRetention;
use Gratora\Donors\DonorService;
use Gratora\Donors\Erasure\AnalyticsEventHandler;
use Gratora\Donors\Erasure\CoreDonorDataHandler;
use Gratora\Donors\Erasure\ErasureRegistry;
use Gratora\Donors\MagicLinkService;
use Gratora\Donors\MagicLinkToken;
use Gratora\Donors\PendingSignup;
use Gratora\Donors\PendingSignupRepository;
use Gratora\Donors\Portal\AnnualStatementBuilder;
use Gratora\Donors\Portal\PortalPage;
use Gratora\Donors\Portal\PortalSession;
use Gratora\Donors\Portal\PortalShortcode;
use Gratora\Donors\Privacy\WordPressPrivacy;
use Gratora\Donors\SignupRedemption;
use Gratora\Exports\DonorExporter;
use Gratora\Exports\RevenueExporter;
use Gratora\Forms\Blocks\AddressBlock;
use Gratora\Forms\Blocks\AnonymousToggleBlock;
use Gratora\Forms\Blocks\BlockRegistry;
use Gratora\Forms\Blocks\CheckboxBlock;
use Gratora\Forms\Blocks\ColumnsBlock;
use Gratora\Forms\Blocks\CommentBlock;
use Gratora\Forms\Blocks\ConsentBlock;
use Gratora\Forms\Blocks\CountryBlock;
use Gratora\Forms\Blocks\CoverFeesBlock;
use Gratora\Forms\Blocks\CurrencySwitcherBlock;
use Gratora\Forms\Blocks\DateBlock;
use Gratora\Forms\Blocks\DividerBlock;
use Gratora\Forms\Blocks\DonationAmountBlock;
use Gratora\Forms\Blocks\DonationSummaryBlock;
use Gratora\Forms\Blocks\DropdownBlock;
use Gratora\Forms\Blocks\EmailBlock;
use Gratora\Forms\Blocks\FundPickerBlock;
use Gratora\Forms\Blocks\GoalBlock;
use Gratora\Forms\Blocks\HeadingBlock;
use Gratora\Forms\Blocks\HiddenBlock;
use Gratora\Forms\Blocks\HtmlBlock;
use Gratora\Forms\Blocks\MultiSelectBlock;
use Gratora\Forms\Blocks\NameBlock;
use Gratora\Forms\Blocks\NumberInputBlock;
use Gratora\Forms\Blocks\ParagraphBlock;
use Gratora\Forms\Blocks\PaymentGatewaysBlock;
use Gratora\Forms\Blocks\PhoneBlock;
use Gratora\Forms\Blocks\PrivacyNoticeBlock;
use Gratora\Forms\Blocks\RadioBlock;
use Gratora\Forms\Blocks\RecurringToggleBlock;
use Gratora\Forms\Blocks\RowBlock;
use Gratora\Forms\Blocks\SectionBlock;
use Gratora\Forms\Blocks\StepBlock;
use Gratora\Forms\Blocks\StepsBlock;
use Gratora\Forms\Blocks\SubmitButtonBlock;
use Gratora\Forms\Blocks\TermsBlock;
use Gratora\Forms\Blocks\TextInputBlock;
use Gratora\Forms\DefaultFormTypeHandler;
use Gratora\Forms\Form;
use Gratora\Forms\FormDonationStats;
use Gratora\Forms\FormReadinessService;
use Gratora\Forms\FormRepository;
use Gratora\Forms\FormService;
use Gratora\Forms\FormTypeRegistry;
use Gratora\Forms\Shortcode\DonationFormShortcode;
use Gratora\Foundation\Auth\Capabilities;
use Gratora\Foundation\Commands\AbilitiesBridge;
use Gratora\Foundation\Commands\CommandRegistry;
use Gratora\Foundation\Config\SystemSetting;
use Gratora\Foundation\Container\Container;
use Gratora\Foundation\Crypto\Crypto;
use Gratora\Foundation\Identity\IdentityHasher;
use Gratora\Foundation\License\LicenseNotice;
use Gratora\Foundation\License\LicenseService;
use Gratora\Foundation\Maintenance\AbandonedPendingReaper;
use Gratora\Foundation\Maintenance\TransientGc;
use Gratora\Foundation\Modules\GratoraModule;
use Gratora\Foundation\Modules\ModuleManager;
use Gratora\Foundation\Plugin;
use Gratora\Foundation\References\ReferenceGenerator;
use Gratora\Foundation\Time\Clock;
use Gratora\Foundation\Time\SystemClock;
use Gratora\Foundation\Transfer\CsvImporter;
use Gratora\Foundation\Transfer\DataExporter;
use Gratora\Foundation\Transfer\DataImporter;
use Gratora\Foundation\Upgrade\OpenTheDefaultFund;
use Gratora\Foundation\Upgrade\RestoreReceiptsRetainingMoney;
use Gratora\Foundation\Upgrade\UnautoloadGatewayConfig;
use Gratora\Foundation\Upgrade\UnpinSiteIdentity;
use Gratora\Foundation\Upgrade\UpgradeJob;
use Gratora\Foundation\Upgrade\UpgradeNotice;
use Gratora\Foundation\Upgrade\UpgradeRunner;
use Gratora\Funds\Fund;
use Gratora\Funds\FundReassignmentJob;
use Gratora\Funds\FundRepository;
use Gratora\Funds\FundResolver;
use Gratora\Funds\FundService;
use Gratora\Gateways\GatewayManager;
use Gratora\Gateways\GatewayReconciler;
use Gratora\Gateways\Offline\OfflineGateway;
use Gratora\Gateways\PayPal\PayPalAccount;
use Gratora\Gateways\PayPal\PayPalApi;
use Gratora\Gateways\PayPal\PayPalGateway;
use Gratora\Gateways\PayPal\PayPalPlanRecorder;
use Gratora\Gateways\PayPal\PayPalPlans;
use Gratora\Gateways\Sandbox\SandboxGateway;
use Gratora\Gateways\Sandbox\SandboxRenewer;
use Gratora\Gateways\Stripe\ApplePayDomain;
use Gratora\Gateways\Stripe\StripeAccount;
use Gratora\Gateways\Stripe\StripeApi;
use Gratora\Gateways\Stripe\StripeGateway;
use Gratora\Gateways\Stripe\StripeWebhookNotice;
use Gratora\Gateways\TestMode;
use Gratora\Mail\Mailer;
use Gratora\Onboarding\Onboarding;
use Gratora\Onboarding\OnboardingPage;
use Gratora\Receipts\PdfBuilder;
use Gratora\Receipts\Receipt;
use Gratora\Receipts\ReceiptIssuer;
use Gratora\Receipts\ReceiptRepository;
use Gratora\Receipts\Renderers\GenericReceiptRenderer;
use Gratora\Recurring\CampaignCancelRecurringJob;
use Gratora\Recurring\RecurringCanceller;
use Gratora\Recurring\RecurringPlan;
use Gratora\Recurring\RecurringPlanActions;
use Gratora\Recurring\RecurringPlanRepository;
use Gratora\Recurring\RecurringResumer;
use Gratora\Reports\CampaignReportBuilder;
use Gratora\Reports\RevenueReportBuilder;
use Gratora\Reports\TaxStatementBuilder;
use Gratora\Rest\Admin\CampaignsController as AdminCampaignsController;
use Gratora\Rest\Admin\CommandsController;
use Gratora\Rest\Admin\DashboardController;
use Gratora\Rest\Admin\DonationsController as AdminDonationsController;
use Gratora\Rest\Admin\DonorsController as AdminDonorsController;
use Gratora\Rest\Admin\ExportsController;
use Gratora\Rest\Admin\FormsController as AdminFormsController;
use Gratora\Rest\Admin\FundsController as AdminFundsController;
use Gratora\Rest\Admin\FxController;
use Gratora\Rest\Admin\NumberingController;
use Gratora\Rest\Admin\OnboardingController;
use Gratora\Rest\Admin\PayPalKeysController;
use Gratora\Rest\Admin\ReadinessController;
use Gratora\Rest\Admin\RecurringController;
use Gratora\Rest\Admin\ReportsController;
use Gratora\Rest\Admin\RolesController;
use Gratora\Rest\Admin\SettingsController;
use Gratora\Rest\Admin\StripeKeysController;
use Gratora\Rest\Admin\ToolsController;
use Gratora\Rest\Admin\UserPrefsController;
use Gratora\Rest\DonationsController;
use Gratora\Rest\PayPalController;
use Gratora\Rest\Portal\PortalController as PortalController;
use Gratora\Rest\ReceiptsController;
use Gratora\Rest\RestProvider;
use Gratora\Rest\WebhookController;
use Gratora\Settings\ReadinessService;
use Gratora\Settings\SettingsService;
use Gratora\Vendor\Queryable\QueryException;

/**
 * Always-on module: migrations, service bindings, admin/REST/asset wiring.
 *
 * @since 1.0.0
 */
final class CoreModule implements GratoraModule
{
    /** @since 1.0.0 */
    public function id(): string
    {
        return 'core';
    }

    /** @since 1.0.0 */
    public function name(): string
    {
        return __('Gratora Core', 'gratora-donation-platform');
    }

    /** @since 1.0.0 */
    public function version(): string
    {
        return GRATORA_VERSION;
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
            new OpenTheDefaultFund(),
            new UnpinSiteIdentity(),
            new UnautoloadGatewayConfig(),
        ];
    }

    /** @since 1.0.0 */
    public function boot(Container $c): void
    {
        // Cache-bust every Gratora build/ stylesheet by file mtime instead of
        // GRATORA_VERSION, so CSS changes show on a normal reload without a plugin
        // version bump (JS already busts via its content-hashed asset.php).
        add_filter('style_loader_src', static function ($src) {
            if (! is_string($src) || strpos($src, GRATORA_URL . 'build/') !== 0) {
                return $src;
            }
            $clean = strtok($src, '?');
            $file  = GRATORA_DIR . substr($clean, strlen(GRATORA_URL));
            return file_exists($file) ? $clean . '?ver=' . filemtime($file) : $src;
        }, 20);

        $c->bind(Clock::class, fn () => new SystemClock());
        $c->bind(Crypto::class, fn () => new Crypto());
        $c->bind(AsyncDispatcher::class, fn () => new AsyncDispatcher());
        $c->bind(IdentityHasher::class, fn (Container $c) => new IdentityHasher(
            $c->get(AsyncDispatcher::class)
        ));

        // Both read gratora_system_settings the moment they are constructed, and
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
        $c->bind(\Gratora\Admin\SystemReport::class, fn (Container $c) => new \Gratora\Admin\SystemReport(
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
        add_action('gratora.cron.magic_link_gc', function () use ($c, $async): void {
            $c->get(MagicLinkService::class)->purgeExpired();
            // An address nobody proved is not kept past its window. Same job,
            // because a pending row and its link expire together.
            $limit = 500;
            $done  = $c->get(PendingSignupRepository::class)->purgeExpired($limit);

            // A full pass means there is more behind it. Draining it here would
            // die on the time limit and leave a larger set for tomorrow.
            if ($done >= $limit) {
                $async->enqueue('gratora.cron.magic_link_gc');
            }
        });
        add_action('init', fn () => $async->scheduleRecurring('gratora.cron.magic_link_gc', 86400));

        // Daily FX snapshot; last-good value on failure.
        $c->bind(FxRates::class, fn () => new FxRates());
        (new FxRatesUpdater($c->get(AsyncDispatcher::class)))->register();
        (new \Gratora\Currency\OutstandingRebase())->register();

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
        CampaignStyleVars::register();

        (new SocialMeta($c->get(CampaignRepository::class)))->register();

        // Keep campaign page visibility in sync with form status.
        add_action('gratora.form.updated', static function ($form) use ($c) {
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
        add_filter('gratora.donor.erasure_handlers', static function (array $handlers) use ($c): array {
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
            $c->get( Crypto::class),
        ));

        $c->bind(AggregateSyncer::class, fn () => new AggregateSyncer());

        $c->bind( FormTypeRegistry::class, function (): FormTypeRegistry {
            $r = new FormTypeRegistry();
            $r->register(new DefaultFormTypeHandler());
            do_action('gratora.form_types.register', $r);
            return $r;
        });

        $c->bind( CampaignTypeRegistry::class, function (): CampaignTypeRegistry {
            $r = new CampaignTypeRegistry();
            $r->register(new DefaultCampaignTypeHandler());
            do_action('gratora.campaign_types.register', $r);
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
        $gwCfg = get_option('gratora_gateway_config', []);
        if (is_array($gwCfg) && ! empty($gwCfg['test_mode'])) {
            $gateways->register(new SandboxGateway($c->get(Clock::class), $c->get(RecurringPlanRepository::class)));
        }

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
            $c->get(TestMode::class),
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
            $c->get(AntiSpamGuard::class),
            $c->get(ReceiptIssuer::class)
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
        add_filter('gratora.receipt.renderers', function (array $renderers) use ($genericRenderer): array {
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
            $c->get(DonationTrasher::class),
            $c->get(DonationDeleter::class),
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
            $c->get(ConsentService::class),
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

        $c->bind(DonationTrasher::class, fn (Container $c) => new DonationTrasher(
            $c->get(GatewayManager::class),
            $c->get(Clock::class)
        ));

        $c->bind(DonationDeleter::class, fn (Container $c) => new DonationDeleter(
            $c->get(DonationTrasher::class),
            $c->get(Clock::class),
            $c->get(AggregateSyncer::class)
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
        // Priority 4 keeps core ahead of the gratora.commands.register broadcast
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
                new \Gratora\Foundation\Maintenance\TestDataPurger($c->get(DonorService::class)),
                $c->get(\Gratora\Admin\SystemReport::class),
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
            'gratora.settings.groups',
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
            do_action('gratora.blocks.register_server', $blocks);
            $blocks->register();
        });

        // WordPress's own Tools, Export and Erase Personal Data. They answered
        // nothing for donors until this, which is the screen a site owner is
        // told to use when a request arrives.
        (new WordPressPrivacy(
            $c->get(DonorRepository::class),
            $c->get(DonorService::class),
            $c->get(IdentityHasher::class),
            $c->get( DonorMetricsService::class),
            $c->get( ConsentService::class),
        ))->register();

        (new CampaignBlockEditorIntegration())->register();

        // Publishes every registered command as a WordPress ability, which is
        // what an MCP server reads. Add-on packs are included because the
        // bridge reads the registry when the abilities hook fires, after the
        // command broadcast on init:5.
        (new AbilitiesBridge(
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

        add_action('gratora.settings.updated', static function (string $group, array $next, array $prev = []): void {
            if ($group === 'roles') {
                Capabilities::applyMapping(is_array($next['mapping'] ?? null) ? $next['mapping'] : []);
            }
            if ($group === 'currency-locale') {
                // Campaigns report in the single org currency; keep their stored
                // currency in lockstep when the org default currency changes.
                // On the change, not on every save of the group: the panel PUTs
                // the whole group, so a thousands separator was relabelling
                // rows a restore had just landed in their own currency.
                $cur = strtoupper((string) ($next['default_currency'] ?? ''));
                $was = strtoupper((string) ($prev['default_currency'] ?? ''));
                if ($cur !== '' && $cur !== $was) {
                    Campaign::query()->where('currency', $cur, '!=')->update(['currency' => $cur]);
                }
            }
        }, 10, 3);

        // Seed actual role capabilities to match displayed defaults, even if an add-on already
        // created the option.
        add_action('admin_init', static function () use ($c): void {
            $stored  = get_option('gratora_roles', []);
            $mapping = is_array($stored) && is_array($stored['mapping'] ?? null) ? $stored['mapping'] : [];
            if (array_key_exists('administrator', $mapping)) return;
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
                echo '<div class="notice gratora-admin-notice" role="alert" style="'
                    . 'border:1px solid #e5e7eb;border-left:3px solid #b42318;border-radius:8px;'
                    . 'background:#fff7f7;color:#b42318;padding:11px 14px;'
                    . 'font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Oxygen,Ubuntu,sans-serif;'
                    . 'font-size:13px;line-height:1.45;">'
                    . '<strong>Gratora:</strong> '
                    . esc_html(sprintf(
                        /* translators: %s: timestamp the key loss was detected */
                        __('Encryption key missing since %s. Donor PII written before this point cannot be decrypted. Restore gratora_system_settings from a backup, or accept that historical PII is gone. New donations are encrypting against a freshly generated key.', 'gratora-donation-platform'),
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
