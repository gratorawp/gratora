<?php

declare(strict_types=1);

namespace Gratora\Rest;

use Gratora\Foundation\Hooks\HookProvider;
use Gratora\Rest\Admin\CampaignsController as AdminCampaignsController;
use Gratora\Rest\Admin\CommandsController as AdminCommandsController;
use Gratora\Rest\Admin\DashboardController as AdminDashboardController;
use Gratora\Rest\Admin\DonationsController as AdminDonationsController;
use Gratora\Rest\Admin\DonorsController as AdminDonorsController;
use Gratora\Rest\Admin\ExportsController as AdminExportsController;
use Gratora\Rest\Admin\FormsController as AdminFormsController;
use Gratora\Rest\Admin\FundsController as AdminFundsController;
use Gratora\Rest\Admin\FxController;
use Gratora\Rest\Admin\NumberingController as AdminNumberingController;
use Gratora\Rest\Admin\OnboardingController as AdminOnboardingController;
use Gratora\Rest\Admin\PayPalKeysController;
use Gratora\Rest\Admin\ReadinessController as AdminReadinessController;
use Gratora\Rest\Admin\RecurringController as AdminRecurringController;
use Gratora\Rest\Admin\ReportsController as AdminReportsController;
use Gratora\Rest\Admin\RolesController as AdminRolesController;
use Gratora\Rest\Admin\SettingsController as AdminSettingsController;
use Gratora\Rest\Admin\StripeKeysController;
use Gratora\Rest\Admin\ToolsController as AdminToolsController;
use Gratora\Rest\Admin\UserPrefsController as AdminUserPrefsController;
use Gratora\Rest\Portal\PortalController;

/** @since 1.0.0 */
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
        do_action('gratora.rest.register', $registry);
        foreach ($registry->all() as $controller) {
            $controller->registerRoutes();
        }
    }
}
