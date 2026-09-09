<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Donors\Consent;
use Gratora\Donors\DonorMetricsService;
use Gratora\Donors\DonorService;
use Gratora\Foundation\Plugin;

/**
 * A consent row is written per purpose per donation, so a five-year monthly
 * donor with three purposes carries about 540 of them. Every neighbouring
 * collection on the profile is capped; this one was serialised whole on every
 * open, including for operators who never click the Consent tab.
 */
final class ConsentHistoryIsCappedTest extends IntegrationTestCase
{
    private function donorWithConsents(int $rows, string $purpose = 'newsletter'): int
    {
        $donor = Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate('consent-' . uniqid() . '@example.test', []);
        $id = (int) $donor->id;

        for ($i = 0; $i < $rows; $i++) {
            $c = Consent::make();
            $c->donor_id    = $id;
            $c->purpose     = $purpose;
            $c->granted     = ($i % 2) === 0;
            $c->occurred_at = gmdate('Y-m-d H:i:s', time() - $rows + $i);
            $c->save();
        }

        return $id;
    }

    private function profile(int $id): array
    {
        return Plugin::instance()->container->get(DonorMetricsService::class)->profile($id);
    }

    public function test_the_history_served_is_bounded(): void
    {
        $id = $this->donorWithConsents(140);

        $consents = $this->profile($id)['consents'];

        $this->assertCount(100, $consents['history']);
        $this->assertSame(140, $consents['history_total'], 'and the screen is told what it is not being shown');
    }

    /**
     * Capping the read would have been the easy fix and the wrong one: the same
     * rows decide the current state per purpose, so a purpose the donor acted on
     * long ago would drop out of `current` entirely.
     */
    public function test_a_purpose_last_touched_long_ago_is_still_in_the_current_state(): void
    {
        $id = $this->donorWithConsents(140);

        $old = Consent::make();
        $old->donor_id    = $id;
        $old->purpose     = 'post';
        $old->granted     = true;
        $old->occurred_at = gmdate('Y-m-d H:i:s', time() - YEAR_IN_SECONDS);
        $old->save();

        $purposes = array_column($this->profile($id)['consents']['current'], 'purpose');

        $this->assertContains('post', $purposes);
    }

    /**
     * occurred_at is second-precision. A grant and a revoke recorded in the same
     * second left the current state to whichever row the engine returned first.
     */
    public function test_two_rows_in_the_same_second_resolve_to_the_later_one(): void
    {
        $donor = Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate('tie-' . uniqid() . '@example.test', []);
        $id  = (int) $donor->id;
        $at  = gmdate('Y-m-d H:i:s');

        foreach ([true, false] as $granted) {
            $c = Consent::make();
            $c->donor_id    = $id;
            $c->purpose     = 'newsletter';
            $c->granted     = $granted;
            $c->occurred_at = $at;
            $c->save();
        }

        $current = $this->profile($id)['consents']['current'];
        $row     = array_values(array_filter($current, static fn ($r) => ($r['purpose'] ?? '') === 'newsletter'))[0] ?? null;

        $this->assertNotNull($row);
        $this->assertFalse((bool) $row['granted'], 'the revoke was written second, so it is the current state');
    }
}
