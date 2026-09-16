<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Admin\AdminGlobals;
use Gratora\Foundation\License\LicenseService;
use Gratora\Foundation\Plugin;
use Gratora\Gateways\Sandbox\SandboxRenewer;
use Gratora\Recurring\PlanStatus;
use Gratora\Recurring\RecurringPlan;
use Gratora\Recurring\RecurringPlanRepository;

/**
 * The words for a plan's statuses, where the statuses are written.
 *
 * Four admin surfaces, a donor portal and a GDPR export each kept their own
 * list of them, so a status this side added arrived as the database slug: a
 * PayPal plan waiting on activation read "pending" in English on a translated
 * site, and the export a data subject is entitled to said "past_due".
 */
final class PlanStatusVocabularyTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['plugin_page']);
        parent::tearDown();
    }

    private function plan(array $overrides = []): RecurringPlan
    {
        $now = gmdate('Y-m-d H:i:s');

        $p = RecurringPlan::make();
        $p->donor_id                = 1;
        $p->gateway                 = 'sandbox';
        $p->gateway_subscription_id = 'sub_' . bin2hex(random_bytes(4));
        $p->amount_cents            = 2000;
        $p->currency                = 'USD';
        $p->interval_unit           = 'month';
        $p->interval_count          = 1;
        $p->started_at              = $now;
        $p->created_at              = $now;
        $p->updated_at              = $now;

        foreach ($overrides as $column => $value) {
            $p->{$column} = $value;
        }

        $p->save();

        return $p;
    }

    private function reload(RecurringPlan $p): RecurringPlan
    {
        return RecurringPlan::query()->where('id', (int) $p->id)->get();
    }

    public function test_every_status_a_plan_can_hold_has_a_word(): void
    {
        $slugs = array_filter(
            PlanStatus::LIFECYCLE,
            static fn (string $status): bool => PlanStatus::label($status) === $status
        );

        $this->assertSame([], array_values($slugs));
    }

    /** The status a plan is born in. */
    public function test_a_new_plan_starts_inside_the_vocabulary(): void
    {
        $this->assertContains($this->plan()->status, PlanStatus::LIFECYCLE);
    }

    public function test_cancelling_lands_inside_the_vocabulary(): void
    {
        $plan = $this->plan(['status' => 'active']);

        Plugin::instance()->container->get(RecurringPlanRepository::class)
            ->markCancelled($plan, gmdate('Y-m-d H:i:s'));

        $this->assertContains($this->reload($plan)->status, PlanStatus::LIFECYCLE);
    }

    /** The sandbox is the one renewer that ends a plan on its own. */
    public function test_a_sandbox_plan_running_out_lands_inside_the_vocabulary(): void
    {
        $plan = $this->plan([
            'status'          => 'active',
            'is_test'         => true,
            'payments_count'  => 99,
            'next_payment_at' => gmdate('Y-m-d H:i:s', time() - 3600),
        ]);

        Plugin::instance()->container->get(SandboxRenewer::class)->run();

        $this->assertContains($this->reload($plan)->status, PlanStatus::LIFECYCLE);
    }

    /** Every screen is handed the whole lifecycle, in order, with its words. */
    public function test_the_admin_is_handed_the_vocabulary(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $GLOBALS['plugin_page'] = 'gratora-subscriptions';

        $shipped = $this->shippedToAdmin();

        $this->assertSame(PlanStatus::LIFECYCLE, array_column($shipped, 'value'));
        $this->assertNotContains('', array_column($shipped, 'label'));
        $this->assertNotContains('', array_column($shipped, 'variant'));
    }

    /** @return list<array{value:string,label:string,variant:string}> */
    private function shippedToAdmin(): array
    {
        global $wp_scripts;

        $wp_scripts = null;
        wp_scripts();

        (new AdminGlobals(Plugin::instance()->container->get(LicenseService::class)))->inject();

        $inline = (string) implode('', (array) (wp_scripts()->get_data('gratora-admin-globals', 'after') ?: []));
        preg_match('/Object\.assign\(window\.gratora, (.*)\);$/s', $inline, $m);

        $payload = json_decode($m[1] ?? '{}', true);

        return (array) ($payload['plan_statuses'] ?? []);
    }
}
