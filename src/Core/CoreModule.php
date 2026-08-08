<?php

declare(strict_types=1);

namespace Dono\Core;

use Dono\Admin\AdminGlobals;
use Dono\Admin\AdminMenu;
use Dono\Admin\Pages\CampaignsPage;
use Dono\Admin\Pages\DonationsPage;
use Dono\Admin\Pages\SubscriptionsPage;
use Dono\Admin\Pages\DonorsPage;
use Dono\Admin\Pages\FormsPage;
use Dono\Admin\Pages\FundsPage;
use Dono\Admin\Pages\ToolsPage;
use Dono\Admin\DeactivationDialog;
use Dono\Admin\ManagedPageStates;
use Dono\Admin\TestModeBadge;
use Dono\Admin\Pages\SettingsPage;
use Dono\Analytics\Event;
use Dono\Analytics\EventRecorder;
use Dono\Async\AsyncDispatcher;
use Dono\Campaigns\CampaignPermalinks;
use Dono\Campaigns\CampaignTypeRegistry;
use Dono\Campaigns\DefaultCampaignTypeHandler;
use Dono\Currency\FxBackfill;
use Dono\Currency\FxRates;
use Dono\Currency\FxRatesUpdater;
use Dono\Campaigns\Campaign;
use Dono\Campaigns\CampaignChrome;
use Dono\Campaigns\CampaignPageTemplate;
use Dono\Campaigns\Styling\PageStyle;
use Dono\Campaigns\CampaignMetricsService;
use Dono\Campaigns\CampaignStatMetrics;
use Dono\Campaigns\CampaignRepository;
use Dono\Campaigns\CampaignService;
use Dono\Campaigns\SocialMeta;
use Dono\Dashboard\DashboardMetricsService;
use Dono\Donations\AggregateSyncer;
use Dono\Donations\AntiSpamGuard;
use Dono\Donations\Donation;
use Dono\Donations\DonationEmails;
use Dono\Donations\DonationNote;
use Dono\Donations\DonationNoteRepository;
use Dono\Donations\DonationRepository;
use Dono\Donations\DonationService;
use Dono\Donations\Refund;
use Dono\Donors\Consent;
use Dono\Donors\ConsentService;
use Dono\Donors\Donor;
use Dono\Donors\DonorAggregateSyncer;
use Dono\Donors\DonorEmailRehasher;
use Dono\Donors\DonorMetricsService;
use Dono\Donors\DonorNote;
use Dono\Donors\DonorNoteRepository;
use Dono\Donors\DonorPurge;
use Dono\Donors\DonorAvatarUploader;
use Dono\Donors\DonorAvatars;
use Dono\Donors\DonorRepository;
use Dono\Donors\DonorService;
use Dono\Donors\Erasure\AnalyticsEventHandler;
use Dono\Donors\Erasure\ClearHashesOnAlreadyErasedConsents;
use Dono\Foundation\Upgrade\UpgradeRunner;
use Dono\Foundation\Upgrade\UpgradeJob;
use Dono\Foundation\Upgrade\UpgradeNotice;
use Dono\Donors\Erasure\CoreDonorDataHandler;
use Dono\Donors\Erasure\ErasureRegistry;
use Dono\Donors\Erasure\WebhookLogHandler;
use Dono\Donors\MagicLinkService;
use Dono\Donors\MagicLinkToken;
use Dono\Donors\PendingSignup;
use Dono\Donors\PendingSignupRepository;
use Dono\Donors\SignupRedemption;
use Dono\Donors\Portal\AnnualStatementBuilder;
use Dono\Donors\Portal\PortalPage;
use Dono\Donors\Portal\PortalSession;
use Dono\Donors\Portal\PortalShortcode;
use Dono\Campaigns\Blocks\BlockEditorIntegration as CampaignBlockEditorIntegration;
use Dono\Campaigns\Blocks\CampaignBindingPreviewController;
use Dono\Campaigns\Blocks\CampaignBindings;
use Dono\Campaigns\Blocks\CampaignGridBlock;
use Dono\Campaigns\Blocks\CampaignImageBlock;
use Dono\Campaigns\Blocks\CampaignProgressBlock;
use Dono\Campaigns\Blocks\CampaignStatBlock;
use Dono\Campaigns\Blocks\DonateButtonBlock;
use Dono\Campaigns\Blocks\DonationFormBlock;
use Dono\Campaigns\Blocks\RecentDonationsBlock;
use Dono\Campaigns\Blocks\SupporterWallBlock;
use Dono\Campaigns\Blocks\TopDonorsBlock;
use Dono\Forms\Blocks\AddressBlock;
use Dono\Forms\Blocks\AnonymousToggleBlock;
use Dono\Forms\Blocks\BlockRegistry;
use Dono\Forms\Blocks\CommentBlock;
use Dono\Forms\Blocks\ConsentBlock;
use Dono\Forms\Blocks\DonationSummaryBlock;
use Dono\Forms\Blocks\TermsBlock;
use Dono\Forms\Blocks\CountryBlock;
use Dono\Forms\Blocks\CoverFeesBlock;
use Dono\Forms\Blocks\CurrencySwitcherBlock;
use Dono\Forms\Blocks\DividerBlock;
use Dono\Forms\Blocks\DonationAmountBlock;
use Dono\Forms\Blocks\PaymentGatewaysBlock;
use Dono\Forms\Blocks\EmailBlock;
use Dono\Forms\Blocks\FundPickerBlock;
use Dono\Forms\Blocks\GoalBlock;
use Dono\Forms\Blocks\HeadingBlock;
use Dono\Forms\Blocks\NameBlock;
use Dono\Forms\Blocks\ParagraphBlock;
use Dono\Forms\Blocks\PhoneBlock;
use Dono\Forms\Blocks\HiddenBlock;
use Dono\Forms\Blocks\HtmlBlock;
use Dono\Forms\Blocks\PrivacyNoticeBlock;
use Dono\Forms\Blocks\RowBlock;
use Dono\Forms\Blocks\ColumnsBlock;
use Dono\Forms\Blocks\SectionBlock;
use Dono\Forms\Blocks\StepBlock;
use Dono\Forms\Blocks\StepsBlock;
use Dono\Forms\Blocks\SubmitButtonBlock;
use Dono\Forms\Blocks\DateBlock;
use Dono\Forms\Blocks\TextInputBlock;
use Dono\Forms\Blocks\NumberInputBlock;
use Dono\Forms\Blocks\RecurringToggleBlock;
use Dono\Forms\Blocks\DropdownBlock;
use Dono\Forms\Blocks\RadioBlock;
use Dono\Forms\Blocks\CheckboxBlock;
use Dono\Forms\Blocks\MultiSelectBlock;
use Dono\Forms\DefaultFormTypeHandler;
use Dono\Forms\Form;
use Dono\Forms\FormDonationStats;
use Dono\Forms\FormReadinessService;
use Dono\Forms\FormRepository;
use Dono\Forms\FormService;
use Dono\Forms\FormTypeRegistry;
use Dono\Foundation\Config\SystemSetting;
use Dono\Campaigns\Styling\CampaignStyleResolver;
use Dono\Core\Commands\CoreCommandProvider;
use Dono\Forms\Shortcode\DonationFormShortcode;
use Dono\Foundation\Commands\CommandRegistry;
use Dono\Foundation\Container\Container;
use Dono\Foundation\Crypto\Crypto;
use Dono\Foundation\Auth\Capabilities;
use Dono\Foundation\Identity\IdentityHasher;
use Dono\Foundation\License\LicenseNotice;
use Dono\Foundation\License\LicenseService;
use Dono\Foundation\Modules\DonoModule;
use Dono\Foundation\Modules\ModuleManager;
use Dono\Foundation\References\ReferenceGenerator;
use Dono\Foundation\Time\Clock;
use Dono\Foundation\Time\SystemClock;
use Dono\Funds\Fund;
use Dono\Funds\FundReassignmentJob;
use Dono\Recurring\CampaignCancelRecurringJob;
use Dono\Funds\FundRepository;
use Dono\Funds\FundResolver;
use Dono\Funds\FundService;
use Dono\Gateways\GatewayManager;
use Dono\Gateways\Offline\OfflineGateway;
use Dono\Gateways\Sandbox\SandboxGateway;
use Dono\Gateways\Stripe\StripeApi;
use Dono\Gateways\PayPal\PayPalAccount;
use Dono\Gateways\PayPal\PayPalApi;
use Dono\Gateways\PayPal\PayPalGateway;
use Dono\Gateways\PayPal\PayPalPlans;
use Dono\Gateways\Stripe\ApplePayDomain;
use Dono\Gateways\Stripe\StripeAccount;
use Dono\Gateways\Stripe\StripeGateway;
use Dono\Gateways\TestMode;
use Dono\Mail\Mailer;
use Dono\Onboarding\Onboarding;
use Dono\Onboarding\OnboardingPage;
use Dono\Exports\DonorExporter;
use Dono\Exports\RevenueExporter;
use Dono\Reports\RevenueReportBuilder;
use Dono\Receipts\PdfBuilder;
use Dono\Reports\CampaignReportBuilder;
use Dono\Reports\TaxStatementBuilder;
use Dono\Receipts\Receipt;
use Dono\Receipts\ReceiptIssuer;
use Dono\Receipts\ReceiptRepository;
use Dono\Receipts\Renderers\GenericReceiptRenderer;
use Dono\Recurring\RecurringPlan;
use Dono\Recurring\RecurringCanceller;
use Dono\Recurring\RecurringPlanActions;
use Dono\Recurring\RecurringPlanRepository;
use Dono\Recurring\RecurringResumer;
use Dono\Rest\Admin\ExportsController;
use Dono\Rest\Admin\ToolsController;
use Dono\Rest\Admin\NumberingController;
use Dono\Rest\Admin\CampaignsController as AdminCampaignsController;
use Dono\Rest\Admin\CommandsController;
use Dono\Rest\Admin\DashboardController;
use Dono\Rest\Admin\FundsController as AdminFundsController;
use Dono\Rest\Admin\DonationsController as AdminDonationsController;
use Dono\Rest\Admin\DonorsController as AdminDonorsController;
use Dono\Rest\Admin\FormsController as AdminFormsController;
use Dono\Rest\Admin\FxController;
use Dono\Rest\Admin\LicenseController as AdminLicenseController;
use Dono\Rest\Admin\OnboardingController;
use Dono\Rest\Admin\ReadinessController;
use Dono\Rest\Admin\ReportsController;
use Dono\Rest\Admin\RecurringController;
use Dono\Rest\Admin\RolesController;
use Dono\Rest\Admin\SettingsController;
use Dono\Rest\Admin\PayPalKeysController;
use Dono\Rest\Admin\StripeKeysController;
use Dono\Rest\Admin\UserPrefsController;
use Dono\Rest\Portal\PortalController as PortalController;
use Dono\Rest\DonationsController;
use Dono\Rest\PayPalController;
use Dono\Rest\ReceiptsController;
use Dono\Rest\RestProvider;
use Dono\Rest\WebhookController;
use Dono\Settings\ReadinessService;
use Dono\Settings\SettingsService;
use Dono\Webhooks\WebhookLog;
use Dono\Webhooks\WebhookLogRetention;
use Dono\Analytics\EventRetention;
use Dono\Donors\DonorRetention;
use Dono\Foundation\Maintenance\TransientGc;

