<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Cli\CliCommands;
use Dono\Cli\DemoSeeder;
use Dono\Donations\Donation;
use Dono\Donations\DonationIntent;
use Dono\Donations\DonationService;
use Dono\Donors\Donor;
use Dono\Foundation\Plugin;
use Dono\Recurring\RecurringPlan;
use DonoCliHalt;

/**
 * Demo data is written live on purpose, so every screen that hides test rows
 * shows it and every total counts it. That is the point, and it is also why it
 * has to be removable: the maintenance purge reads is_test and so cannot see a
 * demo row, and there is no route to deleting a donation in the admin at all.
 * Without an undo the only way back off an evaluation install is hand-written
 * SQL against the donations table.
 *
 * The rows are matched on the demo key rather than on a date, a donor or a
 * slug, because the cost of removing one row too many here is somebody's
 * donation history.
 */
final class DemoSeederPurgeTest extends IntegrationTestCase
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

    private function seeder(): DemoSeeder
    {
        $c = Plugin::instance()->container;

        return new DemoSeeder(
            $c->get(DonationService::class),
            $c->get(\Dono\Donors\DonorService::class),
            $c->get(\Dono\Campaigns\CampaignService::class),
            $c->get(\Dono\Funds\FundService::class),
            $c->get(\Dono\Donations\AggregateSyncer::class),
            $c->get(\Dono\Recurring\RecurringPlanRepository::class),
            $c->get(\Dono\Foundation\Time\Clock::class),
        );
    }

    private function donations(): DonationService
    {
        return Plugin::instance()->container->get(DonationService::class);
    }

    private function donation(string $email, ?string $intentId): Donation
    {
        $donation = $this->donations()->createPending(new DonationIntent(
            email: $email,
            amount_cents: 4200,
            currency: 'USD',
            gateway: 'offline',
            profile: ['first_name' => 'Pat', 'last_name' => 'Giver'],
            is_test: false,
        ))['donation'];

        if ($intentId !== null) {
            $this->donations()->setGatewayIntent($donation, $intentId);
        }

        return $donation;
    }

    private function plan(int $donorId, string $subscriptionId): RecurringPlan
    {
        $plan                          = RecurringPlan::make();
        $plan->donor_id                = $donorId;
        $plan->gateway                 = 'offline';
        $plan->gateway_subscription_id = $subscriptionId;
        $plan->amount_cents            = 1000;
        $plan->currency                = 'USD';
        $plan->interval_unit           = 'month';
        $plan->interval_count          = 1;
        $plan->status                  = 'active';
        $plan->is_test                 = false;
        $plan->save();

        return $plan;
    }

    private function purge(): array
    {
        return $this->seeder()->purge(static fn (string $line) => null);
    }

    public function test_it_removes_the_donations_the_seeder_wrote(): void
    {
        $demo = $this->donation('demo.donor@example.org', DemoSeeder::KEY_PREFIX . 'd0001');

        $removed = $this->purge();

        $this->assertSame(1, $removed['donations']);
        $this->assertNull(Donation::query()->where('id', (int) $demo->id)->get());
    }

    /** The whole reason to key on the marker rather than anything else. */
    public function test_a_donation_the_org_recorded_itself_survives(): void
    {
        $real = $this->donation('real.donor@example.test', null);
        $this->donation('demo.donor@example.org', DemoSeeder::KEY_PREFIX . 'd0001');

        $removed = $this->purge();

        $this->assertSame(1, $removed['donations'], 'only the demo row is in range');
        $this->assertNotNull(
            Donation::query()->where('id', (int) $real->id)->get(),
            'a donation with no gateway key is the org\'s own, not the seeder\'s'
        );
    }

    /** A gateway key that is not the seeder's is somebody else's donation. */
    public function test_a_donation_with_a_real_gateway_key_survives(): void
    {
        $real = $this->donation('stripe.donor@example.test', 'pi_3PabcdefghIJKL');

        $removed = $this->purge();

        $this->assertSame(0, $removed['donations']);
        $this->assertNotNull(Donation::query()->where('id', (int) $real->id)->get());
    }

    public function test_it_removes_the_recurring_plans_the_seeder_wrote(): void
    {
        $donor = $this->donation('demo.donor@example.org', DemoSeeder::KEY_PREFIX . 'd0001')->donor_id;

        $demoPlan = $this->plan((int) $donor, DemoSeeder::KEY_PREFIX . 'sub001');
        $realPlan = $this->plan((int) $donor, 'sub_1PabcdefghIJKL');

        $removed = $this->purge();

        $this->assertSame(1, $removed['recurring_plans']);
        $this->assertNull(RecurringPlan::query()->where('id', (int) $demoPlan->id)->get());
        $this->assertNotNull(RecurringPlan::query()->where('id', (int) $realPlan->id)->get());
    }

    /** A donor invented for the demo has nothing left to belong to. */
    public function test_a_donor_who_only_ever_gave_demo_money_goes(): void
    {
        $demo = $this->donation('only.demo@example.org', DemoSeeder::KEY_PREFIX . 'd0002');

        $removed = $this->purge();

        $this->assertSame(1, $removed['donors']);
        $this->assertNull(Donor::query()->where('id', (int) $demo->donor_id)->get());
    }

    /**
     * The demo roster reuses invented addresses, so a real donor can end up
     * sharing one. Their row is theirs whatever else is attached to it.
     */
    public function test_a_donor_who_also_gave_for_real_keeps_their_row(): void
    {
        $real = $this->donation('shared@example.org', null);
        $this->donation('shared@example.org', DemoSeeder::KEY_PREFIX . 'd0003');

        $removed = $this->purge();

        $this->assertSame(0, $removed['donors']);
        $this->assertNotNull(Donor::query()->where('id', (int) $real->donor_id)->get());
    }

    /** A live recurring plan is as good a reason to keep a donor as a donation. */
    public function test_a_donor_with_a_plan_of_their_own_keeps_their_row(): void
    {
        $demo = $this->donation('planholder@example.org', DemoSeeder::KEY_PREFIX . 'd0004');
        $this->plan((int) $demo->donor_id, 'sub_1PrealPLAN');

        $removed = $this->purge();

        $this->assertSame(0, $removed['donors']);
        $this->assertNotNull(Donor::query()->where('id', (int) $demo->donor_id)->get());
    }

    public function test_the_preview_counts_what_the_purge_removes(): void
    {
        $demo = $this->donation('preview@example.org', DemoSeeder::KEY_PREFIX . 'd0005');
        $this->plan((int) $demo->donor_id, DemoSeeder::KEY_PREFIX . 'sub002');
        $this->donation('real@example.test', null);

        $planned = $this->seeder()->purgePreview();
        $removed = $this->purge();

        $this->assertSame($planned, $removed, 'the confirmation prompt promises what happens');
    }

    /** The command reaches the purge, and asks before it deletes anything. */
    public function test_the_command_asks_before_removing_anything(): void
    {
        $demo = $this->donation('cli@example.org', DemoSeeder::KEY_PREFIX . 'd0006');

        try {
            (new CliCommands())->demo_seed([], ['purge' => true]);
            $this->fail('the purge deleted without asking');
        } catch (DonoCliHalt $halt) {
            $this->assertStringStartsWith('confirm:', $halt->getMessage());
            $this->assertStringContainsString('1 demo donations', $halt->getMessage());
        }

        $this->assertNotNull(Donation::query()->where('id', (int) $demo->id)->get());
    }

    public function test_the_command_removes_the_rows_once_confirmed(): void
    {
        $demo = $this->donation('cli.yes@example.org', DemoSeeder::KEY_PREFIX . 'd0007');
        $real = $this->donation('cli.real@example.test', null);

        (new CliCommands())->demo_seed([], ['purge' => true, 'yes' => true]);

        $this->assertNull(Donation::query()->where('id', (int) $demo->id)->get());
        $this->assertNotNull(Donation::query()->where('id', (int) $real->id)->get());
    }

    /** Nothing to take back is not an error, and must not seed instead. */
    public function test_the_command_says_so_when_there_is_nothing_to_remove(): void
    {
        $real = $this->donation('untouched@example.test', null);

        (new CliCommands())->demo_seed([], ['purge' => true]);

        $this->assertNotNull(Donation::query()->where('id', (int) $real->id)->get());
        $this->assertStringContainsString('no demo rows', implode("\n", \WP_CLI::$log));
    }
}
