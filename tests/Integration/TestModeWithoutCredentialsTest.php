<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Donations\DonationRepository;
use Gratora\Donations\DonationService;
use Gratora\Donors\DonorRepository;
use Gratora\Donors\DonorService;
use Gratora\Foundation\Time\Clock;
use Gratora\Foundation\Crypto\Crypto;
use Gratora\Foundation\License\LicenseService;
use Gratora\Donors\Portal\PortalPage;
use Gratora\Forms\FormReadinessService;
use Gratora\Forms\FormRepository;
use Gratora\Gateways\GatewayManager;
use Gratora\Gateways\PayPal\PayPalAccount;
use Gratora\Gateways\Stripe\ApplePayDomain;
use Gratora\Gateways\Stripe\StripeAccount;
use Gratora\Gateways\Stripe\StripeApi;
use Gratora\Gateways\Stripe\StripeGateway;
use Gratora\Gateways\TestMode;
use Gratora\Foundation\Plugin;
use Gratora\Recurring\RecurringPlanRepository;
use Gratora\Settings\ReadinessService;
use Gratora\Settings\SettingsService;

/**
 * Test keys are optional, so an ordinary live site has none. Flipping the
 * org-wide switch then offered every donor a gateway that could not take their
 * money, and the only thing the screen said was that test mode was on.
 */
final class TestModeWithoutCredentialsTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        delete_option('gratora_gateway_config');
        (new StripeAccount(new Crypto()))->forget();
    }

    private function liveOnlyStripe(): StripeAccount
    {
        $account = new StripeAccount(new Crypto());
        $account->saveKeys(false, 'sk_live_only', 'pk_live_only');
        $account->refresh(['id' => 'acct_live', 'charges_enabled' => true]);

        return $account;
    }

    private function testModeOn(): void
    {
        update_option('gratora_gateway_config', ['test_mode' => true]);
    }


    private function stripeIn(StripeAccount $account): GatewayManager
    {
        $c       = Plugin::instance()->container;
        $manager = new GatewayManager();
        $manager->register(new StripeGateway(
            new StripeApi($account),
            $c->get(DonationRepository::class),
            $c->get(DonationService::class),
            $account,
            $c->get(DonorRepository::class),
            $c->get(DonorService::class),
            $c->get(Clock::class),
            $c->get(RecurringPlanRepository::class),
        ));

        return $manager;
    }

    public function test_a_gateway_with_no_sandbox_is_not_offered_while_test_mode_is_on(): void
    {
        $manager = $this->stripeIn($this->liveOnlyStripe());
        $this->assertArrayHasKey('stripe', $manager->availableFor('US', 'USD'), 'precondition: live mode offers it');

        $this->testModeOn();

        $this->assertArrayNotHasKey(
            'stripe',
            $manager->availableFor('US', 'USD'),
            'the donor was offered a gateway whose next step throws "Stripe has no test connection"'
        );
    }

    public function test_a_gateway_with_both_key_pairs_is_still_offered_in_test_mode(): void
    {
        $account = $this->liveOnlyStripe();
        $account->saveKeys(true, 'sk_test_ok', 'pk_test_ok');
        $this->testModeOn();

        $this->assertArrayHasKey('stripe', $this->stripeIn($account)->availableFor('US', 'USD'));
    }


    private function checks(): array
    {
        $settings = new SettingsService();
        $crypto   = new Crypto();
        $stripe   = new StripeAccount($crypto);
        $api      = new StripeApi($stripe);

        $service = new ReadinessService(
            $settings,
            new FormReadinessService($settings, new GatewayManager(), $stripe, new TestMode(new FormRepository()), \Gratora\Foundation\Plugin::instance()->container->get(\Gratora\Donors\ConsentService::class)),
            $stripe,
            $api,
            new ApplePayDomain($api, $stripe),
            new PayPalAccount($crypto),
            new GatewayManager(),
            new PortalPage(),
            new LicenseService(),
        );

        $out = [];
        foreach ($service->check() as $check) {
            $out[$check['id']] = $check;
        }

        return $out;
    }

    public function test_test_mode_with_no_test_keys_is_a_blocker_that_names_the_gateway(): void
    {
        $this->liveOnlyStripe();
        $this->testModeOn();

        $check = $this->checks()['mode'];

        $this->assertSame(ReadinessService::FAIL, $check['status'], 'the screen said only that test mode was on');
        $this->assertTrue($check['blocker']);
        $this->assertStringContainsString('Stripe', (string) $check['label']);
    }

    public function test_test_mode_with_test_keys_stays_the_ordinary_warning(): void
    {
        $account = $this->liveOnlyStripe();
        $account->saveKeys(true, 'sk_test_ok', 'pk_test_ok');
        $this->testModeOn();

        $this->assertSame(ReadinessService::WARN, $this->checks()['mode']['status']);
    }

    public function test_an_addon_reports_its_own_missing_sandbox(): void
    {
        $this->liveOnlyStripe();
        $account = new StripeAccount(new Crypto());
        $account->saveKeys(true, 'sk_test_ok', 'pk_test_ok');
        $this->testModeOn();

        add_filter('gratora.readiness.test_mode_gaps', static fn (array $gaps): array => [...$gaps, 'Square']);

        $check = $this->checks()['mode'];

        $this->assertSame(ReadinessService::FAIL, $check['status']);
        $this->assertStringContainsString('Square', (string) $check['label']);
    }

    public function test_a_live_mode_listener_is_not_consulted_in_test_mode(): void
    {
        $account = $this->liveOnlyStripe();
        $account->saveKeys(true, 'sk_test_ok', 'pk_test_ok');
        $this->testModeOn();

        add_filter('gratora.readiness.live_mode_gaps', static fn (array $gaps): array => [...$gaps, 'Square']);

        $this->assertSame(ReadinessService::WARN, $this->checks()['mode']['status']);
    }
}