/**
 * Always-on module: migrations, service bindings, admin/REST/asset wiring.
 *
 * @version 1.0.0
 */
final class CoreModule implements DonoModule
{
    public function id(): string
    {
        return 'core';
    }

    public function name(): string
    {
        return __('Dono Core', 'dono');
    }

    public function version(): string
    {
        return DONO_VERSION;
    }

    public function requires(): array
    {
        return [];
    }

    public function isLicensed(): bool
    {
        return true;
    }

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
     */
    public static function upgradeRoutines(): array
    {
        return [
            new ClearHashesOnAlreadyErasedConsents(),
        ];
    }

    public function boot(Container $c): void
    {
        // Cache-bust every Dono build/ stylesheet by file mtime instead of
        // DONO_VERSION, so CSS changes show on a normal reload without a plugin
        // version bump (JS already busts via its content-hashed asset.php).
        add_filter('style_loader_src', static function ($src) {
            if (! is_string($src) || strpos($src, DONO_URL . 'build/') !== 0) {
                return $src;
            }
            $clean = strtok($src, '?');
            $file  = DONO_DIR . substr($clean, strlen(DONO_URL));
            return file_exists($file) ? $clean . '?ver=' . filemtime($file) : $src;
        }, 20);

        $c->bind(Clock::class, fn () => new SystemClock());
        $c->bind(Crypto::class, fn () => new Crypto());
        $c->bind(AsyncDispatcher::class, fn () => new AsyncDispatcher());
        $c->bind(IdentityHasher::class, fn (Container $c) => new IdentityHasher(
            $c->get(AsyncDispatcher::class)
        ));
        $c->bind(PdfBuilder::class, fn () => new PdfBuilder());
        $c->bind(LicenseService::class, fn (Container $c) => new LicenseService($c->get(ModuleManager::class)));

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
        (new WebhookLogRetention($c->get(AsyncDispatcher::class)))->register();
        // Prunes our own expired rate-limit transients independently of WP core's wp_scheduled_delete.
        (new TransientGc($c->get(AsyncDispatcher::class)))->register();

        // Purge expired magic-link tokens daily to prevent unbounded table growth.
        $async = $c->get(AsyncDispatcher::class);
        add_action('dono.cron.magic_link_gc', function () use ($c): void {
            $c->get(MagicLinkService::class)->purgeExpired();
            // An address nobody proved is not kept past its window. Same job,
            // because a pending row and its link expire together.
            $c->get(PendingSignupRepository::class)->purgeExpired();
        });
        add_action('init', fn () => $async->scheduleRecurring('dono.cron.magic_link_gc', 86400));

        // Daily FX snapshot; last-good value on failure.
        $c->bind(FxRates::class, fn () => new FxRates());
        (new FxRatesUpdater($c->get(AsyncDispatcher::class)))->register();

        // GDPR retention: donor PII wiped after inactivity, events pruned by age.
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
        add_action('dono.form.updated', static function ($form) use ($c) {
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
        add_filter('dono.donor.erasure_handlers', static function (array $handlers) use ($c): array {
            $handlers[] = new CoreDonorDataHandler();
            $handlers[] = new AnalyticsEventHandler();
            $handlers[] = new WebhookLogHandler();
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
            $c->get(GatewayManager::class)
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
            $c->get(ReceiptRepository::class),
            $c->get(MagicLinkService::class),
            $c->get(IdentityHasher::class),
            $c->get(AnnualStatementBuilder::class),
            $c->get(ConsentService::class),
            $c->get(Mailer::class),
            $c->get(AsyncDispatcher::class),
            $c->get(DonationService::class),
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
            do_action('dono.form_types.register', $r);
            return $r;
        });

        $c->bind( CampaignTypeRegistry::class, function (): CampaignTypeRegistry {
            $r = new CampaignTypeRegistry();
            $r->register(new DefaultCampaignTypeHandler());
            do_action('dono.campaign_types.register', $r);
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
        // so the old isConfigured() gate only ever saw the test token and would
        // skip a live-only connection. Per-charge calls still fail closed via
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
            ));
        }


        // Sandbox gateway only available when org-wide test mode is on.
        $gwCfg = get_option('dono_gateway_config', []);
        if (is_array($gwCfg) && ! empty($gwCfg['test_mode'])) {
            $gateways->register(new SandboxGateway($c->get(Clock::class)));
        }

        do_action('dono.gateways.register', $gateways, $c);

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
            $c->get(SettingsService::class),
        ));

