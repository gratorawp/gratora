<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Donors\DonorMetricsService;
use Gratora\Donors\DonorService;
use Gratora\Foundation\Plugin;
use Gratora\Recurring\RecurringPlan;

/**
 * The banner an admin sees on a donor whose renewal was declined.
 *
 * Its own comment says offering a retry the gateway cannot do "sends the admin
 * looking for a button that does not exist", and its fallback then told them to
 * ask the donor to update their card in the portal, which is the same dead end
 * one step removed: the portal renders that button only for a gateway that
 * implements SupportsPaymentMethodUpdate, and the route answers 422 otherwise.
 */
final class PastDueBannerTest extends IntegrationTestCase
{
    /** @return array<string,mixed> */
    private function bannersFor(string $gateway): array
    {
        $donor = Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate('pastdue-' . uniqid() . '@example.test', ['first_name' => 'Ada']);

        $now = gmdate('Y-m-d H:i:s');

        $plan = RecurringPlan::make();
        $plan->donor_id                = (int) $donor->id;
        $plan->gateway                 = $gateway;
        $plan->gateway_subscription_id = 'sub_' . uniqid();
        $plan->amount_cents            = 2000;
        $plan->currency                = 'USD';
        $plan->status                  = 'past_due';
        $plan->started_at              = $now;
        $plan->created_at              = $now;
        $plan->updated_at              = $now;
        $plan->save();

        $profile = Plugin::instance()->container->get(DonorMetricsService::class)
            ->profile((int) $donor->id);

        foreach ((array) ($profile['banners'] ?? []) as $banner) {
            if (($banner['kind'] ?? '') === 'past_due') {
                return $banner;
            }
        }

        return [];
    }

    /**
     * Offline can neither retry nor take a new card, so the admin is told what
     * is actually true rather than sent to a portal button that is not there.
     */
    public function test_a_gateway_that_can_do_neither_says_so(): void
    {
        $banner = $this->bannersFor('offline');

        $this->assertNotSame([], $banner, 'fixture: a past_due plan raises the banner');
        $this->assertStringNotContainsString(
            'update their card in the donor portal',
            (string) $banner['message'],
            'the portal does not offer that for this gateway'
        );
        $this->assertStringContainsString(
            'set the donation up again',
            (string) $banner['message'],
            'and the admin is told what can actually be done'
        );
    }

    public function test_a_disconnected_gateway_is_still_reported_as_disconnected(): void
    {
        $banner = $this->bannersFor('a-gateway-that-is-not-installed');

        $this->assertNotSame([], $banner);
        $this->assertStringContainsString(
            'is not set up on this site',
            (string) $banner['message'],
            'that cause is different and keeps its own wording'
        );
    }
}
