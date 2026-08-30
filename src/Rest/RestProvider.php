<?php

declare(strict_types=1);

namespace FundKit\Rest;

use FundKit\Foundation\Hooks\HookProvider;
use FundKit\Rest\Admin\ExportsController as AdminExportsController;
use FundKit\Rest\Admin\ToolsController as AdminToolsController;
use FundKit\Rest\Admin\CampaignsController as AdminCampaignsController;
use FundKit\Rest\Admin\CommandsController as AdminCommandsController;
use FundKit\Rest\Admin\DashboardController as AdminDashboardController;
use FundKit\Rest\Admin\DonationsController as AdminDonationsController;
use FundKit\Rest\Admin\DonorsController as AdminDonorsController;
use FundKit\Rest\Admin\FormsController as AdminFormsController;
use FundKit\Rest\Admin\FundsController as AdminFundsController;
use FundKit\Rest\Admin\FxController;
use FundKit\Rest\Admin\NumberingController as AdminNumberingController;
use FundKit\Rest\Admin\OnboardingController as AdminOnboardingController;
use FundKit\Rest\Admin\RecurringController as AdminRecurringController;
use FundKit\Rest\Admin\ReportsController as AdminReportsController;
use FundKit\Rest\Admin\RolesController as AdminRolesController;
use FundKit\Rest\Admin\SettingsController as AdminSettingsController;
use FundKit\Rest\Admin\PayPalKeysController;
use FundKit\Rest\Admin\ReadinessController as AdminReadinessController;
use FundKit\Rest\Admin\StripeKeysController;
use FundKit\Rest\Admin\UserPrefsController as AdminUserPrefsController;
use FundKit\Rest\Portal\PortalController;

/**
 * Registers all REST route groups on rest_api_init.
 *
 * @since 1.0.0
 */
final class RestProvider extends HookProvider
{
    /** @since 1.0.0 */
    public function __construct(
        private DonationsController $donations,
        private WebhookController $webhooks,
        private ReceiptsController $receipts,
        private AdminDonationsController $adminDonations,
        private AdminDonorsController $adminDonors,
        private AdminFormsController $adminForms,
        private AdminCampaignsController $adminCampaigns,
        private AdminFundsController $adminFunds,
        private AdminUserPrefsController $adminUserPrefs,
        private AdminDashboardController $adminDashboard,
        private AdminSettingsController $adminSettings,
        private PortalController $portal,
        private AdminRecurringController $adminRecurring,
        private AdminRolesController $adminRoles,
        private AdminToolsController $adminTools,
        private AdminExportsController $adminExports,
        private AdminOnboardingController $adminOnboarding,
        private StripeKeysController $stripeKeys,
        private PayPalKeysController $payPalKeys,
        private PayPalController $payPal,
        private FxController $fx,
        private AdminCommandsController $commands,
        private AdminNumberingController $numbering,
        private AdminReportsController $reports,
        private AdminReadinessController $readiness,
    ) {
    }

    /** @since 1.0.0 */
    protected function actions(): array
    {
        return ['rest_api_init' => 'registerRoutes'];
    }

    /** @since 1.0.0 */
    public function registerRoutes(): void
    {
        $this->donations->registerRoutes();
        $this->webhooks->registerRoutes();
        $this->receipts->registerRoutes();
        $this->adminDonations->registerRoutes();
        $this->adminDonors->registerRoutes();
        $this->adminForms->registerRoutes();
        $this->adminCampaigns->registerRoutes();
        $this->adminFunds->registerRoutes();
        $this->adminUserPrefs->registerRoutes();
        $this->adminDashboard->registerRoutes();
        $this->adminSettings->registerRoutes();
        $this->portal->registerRoutes();
        $this->adminRecurring->registerRoutes();
        $this->adminRoles->registerRoutes();
        $this->adminTools->registerRoutes();
        $this->adminExports->registerRoutes();
        $this->adminOnboarding->registerRoutes();
        $this->stripeKeys->registerRoutes();
        $this->payPalKeys->registerRoutes();
        $this->payPal->registerRoutes();
        $this->fx->registerRoutes();
        $this->commands->registerRoutes();
        $this->numbering->registerRoutes();
        $this->reports->registerRoutes();
        $this->readiness->registerRoutes();

        $registry = new ControllerRegistry();
        do_action('fundkit.rest.register', $registry);
        foreach ($registry->all() as $controller) {
            $controller->registerRoutes();
        }
    }
}