        $c->bind(WebhookController::class, fn (Container $c) => new WebhookController(
            $c->get(GatewayManager::class),
            $c->get(Clock::class)
        ));

        $c->bind(ReceiptsController::class, fn (Container $c) => new ReceiptsController(
            $c->get(ReceiptRepository::class),
            $c->get(DonationRepository::class),
            $c->get(DonorRepository::class),
            $c->get(DonorService::class),
            $c->get(MagicLinkService::class)
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
        add_filter('dono.receipt.renderers', function (array $renderers) use ($genericRenderer): array {
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
            $c->get(DonationService::class)
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
        // again and every change leaves an event behind.
        $c->bind(RecurringPlanActions::class, fn (Container $c) => new RecurringPlanActions(
            $c->get(GatewayManager::class),
            $c->get(RecurringCanceller::class),
            $c->get(EventRecorder::class)
        ));

        // Lifts a pause when its window closes. PayPal cannot
        // schedule their own resume, so without this a donor's "skip next
        // payment" stopped the subscription for good.
        $c->bind(RecurringResumer::class, fn (Container $c) => new RecurringResumer(
            $c->get(GatewayManager::class),
            $c->get(Clock::class),
            $c->get(AsyncDispatcher::class)
        ));
        $c->get(RecurringResumer::class)->register();

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
            $c->get(FundRepository::class),
            $c->get(RecurringPlanRepository::class),
            $c->get(RecurringCanceller::class),
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
        (new CoreCommandProvider())->register($c->get(CommandRegistry::class), $c);
        // The dono.commands.register broadcast fires from Plugin::boot AFTER
        // every module has booted, so an add-on's boot-time add_action handler
        // is honored. Firing it here (during core's own boot) would run before
        // add-on modules exist, silently dropping their command packs.

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
            new SettingsController(new SettingsService()),
            new AdminLicenseController($c->get(LicenseService::class)),
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
                $c->get(RecurringPlanRepository::class),
                $c->get(Clock::class),
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
        $blocks->add(new ConsentBlock());
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
            'dono.settings.groups',
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
        $formShortcode = new DonationFormShortcode(
            $c->get(FormRepository::class),
            $c->get(CampaignStyleResolver::class),
            $c->get(CampaignRepository::class),
            $c->get(AntiSpamGuard::class),
            $c->get(GatewayManager::class),
            $c->get(TestMode::class),
        );
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
            $c->get(DonationRepository::class),
            $c->get(DonorAvatars::class),
        ));

        // Broadcast on init, not here: add-on modules boot after core, so a
        // handler they attach during their own boot would miss a broadcast
        // fired inside this method and their block would never register.
        add_action('init', static function () use ($blocks): void {
            do_action('dono.blocks.register_server', $blocks);
            $blocks->register();
        });

        (new CampaignBlockEditorIntegration())->register();
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

        add_action('dono.settings.updated', static function (string $group, array $next): void {
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
            (new SettingsPage($c))->register();
            (new OnboardingPage())->register();
            (new Onboarding())->register();
            (new AdminGlobals($c->get(LicenseService::class)))->register();
            (new LicenseNotice($c->get(LicenseService::class)))->register();

            // Persist admin notice until the lost-key flag is cleared.
            add_action('admin_notices', static function (): void {
                if (! current_user_can('manage_options')) return;
                $lostAt = Crypto::keyLostAt();
                if ($lostAt === null) return;
                echo '<div class="notice dono-admin-notice" role="alert" style="'
                    . 'border:1px solid #e5e7eb;border-left:3px solid #b42318;border-radius:8px;'
                    . 'background:#fff7f7;color:#b42318;padding:11px 14px;'
                    . 'font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Oxygen,Ubuntu,sans-serif;'
                    . 'font-size:13px;line-height:1.45;">'
                    . '<strong>Dono:</strong> '
                    . esc_html(sprintf(
                        /* translators: %s: timestamp the key loss was detected */
                        __('Encryption key missing since %s. Donor PII written before this point cannot be decrypted. Restore dono_system_settings from a backup, or accept that historical PII is gone. New donations are encrypting against a freshly generated key.', 'dono'),
                        $lostAt
                    ))
                    . '</div>';
            });

            // Stripe connected but no webhook signing secret: verifyWebhookSignature
            // fails closed, so every webhook is silently rejected and recurring
            // renewals and async confirmations never process.
            add_action('admin_notices', static function () use ($c): void {
                if (! current_user_can('manage_options')) return;
                if (! $c->get(StripeAccount::class)->isConnected()) return;
                if ($c->get(StripeApi::class)->hasWebhookSecret()) return;
                echo '<div class="notice dono-admin-notice" role="alert" style="'
                    . 'border:1px solid #e5e7eb;border-left:3px solid #b54708;border-radius:8px;'
                    . 'background:#fffaf5;color:#b54708;padding:11px 14px;'
                    . 'font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Oxygen,Ubuntu,sans-serif;'
                    . 'font-size:13px;line-height:1.45;">'
                    . '<strong>Dono:</strong> '
                    . esc_html__('Stripe is connected but its webhook signing secret is missing. Recurring renewals, payment confirmations, and account updates will not process until you add it under Dono, Settings, Payment gateways.', 'dono')
                    . '</div>';
            });
        }
    }

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
            WebhookLog::class,
        ];
    }
}
