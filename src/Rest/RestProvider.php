<?php

declare(strict_types=1);

namespace GiveFlow\Rest;

use GiveFlow\Foundation\Hooks\HookProvider;
use GiveFlow\Rest\Admin\ExportsController as AdminExportsController;
use GiveFlow\Rest\Admin\ToolsController as AdminToolsController;
use GiveFlow\Rest\Admin\CampaignsController as AdminCampaignsController;
use GiveFlow\Rest\Admin\CommandsController as AdminCommandsController;
use GiveFlow\Rest\Admin\DashboardController as AdminDashboardController;
use GiveFlow\Rest\Admin\DonationsController as AdminDonationsController;
use GiveFlow\Rest\Admin\DonorsController as AdminDonorsController;
use GiveFlow\Rest\Admin\FormsController as AdminFormsController;
use GiveFlow\Rest\Admin\FundsController as AdminFundsController;
use GiveFlow\Rest\Admin\FxController;
use GiveFlow\Rest\Admin\NumberingController as AdminNumberingController;
use GiveFlow\Rest\Admin\OnboardingController as AdminOnboardingController;
use GiveFlow\Rest\Admin\RecurringController as AdminRecurringController;
use GiveFlow\Rest\Admin\ReportsController as AdminReportsController;
use GiveFlow\Rest\Admin\RolesController as AdminRolesController;
use GiveFlow\Rest\Admin\SettingsController as AdminSettingsController;
use GiveFlow\Rest\Admin\PayPalKeysController;
use GiveFlow\Rest\Admin\ReadinessController as AdminReadinessController;
use GiveFlow\Rest\Admin\StripeKeysController;
use GiveFlow\Rest\Admin\UserPrefsController as AdminUserPrefsController;
use GiveFlow\Rest\Portal\PortalController;

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
        do_action('giveflow.rest.register', $registry);
        foreach ($registry->all() as $controller) {
            $controller->registerRoutes();
        }
    }
}
