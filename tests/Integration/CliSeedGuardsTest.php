<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Cli\CliCommands;
use FundKit\Cli\DemoSeeder;
use FundKit\Donations\DonationIntent;
use FundKit\Donations\DonationService;
use FundKit\Foundation\Plugin;
use FundKitCliHalt;

/**
 * The seeding commands ship in the release zip and run against whatever
 * install the operator is pointed at. Both write things a live org cannot
 * undo: e2e-seed rewrites the org currency, its number format and org-wide
 * test mode, and demo-seed writes donations that no screen in the plugin can
 * delete. What is asserted here is that each one stops first.
 *
 * WP-CLI is not loaded under PHPUnit, so the runtime is stood in for
 * (tests/Integration/wp-cli-double.php): error() and confirm() throw, which is
 * how the real ones end a command.
 */
final class CliSeedGuardsTest extends IntegrationTestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/wp-cli-double.php';
        parent::setUpBeforeClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        \WP_CLI::$log = [];
    }

    private function commands(): CliCommands
    {
        return new CliCommands();
    }

    private function donations(): DonationService
    {
        return Plugin::instance()->container->get(DonationService::class);
    }


    public function test_e2e_seed_refuses_on_an_install_that_reports_production(): void
    {
        $before = get_option('fundkit_currency_locale');

        try {
            $this->commands()->e2e_seed([], ['yes' => true]);
            $this->fail('e2e-seed ran on a production install');
        } catch (FundKitCliHalt $halt) {
            $this->assertStringContainsString('production', $halt->getMessage());
        }

        $this->assertSame($before, get_option('fundkit_currency_locale'), 'the org currency is untouched');
        $this->assertArrayNotHasKey(
            'test_mode',
            (array) get_option('fundkit_gateway_config', []),
            'org-wide test mode is not switched on by a refused command'
        );
    }

    /** Nothing is written before the refusal, including the onboarding state. */
    public function test_a_refused_e2e_seed_leaves_onboarding_alone(): void
    {
        update_option('fundkit_onboarding_status', 'pending', false);

        try {
            $this->commands()->e2e_seed([], ['yes' => true]);
        } catch (FundKitCliHalt) {
            // The refusal is the subject of the test above.
        }

        $this->assertSame('pending', get_option('fundkit_onboarding_status'));
    }

    /**
     * Past the environment gate it still asks, so an operator who reached for
     * --force is told what --force buys. A halt that reads "confirm" rather
     * than "error" is also how this test knows --force cleared the gate.
     */
    public function test_e2e_seed_asks_before_it_writes_even_with_force(): void
    {
        try {
            $this->commands()->e2e_seed([], ['force' => true]);
            $this->fail('e2e-seed wrote without asking');
        } catch (FundKitCliHalt $halt) {
            $this->assertStringStartsWith('confirm:', $halt->getMessage());
            $this->assertStringContainsString('test mode', $halt->getMessage());
            $this->assertStringContainsString('currency', $halt->getMessage());
        }
    }


    /**
     * A bank-transfer donation sits pending until an admin reconciles it. An
     * org whose only donations are pending is exactly the org whose books a
     * settled-only count would call empty.
     */
    public function test_demo_seed_refuses_when_the_install_holds_a_pending_live_donation(): void
    {
        $this->liveDonation();

        $this->assertGreaterThan(0, DemoSeeder::foreignLiveDonations());

        try {
            $this->commands()->demo_seed([], ['yes' => true]);
            $this->fail('demo-seed wrote into a real book of record');
        } catch (FundKitCliHalt $halt) {
            $this->assertStringContainsString('live donations', $halt->getMessage());
        }
    }

    /** Its own rows are not somebody else's, or a second run could never happen. */
    public function test_the_seeders_own_rows_do_not_count_against_it(): void
    {
        $donation = $this->liveDonation();
        $this->donations()->setGatewayIntent($donation, DemoSeeder::KEY_PREFIX . 'd0001');

        $this->assertSame(0, DemoSeeder::foreignLiveDonations());
    }

    public function test_a_test_donation_does_not_count_against_it(): void
    {
        $this->liveDonation(isTest: true);

        $this->assertSame(0, DemoSeeder::foreignLiveDonations());
    }

    private function liveDonation(bool $isTest = false): \FundKit\Donations\Donation
    {
        return $this->donations()->createPending(new DonationIntent(
            email: 'offline.giver@example.test',
            amount_cents: 5000,
            currency: 'USD',
            gateway: 'offline',
            profile: ['first_name' => 'Ruth', 'last_name' => 'Bank'],
            is_test: $isTest,
        ))['donation'];
    }
}
