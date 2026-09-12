<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Async\AsyncDispatcher;
use Gratora\Donations\Donation;
use Gratora\Donors\DonorService;
use Gratora\Foundation\Plugin;
use Gratora\Recurring\RecurringPlan;
use Gratora\Recurring\RecurringPlanChange;

/**
 * The receipt already goes out in the language the donor filled the form in.
 * Every other mail they get about the same donation is a site-locale render, so
 * a French donor is thanked in French and told their card failed in English.
 */
final class DonorLocaleDrivesDonationEmailsTest extends IntegrationTestCase
{
    /** @var array<string,mixed>|null */
    private ?array $sent = null;

    private ?\WP_Locale_Switcher $originalSwitcher = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sent = null;

        // switch_to_locale() refuses a locale with no language pack installed,
        // which is WordPress behaving correctly and not what is under test. A
        // switcher built with fr_FR available is a site that has the pack.
        add_filter('get_available_languages', static fn (array $langs): array => array_merge($langs, ['fr_FR']));
        $this->originalSwitcher = $GLOBALS['wp_locale_switcher'];
        $GLOBALS['wp_locale_switcher'] = new \WP_Locale_Switcher();
        $GLOBALS['wp_locale_switcher']->init();

        // Marks anything translated while the switch is active, without a .mo.
        add_filter('gettext', static function ($translated, $text, $domain) {
            return $domain === 'gratora-donation-platform' && get_locale() === 'fr_FR'
                ? '[fr] ' . $translated
                : $translated;
        }, 10, 3);

        add_filter('wp_mail', function (array $args): array {
            $this->sent = $args;

            return $args;
        });
    }

    protected function tearDown(): void
    {
        if ($this->originalSwitcher !== null) {
            $GLOBALS['wp_locale_switcher'] = $this->originalSwitcher;
        }
        remove_all_filters('get_available_languages');
        remove_all_filters('gettext');
        remove_all_filters('wp_mail');
        parent::tearDown();
    }

    public function test_a_pending_notice_is_written_in_the_donors_language(): void
    {
        $donation = $this->donation('fr');

        do_action('gratora.donation.pending', $donation, 'awaiting_bank', []);

        $this->assertNotNull($this->sent, 'no mail was sent');
        $this->assertStringStartsWith('[fr] ', (string) $this->sent['subject']);
        $this->assertSame('en_US', get_locale(), 'the switch has to be unwound');
    }

    public function test_the_words_built_into_the_tokens_are_translated_too(): void
    {
        $donor = Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate('plan-fr@example.test', ['first_name' => 'Amelie']);
        $donor->locale = 'fr_FR';
        $donor->save();

        $plan = RecurringPlan::make();
        $plan->donor_id       = (int) $donor->id;
        $plan->gateway        = 'offline';
        $plan->gateway_subscription_id = 'offline-fr-1';
        $plan->status         = 'active';
        $plan->amount_cents   = 2000;
        $plan->currency       = 'USD';
        $plan->interval_unit  = 'month';
        $plan->interval_count = 1;
        $plan->created_at     = gmdate('Y-m-d H:i:s');
        $plan->updated_at     = gmdate('Y-m-d H:i:s');
        $plan->save();

        $change = RecurringPlanChange::byAdmin('change_interval', true);
        $change->detail = ['from' => 'weekly'];

        do_action('gratora.recurring.plan_changed', $plan, $change);

        $this->assertNotNull($this->sent, 'no mail was sent');
        // The word itself, not the template around it: only a switch that also
        // covers token building marks what FrequencyMap::label() returns.
        $this->assertStringContainsString('[fr] every month', (string) $this->sent['message']);
        $this->assertStringContainsString('[fr] every week', (string) $this->sent['message']);
        $this->assertSame('en_US', get_locale(), 'the switch has to be unwound');
    }

    public function test_a_sign_in_link_is_written_in_the_donors_language(): void
    {
        $email = 'signin-fr@example.test';
        $donor = Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate($email, ['first_name' => 'Amelie']);
        $donor->locale = 'fr_FR';
        $donor->save();

        Plugin::instance()->container->get(AsyncDispatcher::class)
            ->enqueue('gratora.async.send_portal_link', ['email' => $email]);
        $this->runPendingAsyncJobs();

        $this->assertNotNull($this->sent, 'no mail was sent');
        $this->assertStringStartsWith('[fr] ', (string) $this->sent['subject']);
        $this->assertSame('en_US', get_locale(), 'the switch has to be unwound');
    }

    private function donation(string $tag): Donation
    {
        $donor = Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate("locale-{$tag}@example.test", ['first_name' => 'Amelie']);

        $now = gmdate('Y-m-d H:i:s');
        $d   = Donation::make();
        $d->reference    = 'DN-LOCALE-' . strtoupper($tag);
        $d->donor_id     = (int) $donor->id;
        $d->amount_cents = 2500;
        $d->net_cents    = 2500;
        $d->currency     = 'USD';
        $d->base_amount_cents = 2500;
        $d->base_currency     = 'USD';
        $d->fx_rate      = '1.00000000';
        $d->gateway      = 'offline';
        $d->status       = 'pending';
        $d->locale       = 'fr_FR';
        $d->created_at   = $now;
        $d->updated_at   = $now;
        $d->save();

        return $d;
    }
}
