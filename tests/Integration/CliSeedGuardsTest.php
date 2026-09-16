<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Cli\CliCommands;
use Gratora\Cli\DemoSeeder;
use Gratora\Donations\Donation;
use Gratora\Donations\DonationIntent;
use Gratora\Donations\DonationService;
use Gratora\Foundation\Plugin;
use Gratora\Tests\E2e\E2eSeedCommand;
use GratoraCliHalt;
use WP_CLI;
use WP_User;

/**
 * The seeding commands run against whatever install the operator is pointed
 * at, and both write things a live org cannot undo: e2e-seed rewrites the org
 * currency, its number format and org-wide test mode, and demo-seed writes
 * donations that no screen in the plugin can delete. What is asserted here is
 * that each one stops first, and that e2e-seed never hands out a known
 * administrator password or takes over an account it did not create.
 *
 * WP-CLI is not loaded under PHPUnit, so the runtime is stood in for
 * (tests/Integration/wp-cli-double.php): error() and confirm() throw, which is
 * how the real ones end a command.
 */
final class CliSeedGuardsTest extends IntegrationTestCase
{
    private const ENV = ['GRATORA_E2E_ADMIN_USER', 'GRATORA_E2E_ADMIN_PASS'];

    /** @var array<string, string|false> */
    private array $env = [];

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/wp-cli-double.php';
        require_once dirname(__DIR__, 2) . '/tests-e2e/cli/E2eSeedCommand.php';
        parent::setUpBeforeClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        WP_CLI::$log = [];

        foreach (self::ENV as $name) {
            $this->env[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->env as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }

        parent::tearDown();
    }

    private function commands(): CliCommands
    {
        return new CliCommands();
    }

    private function e2eSeed(): E2eSeedCommand
    {
        return new E2eSeedCommand();
    }

    private function donations(): DonationService
    {
        return Plugin::instance()->container->get(DonationService::class);
    }


    public function test_e2e_seed_refuses_on_an_install_that_reports_production(): void
    {
        $before = get_option('gratora_currency_locale');

        try {
            $this->e2eSeed()->seed([], ['yes' => true]);
            $this->fail('e2e-seed ran on a production install');
        } catch (GratoraCliHalt $halt) {
            $this->assertStringContainsString('production', $halt->getMessage());
        }

        $this->assertSame($before, get_option('gratora_currency_locale'), 'the org currency is untouched');
        $this->assertArrayNotHasKey(
            'test_mode',
            (array) get_option('gratora_gateway_config', []),
            'org-wide test mode is not switched on by a refused command'
        );
    }

    /** Nothing is written before the refusal, including the onboarding state. */
    public function test_a_refused_e2e_seed_leaves_onboarding_alone(): void
    {
        update_option('gratora_onboarding_status', 'pending', false);

        try {
            $this->e2eSeed()->seed([], ['yes' => true]);
        } catch (GratoraCliHalt) {
            // The refusal is the subject of the test above.
        }

        $this->assertSame('pending', get_option('gratora_onboarding_status'));
    }

    /**
     * Past the environment gate it still asks, so an operator who reached for
     * --force is told what --force buys. A halt that reads "confirm" rather
     * than "error" is also how this test knows --force cleared the gate.
     */
    public function test_e2e_seed_asks_before_it_writes_even_with_force(): void
    {
        try {
            $this->e2eSeed()->seed([], ['force' => true]);
            $this->fail('e2e-seed wrote without asking');
        } catch (GratoraCliHalt $halt) {
            $this->assertStringStartsWith('confirm:', $halt->getMessage());
            $this->assertStringContainsString('test mode', $halt->getMessage());
            $this->assertStringContainsString('currency', $halt->getMessage());
        }
    }

    /**
     * The login comes from the environment, so it can name a real account. The
     * seed must not reset that account's password or promote it.
     */
    public function test_e2e_seed_refuses_an_existing_account_it_did_not_create(): void
    {
        $ownerId = self::factory()->user->create([
            'user_login' => 'site-owner',
            'user_pass'  => 'owner-secret-1',
            'role'       => 'editor',
        ]);
        putenv('GRATORA_E2E_ADMIN_USER=site-owner');
        $currencyBefore = get_option('gratora_currency_locale');

        try {
            $this->e2eSeed()->seed([], ['force' => true, 'yes' => true]);
            $this->fail('e2e-seed adopted an account it did not create');
        } catch (GratoraCliHalt $halt) {
            $this->assertStringStartsWith('error:', $halt->getMessage());
            $this->assertStringContainsString('site-owner', $halt->getMessage());
        }

        $owner = get_user_by('id', $ownerId);
        $this->assertTrue(wp_check_password('owner-secret-1', $owner->user_pass, $ownerId), 'the password is untouched');
        $this->assertSame(['editor'], array_values($owner->roles), 'the account is not promoted');
        $this->assertSame($currencyBefore, get_option('gratora_currency_locale'), 'nothing was written before the refusal');
    }

    public function test_e2e_seed_refuses_the_default_login_when_it_did_not_create_it(): void
    {
        $id = self::factory()->user->create([
            'user_login' => 'gratora-e2e-admin',
            'user_pass'  => 'known-e2e-pass',
            'role'       => 'subscriber',
        ]);
        $currencyBefore = get_option('gratora_currency_locale');

        try {
            $this->e2eSeed()->seed([], ['force' => true, 'yes' => true]);
            $this->fail('e2e-seed adopted the default login without its marker');
        } catch (GratoraCliHalt $halt) {
            $this->assertStringStartsWith('error:', $halt->getMessage());
            $this->assertStringContainsString('gratora-e2e-admin', $halt->getMessage());
        }

        $user = get_user_by('id', $id);
        $this->assertTrue(wp_check_password('known-e2e-pass', $user->user_pass, $id), 'the password is untouched');
        $this->assertSame(['subscriber'], array_values($user->roles), 'the account is not promoted');
        $this->assertSame($currencyBefore, get_option('gratora_currency_locale'), 'nothing was written before the refusal');
    }

    public function test_the_fixture_admin_password_is_generated_unless_the_operator_sets_one(): void
    {
        [$login, $first] = $this->e2eSeed()->ensureAdmin();

        $user = get_user_by('login', $login);
        $this->assertInstanceOf(WP_User::class, $user, 'the fixture admin is created');
        $this->assertContains('administrator', $user->roles);
        $this->assertTrue(wp_check_password($first, $user->user_pass, $user->ID), 'the printed password is the stored one');
        $this->assertFalse(wp_check_password('gratora-e2e-pass', $user->user_pass, $user->ID));
        $this->assertSame(24, strlen($first));

        [, $second] = $this->e2eSeed()->ensureAdmin();

        $user = get_user_by('login', $login);
        $this->assertNotSame($first, $second, 'a re-run generates a new password rather than reusing a known one');
        $this->assertTrue(wp_check_password($second, $user->user_pass, $user->ID), 'the reused account takes the printed password');
        $this->assertFalse(wp_check_password($first, $user->user_pass, $user->ID));

        putenv('GRATORA_E2E_ADMIN_PASS=chosen-by-the-operator');

        [, $chosen] = $this->e2eSeed()->ensureAdmin();

        $user = get_user_by('login', $login);
        $this->assertSame('chosen-by-the-operator', $chosen, 'an operator-chosen password is used as given');
        $this->assertTrue(wp_check_password($chosen, $user->user_pass, $user->ID));
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
        } catch (GratoraCliHalt $halt) {
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

    private function liveDonation(bool $isTest = false): Donation
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
