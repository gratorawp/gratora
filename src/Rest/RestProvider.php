<?php

declare(strict_types=1);

namespace Dono\Rest;

use Dono\Foundation\Hooks\HookProvider;
use Dono\Rest\Admin\AdvancedController as AdminAdvancedController;
use Dono\Rest\Admin\CampaignsController as AdminCampaignsController;
use Dono\Rest\Admin\CommandsController as AdminCommandsController;
use Dono\Rest\Admin\DashboardController as AdminDashboardController;
use Dono\Rest\Admin\DonationsController as AdminDonationsController;
use Dono\Rest\Admin\DonorsController as AdminDonorsController;
use Dono\Rest\Admin\FormsController as AdminFormsController;
use Dono\Rest\Admin\FundsController as AdminFundsController;
use Dono\Rest\Admin\FxController;
use Dono\Rest\Admin\GiftAidController;
use Dono\Rest\Admin\LicenseController as AdminLicenseController;
use Dono\Rest\Admin\NumberingController as AdminNumberingController;
use Dono\Rest\Admin\OnboardingController as AdminOnboardingController;
use Dono\Rest\Admin\ReportsController as AdminReportsController;
use Dono\Rest\Admin\RolesController as AdminRolesController;
use Dono\Rest\Admin\SettingsController as AdminSettingsController;
use Dono\Rest\Admin\PayPalKeysController;
use Dono\Rest\Admin\RazorpayKeysController;
use Dono\Rest\Admin\StripeKeysController;
use Dono\Rest\Admin\UserPrefsController as AdminUserPrefsController;
use Dono\Rest\Portal\PortalController;

/**
 * Registers all REST route groups on rest_api_init.
 *
 * @version 1.0.0
 */
final class RestProvider extends HookProvider
{
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
        private AdminLicenseController $adminLicense,
        private PortalController $portal,
        private AdminRolesController $adminRoles,
        private AdminAdvancedController $adminAdvanced,
        private AdminOnboardingController $adminOnboarding,
        private StripeKeysController $stripeKeys,
        private PayPalKeysController $payPalKeys,
        private PayPalController $payPal,
        private RazorpayKeysController $razorpayKeys,
        private RazorpayController $razorpay,
        private FxController $fx,
        private AdminCommandsController $commands,
        private AdminNumberingController $numbering,
        private AdminReportsController $reports,
        private GiftAidController $giftAid,
    ) {
    }

    protected function actions(): array
    {
        return ['rest_api_init' => 'registerRoutes'];
    }

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
        $this->adminLicense->registerRoutes();
        $this->portal->registerRoutes();
        $this->adminRoles->registerRoutes();
        $this->adminAdvanced->registerRoutes();
        $this->adminOnboarding->registerRoutes();
        $this->stripeKeys->registerRoutes();
        $this->payPalKeys->registerRoutes();
        $this->payPal->registerRoutes();
        $this->razorpayKeys->registerRoutes();
        $this->razorpay->registerRoutes();
        $this->fx->registerRoutes();
        $this->commands->registerRoutes();
        $this->numbering->registerRoutes();
        $this->reports->registerRoutes();
        $this->giftAid->registerRoutes();

        $registry = new ControllerRegistry();
        do_action('dono.rest.register', $registry);
        foreach ($registry->all() as $controller) {
            $controller->registerRoutes();
        }
    }
}
