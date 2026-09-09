<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Campaigns\Campaign;
use Gratora\Settings\SettingsService;
use Gratora\Foundation\Plugin;

/**
 * Campaigns report in the single org currency, so the relabel on a base change
 * is the design. Firing it on every save of the group is not: the panel PUTs
 * the whole group, and a restore lands campaign rows in the file's own currency
 * after the settings write, so an unrelated separator change silently relabelled
 * them without restating a figure.
 */
final class CampaignCurrencyFollowsBaseOnlyTest extends IntegrationTestCase
{
    private function settings(): SettingsService
    {
        return Plugin::instance()->container->get(SettingsService::class);
    }

    private function campaignIn(string $currency): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $c = Campaign::make();
        $c->title      = 'Winter appeal';
        $c->slug       = 'winter-' . uniqid();
        $c->status     = 'published';
        $c->currency   = $currency;
        $c->goal_cents = 500000;
        $c->created_at = $now;
        $c->updated_at = $now;
        $c->save();

        return (int) $c->id;
    }

    private function currencyOf(int $id): string
    {
        return (string) Campaign::query()->where('id', $id)->get()->currency;
    }

    protected function setUp(): void
    {
        parent::setUp();
        update_option('gratora_currency_locale', ['default_currency' => 'USD', 'supported_currencies' => ['USD']], false);
    }

    public function test_an_unrelated_save_leaves_a_restored_campaign_alone(): void
    {
        $id = $this->campaignIn('GBP');

        $this->settings()->update('currency-locale', ['format' => ['thousand_sep' => ' ']]);

        $this->assertSame('GBP', $this->currencyOf($id));
    }

    /** The lockstep the design wants still happens on a real base change. */
    public function test_a_base_change_still_relabels_them(): void
    {
        $id = $this->campaignIn('GBP');

        $this->settings()->update('currency-locale', ['default_currency' => 'EUR', 'supported_currencies' => ['EUR']]);

        $this->assertSame('EUR', $this->currencyOf($id));
    }
}
