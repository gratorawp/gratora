<?php

declare(strict_types=1);

namespace Gratora\Cli;

use Gratora\Campaigns\Campaign;
use Gratora\Campaigns\CampaignService;
use Gratora\Donations\AggregateSyncer;
use Gratora\Donations\DonationIntent;
use Gratora\Donations\DonationService;
use Gratora\Donors\Donor;
use Gratora\Donors\DonorService;
use Gratora\Forms\Form;
use Gratora\Foundation\Container\Container;
use Gratora\Foundation\Plugin;
use Gratora\Foundation\Time\Clock;
use Gratora\Funds\Fund;
use Gratora\Funds\FundService;
use Gratora\Onboarding\Onboarding;
use Gratora\Recurring\RecurringPlanRepository;
use Gratora\Settings\SettingsService;
use WP_CLI;

/**
 * `wp gratora ...` commands. Registered only under WP-CLI (see gratora.php).
 * Operational commands (migrate, recompute-aggregates) are production-safe;
 * seed writes fake data and is gated on org-wide test mode.
 *
 * @since 1.0.0
 */
final class CliCommands
{
    /** @since 1.0.0 */
    private function container(): Container
    {
        return Plugin::instance()->container;
    }

    /**
     * Run every registered model's schema migration (idempotent). Safe to run
     * on production after a deploy that changed a schema closure.
     *
     * @when after_wp_load
     * @since 1.0.0
     */
    public function migrate(array $args, array $assoc): void
    {
        $count = 0;
        if (! function_exists('dbDelta')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        foreach (Plugin::instance()->modules->allMigrations() as $model) {
            if (! method_exists($model, 'migrate')) {
                continue;
            }
            $t0 = microtime(true);
            $model::migrate(true);
            WP_CLI::log(sprintf('  %-52s %8.1f ms', $model, (microtime(true) - $t0) * 1000));
            $count++;
        }
        WP_CLI::success("Ran {$count} model migrations.");
    }

    /**
     * Recompute denormalized aggregates from the source-of-truth donation
     * rows. Production-safe (read-then-write of derived counters only).
     *
     * ## OPTIONS
     *
     * [--scope=<scope>]
     * : Which aggregates to recompute.
     * ---
     * default: all
     * options:
     *   - all
     *   - donors
     *   - funds
     *   - campaigns
     *   - forms
     * ---
     *
     * @when after_wp_load
     * @since 1.0.0
     */
    public function recompute_aggregates(array $args, array $assoc): void
    {
        $scope = (string) ($assoc['scope'] ?? 'all');
        $agg   = $this->container()->get(AggregateSyncer::class);

        $run = function (string $label, array $ids, callable $sync): void {
            if ($ids === []) {
                WP_CLI::log("  {$label}: nothing to do");
                return;
            }
            $bar = \WP_CLI\Utils\make_progress_bar("  {$label}", count($ids));
            foreach ($ids as $id) {
                $sync((int) $id);
                $bar->tick();
            }
            $bar->finish();
        };

        if ($scope === 'all' || $scope === 'donors') {
            $run('donors', $this->ids(Donor::class), fn (int $id) => $agg->syncDonor($id));
        }
        if ($scope === 'all' || $scope === 'funds') {
            $run('funds', $this->ids(Fund::class), fn (int $id) => $agg->syncFund($id));
        }
        if ($scope === 'all' || $scope === 'campaigns') {
            $run('campaigns', $this->ids(Campaign::class), fn (int $id) => $agg->syncCampaign($id));
        }
        if ($scope === 'all' || $scope === 'forms') {
            $run('forms', $this->ids(Form::class), fn (int $id) => $agg->syncForm($id));
        }

        WP_CLI::success('Aggregates recomputed.');
    }

    /**
     * Seed fake test donations so the admin has data to explore. Refuses
     * unless org-wide test mode is on, so it can never pollute a live site.
     * Seeded donations are flagged is_test and excluded from money reporting.
     *
     * ## OPTIONS
     *
     * [--donations=<n>]
     * : How many paid donations to create.
     * ---
     * default: 20
     * ---
     *
     * [--yes]
     * : Skip the confirmation prompt.
     *
     * @when after_wp_load
     * @since 1.0.0
     */
    public function seed(array $args, array $assoc): void
    {
        $gateways = $this->container()->get(SettingsService::class)->get('gateways');
        if (empty($gateways['test_mode'])) {
            WP_CLI::error(
                'Refusing to seed: org-wide test mode is off. Turn it on in '
                . 'Settings, Payment gateways (it is also set automatically by '
                . 'the "just exploring" onboarding path).'
            );
        }

        $n = max(1, min(1000, (int) ($assoc['donations'] ?? 20)));

        $campaign = Campaign::query()->where('status', 'published')->get()
            ?? Campaign::query()->get();
        if (! $campaign) {
            WP_CLI::error('No campaign found. Complete onboarding first.');
        }

        WP_CLI::confirm("Create {$n} fake test donations on campaign \"{$campaign->title}\"?", $assoc);

        $service  = $this->container()->get(DonationService::class);
        $agg      = $this->container()->get(AggregateSyncer::class);
        $currency = strtoupper((string) ($campaign->currency ?: 'USD'));
        $formId   = ((int) ($campaign->default_form_id ?? 0)) ?: null;
        $amounts  = [500, 1000, 1500, 2500, 5000, 10000, 25000];
        $stamp    = time();

        $donorIds = [];
        $bar = \WP_CLI\Utils\make_progress_bar('  seeding', $n);
        for ($i = 1; $i <= $n; $i++) {
            $intent = new DonationIntent(
                email:        "seed+{$stamp}-{$i}@example.test",
                amount_cents: $amounts[array_rand($amounts)],
                currency:     $currency,
                gateway:      'offline',
                frequency:    'one_time',
                form_id:      $formId,
                campaign_id:  (int) $campaign->id,
                profile:      ['first_name' => 'Seed', 'last_name' => 'Donor ' . $i],
            );

            $created  = $service->createPending($intent);
            $donation = $created['donation'];
            $service->confirm($donation, [
                'gateway_txn_id' => 'seed_txn_' . $donation->reference,
                'payment_method' => 'offline',
            ]);

            $when = gmdate('Y-m-d H:i:s', $stamp - random_int(0, 90 * 86400));
            $donation->created_at = $when;
            $donation->paid_at    = $when;
            $donation->updateColumns([
                'created_at' => $donation->created_at,
                'paid_at'    => $donation->paid_at,
            ]);

            $donorIds[$donation->donor_id] = true;
            $bar->tick();
        }
        $bar->finish();

        // Backdated inserts bypass event-driven aggregate updates.
        $agg->syncCampaign((int) $campaign->id);
        if ($formId !== null) {
            $agg->syncForm($formId);
        }
        foreach (array_keys($donorIds) as $donorId) {
            $agg->syncDonor((int) $donorId);
        }

        WP_CLI::success("Seeded {$n} test donations on \"{$campaign->title}\".");
    }

    /**
     * Seed a year of plausible fundraising history so the admin screens can be
     * screenshotted against something that looks like a real organisation.
     *
     * Unlike `wp gratora seed`, the rows are live (is_test = 0): test-mode rows
     * are excluded from money reporting by design, so a dashboard seeded with
     * them renders empty. That makes this unsafe anywhere real money is
     * recorded, and it refuses when it finds any.
     *
     * Idempotent: every row carries a stable key, and a second run converges
     * instead of duplicating.
     *
     * Creates 7 campaigns at different progress levels and statuses, 5 funds,
     * 140 donors, ~620 one-time donations spread over the last 12 months
     * (paid, pending, failed, refunded, partially refunded, disputed), and 44
     * recurring plans (active, paused, past_due, cancelled) with their renewal
     * donations.
     *
     * ## OPTIONS
     *
     * [--force]
     * : Seed even though the install already holds live donations this command
     * did not write. Only for a throwaway install.
     *
     * [--purge]
     * : Remove the rows this command wrote instead of writing more. Matched on
     * the demo key, so nothing the org recorded itself is in range.
     *
     * [--yes]
     * : Skip the confirmation prompt.
     *
     * ## EXAMPLES
     *
     *     wp gratora demo-seed
     *     wp gratora demo-seed --yes
     *     wp gratora demo-seed --purge
     *
     * @when after_wp_load
     * @since 1.0.0
     */
    public function demo_seed(array $args, array $assoc): void
    {
        if (! empty($assoc['purge'])) {
            $this->demo_purge($assoc);
            return;
        }

        $foreign = DemoSeeder::foreignLiveDonations();
        if ($foreign > 0 && empty($assoc['force'])) {
            WP_CLI::error(sprintf(
                'Refusing to seed: this install already holds %d live donations that this '
                . 'command did not write. Demo data is written live, so it would mix into '
                . 'the org\'s own reporting. Pass --force only on a throwaway install.',
                $foreign
            ));
        }

        WP_CLI::confirm(
            'Write ~1,000 live demo donations, 140 donors, 7 campaigns and 44 recurring '
            . 'plans into this install?',
            $assoc
        );

        // Complete onboarding so screenshot runs can reach admin screens.
        update_option(Onboarding::OPTION, 'completed', false);

        $t0     = microtime(true);
        $counts = $this->demoSeeder()->run(static fn (string $line) => WP_CLI::log('  ' . $line));

        WP_CLI::success(sprintf(
            'Demo data ready in %.1fs: %d campaigns, %d funds, %d donors, %d donations '
            . '(%d recurring renewals), %d recurring plans, %d rows already present.',
            microtime(true) - $t0,
            $counts['campaigns'],
            $counts['funds'],
            $counts['donors'],
            $counts['donations'],
            $counts['renewals'],
            $counts['recurring_plans'],
            $counts['skipped'],
        ));
    }

    /**
     * The `--purge` half of demo-seed: take back exactly what it wrote.
     *
     * Demo rows are live by design, so the maintenance purge (which reads
     * is_test) cannot see them and the admin has no way to delete a donation.
     * Without this the only route back is hand-written SQL.
     *
     * @param array<string,mixed> $assoc
     *
     * @since 1.0.0
     */
    private function demo_purge(array $assoc): void
    {
        $seeder  = $this->demoSeeder();
        $planned = $seeder->purgePreview();

        if (array_sum($planned) === 0) {
            WP_CLI::success('Nothing to remove: this install holds no demo rows.');
            return;
        }

        WP_CLI::confirm(sprintf(
            'Remove %d demo donations, %d demo recurring plans and %d demo donors from '
            . 'this install? Demo campaigns, funds and their pages are left for you to '
            . 'delete in the admin.',
            $planned['donations'],
            $planned['recurring_plans'],
            $planned['donors'],
        ), $assoc);

        $t0      = microtime(true);
        $removed = $seeder->purge(static fn (string $line) => WP_CLI::log('  ' . $line));

        WP_CLI::success(sprintf(
            'Demo data removed in %.1fs: %d donations, %d recurring plans, %d donors.',
            microtime(true) - $t0,
            $removed['donations'],
            $removed['recurring_plans'],
            $removed['donors'],
        ));
    }

    /** @since 1.0.0 */
    private function demoSeeder(): DemoSeeder
    {
        $c = $this->container();

        return new DemoSeeder(
            $c->get(DonationService::class),
            $c->get(DonorService::class),
            $c->get(CampaignService::class),
            $c->get(FundService::class),
            $c->get(AggregateSyncer::class),
            $c->get(RecurringPlanRepository::class),
            $c->get(Clock::class),
        );
    }

    /**
     * @param class-string $model
     * @return list<int>
     * @since 1.0.0
     */
    private function ids(string $model): array
    {
        $rows = $model::query()->getAll();
        return array_values(array_map(static fn ($r) => (int) $r->id, $rows));
    }
}
