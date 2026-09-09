<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Donors\Donor;
use Gratora\Donors\DonorService;
use Gratora\Foundation\Plugin;
use Gratora\Recurring\RecurringPlan;
use Gratora\Recurring\RecurringPlanRepository;

/**
 * Donors and plans are named by their id on screen, so the id is what an admin
 * reading one off a screen will type into the search box.
 */
final class SearchByIdTest extends IntegrationTestCase
{
    private function donor(string $email): Donor
    {
        return Plugin::instance()->container
            ->get(DonorService::class)
            ->findOrCreate($email, ['first_name' => 'Nadia', 'last_name' => 'Petrova']);
    }

    private function plan(int $donorId): RecurringPlan
    {
        $now = gmdate('Y-m-d H:i:s');

        $p = RecurringPlan::make();
        $p->donor_id                = $donorId;
        $p->gateway                 = 'stripe';
        $p->gateway_subscription_id = 'sub_' . uniqid();
        $p->amount_cents            = 2500;
        $p->currency                = 'EUR';
        $p->interval_unit           = 'month';
        $p->interval_count          = 1;
        $p->status                  = 'active';
        $p->is_test                 = false;
        $p->started_at              = $now;
        $p->created_at              = $now;
        $p->updated_at              = $now;
        $p->save();

        return $p;
    }

    public function test_a_donor_id_finds_that_donor(): void
    {
        $donor = $this->donor('by-id@example.com');
        $ids   = Plugin::instance()->container
            ->get(DonorService::class)
            ->findIdsBySearch((string) $donor->id);

        $this->assertContains((int) $donor->id, array_map('intval', $ids));
    }

    public function test_a_donor_name_still_finds_that_donor(): void
    {
        $donor = $this->donor('by-name@example.com');
        $ids   = Plugin::instance()->container
            ->get(DonorService::class)
            ->findIdsBySearch('Petrova');

        $this->assertContains((int) $donor->id, array_map('intval', $ids));
    }

    public function test_a_plan_id_finds_that_plan(): void
    {
        $donor = $this->donor('plan-owner@example.com');
        $plan  = $this->plan((int) $donor->id);
        $other = $this->plan((int) $this->donor('someone-else@example.com')->id);

        $repo = Plugin::instance()->container->get(RecurringPlanRepository::class);
        $rows = $repo->listAdmin(['search' => (string) $plan->id, 'donor_ids' => []], ['per_page' => 50]);
        $got  = array_map(static fn (RecurringPlan $p): int => (int) $p->id, $rows);

        $this->assertContains((int) $plan->id, $got);
        $this->assertNotContains((int) $other->id, $got, 'an id search must not widen to other plans');
    }

    /**
     * The clause that narrows a search must never fall through to the whole
     * book when nothing matches.
     */
    public function test_a_search_matching_nothing_returns_nothing(): void
    {
        $this->plan((int) $this->donor('nobody@example.com')->id);

        $repo = Plugin::instance()->container->get(RecurringPlanRepository::class);
        $rows = $repo->listAdmin(['search' => 'zzzz-no-such-thing', 'donor_ids' => []], ['per_page' => 50]);

        $this->assertSame([], $rows);
    }
}
