<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Campaigns\Campaign;
use Dono\Donations\Donation;
use Dono\Donors\DonorService;
use Dono\Foundation\Plugin;
use Dono\Recurring\RecurringPlan;
use Dono\Settings\SettingsService;

/**
 * The renewal notice goes out while the receipt row is still queued, so it can
 * only ever state a receipt number it does not have. Neither the shipped copy
 * nor the tags offered to a template author may promise one.
 */
final class RecurringRenewalEmailTest extends IntegrationTestCase
{
    public function test_the_renewal_notice_states_no_receipt_number(): void
    {
        $sent = [];
        add_filter('pre_wp_mail', function ($null, $atts) use (&$sent) {
            $sent[] = ['subject' => $atts['subject'] ?? '', 'body' => $atts['message'] ?? ''];
            return false;
        }, 10, 2);

        $now   = gmdate('Y-m-d H:i:s');
        $donor = Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate('renewal-' . uniqid() . '@example.test', ['first_name' => 'Rae', 'last_name' => 'New']);

        $campaign = Campaign::make();
        $campaign->title      = 'Renewal campaign';
        $campaign->slug       = 'renewal-campaign-' . uniqid();
        $campaign->status     = 'published';
        $campaign->currency   = 'USD';
        $campaign->created_at = $now;
        $campaign->updated_at = $now;
        $campaign->save();

        $plan = RecurringPlan::make();
        $plan->donor_id                = (int) $donor->id;
        $plan->campaign_id             = (int) $campaign->id;
        $plan->gateway                 = 'offline';
        $plan->gateway_subscription_id = 'sub_renew_' . bin2hex(random_bytes(4));
        $plan->amount_cents            = 2500;
        $plan->currency                = 'USD';
        $plan->interval_unit           = 'month';
        $plan->interval_count          = 1;
        $plan->status                  = 'active';
        $plan->started_at              = $now;
        $plan->created_at              = $now;
        $plan->updated_at              = $now;
        $plan->save();

        $donation = Donation::make();
        $donation->reference        = 'DONO-RENEW-' . strtoupper(bin2hex(random_bytes(3)));
        $donation->donor_id         = (int) $donor->id;
        $donation->campaign_id      = (int) $campaign->id;
        $donation->amount_cents     = 2500;
        $donation->net_cents        = 2500;
        $donation->currency         = 'USD';
        $donation->gateway          = 'offline';
        $donation->status           = 'paid';
        $donation->frequency        = 'monthly';
        $donation->paid_at          = $now;
        $donation->created_at       = $now;
        $donation->updated_at       = $now;
        $donation->save();

        do_action('dono.recurring.renewed', $donation, $plan);

        $this->assertCount(1, $sent);
        $body = (string) $sent[0]['body'];
        $this->assertStringNotContainsString('Receipt number', $body);
        $this->assertStringNotContainsString('{receipt_number}', $body);
        $this->assertStringContainsString((string) $donation->reference, $body);
    }

    public function test_the_editor_is_not_offered_a_receipt_number_tag(): void
    {
        // The tag list is what the settings editor offers an author as safe to
        // insert, so advertising one nothing fills is the same defect.
        $this->assertNotContains('receipt_number', SettingsService::templateTags()['recurring_renewal']);
    }
}
