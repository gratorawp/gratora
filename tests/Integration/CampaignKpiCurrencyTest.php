<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Campaigns\Campaign;
use FundKit\Analytics\EventRecorder;
use FundKit\Campaigns\CampaignRepository;
use FundKit\Core\Commands\CoreCommandProvider;
use FundKit\Foundation\Commands\CommandContext;
use FundKit\Foundation\Commands\CommandRegistry;
use FundKit\Foundation\Plugin;
use WP_REST_Request;

/**
 * The campaigns KPI strip sums campaigns.raised_cents, which the aggregate
 * syncer writes in the org's base currency. Labelling that one figure with the
 * most common per-campaign currency printed a euro total under a dollar sign on
 * any site whose campaigns are mostly denominated abroad.
 */
final class CampaignKpiCurrencyTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        update_option('fundkit_currency_locale', [
            'default_currency'     => 'EUR',
            'supported_currencies' => ['EUR', 'USD'],
        ]);
    }

    public function test_the_total_is_labelled_in_the_currency_it_is_summed_in(): void
    {
        // Most campaigns denominated in dollars, the org's books in euro.
        $this->campaign('us-one',   'USD', 100000);
        $this->campaign('us-two',   'USD', 250000);
        $this->campaign('home-one', 'EUR', 50000);

        $stats = Plugin::instance()->container->get(CampaignRepository::class)->aggregateAdmin();

        $this->assertSame(400000, (int) $stats['raised_cents']);
        $this->assertSame('EUR', $stats['currency'], 'the base currency the sum is actually in');
    }

    /**
     * The strip and the rows it sits above are one screen and one request pair,
     * so they cannot report the same numbers in different units.
     */
    public function test_the_strip_and_the_rows_under_it_agree(): void
    {
        $this->campaign('us-only', 'USD', 100000);

        $strip = rest_do_request(new WP_REST_Request('GET', '/fundkit/v1/admin/campaigns/stats'));
        $this->assertSame(200, $strip->get_status());
        $this->assertSame('EUR', (string) ($strip->get_data()['currency'] ?? ''));

        $rows = rest_do_request(new WP_REST_Request('GET', '/fundkit/v1/admin/campaigns'));
        $this->assertSame(200, $rows->get_status());

        foreach ((array) $rows->get_data() as $row) {
            $this->assertSame('EUR', (string) ($row['currency'] ?? ''), 'a row disagreeing with the strip above it');
        }
    }

    /** The same figure reaches the command registry, so it carries the same unit. */
    public function test_the_command_registry_reads_it_the_same_way(): void
    {
        $this->campaign('us-only', 'USD', 100000);

        $c        = Plugin::instance()->container;
        $registry = new CommandRegistry($c->get(EventRecorder::class));
        (new CoreCommandProvider())->register($registry, $c);

        $ctx = new CommandContext(get_current_user_id(), 'rest', 'req-' . uniqid());
        $res = $registry->dispatch('campaign.list', ['per_page' => 50], $ctx);

        $this->assertTrue($res->ok, $res->error ?? '');
        $this->assertNotSame([], $res->data['items'], 'the command returned a campaign');
        foreach ($res->data['items'] as $row) {
            $this->assertSame('EUR', (string) ($row['currency'] ?? ''));
        }
    }

    /**
     * The figure follows a base-currency change in the same process. It used to
     * be memoised on first read, so a CLI run that spanned the change kept
     * labelling every total with the currency the site had before it.
     */
    public function test_it_follows_a_base_currency_change_without_a_restart(): void
    {
        $this->campaign('one', 'USD', 100000);

        $repo = Plugin::instance()->container->get(CampaignRepository::class);
        $this->assertSame('EUR', $repo->aggregateAdmin()['currency']);

        update_option('fundkit_currency_locale', [
            'default_currency'     => 'GBP',
            'supported_currencies' => ['GBP', 'USD'],
        ]);

        $this->assertSame('GBP', $repo->aggregateAdmin()['currency']);
    }

    /** A site with nothing raised still names a currency rather than none. */
    public function test_an_empty_site_still_names_the_base_currency(): void
    {
        $stats = Plugin::instance()->container->get(CampaignRepository::class)->aggregateAdmin();

        $this->assertSame(0, (int) $stats['raised_cents']);
        $this->assertSame('EUR', $stats['currency']);
    }

    private function campaign(string $slug, string $currency, int $raisedCents): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $c = Campaign::make();
        $c->title        = ucfirst($slug);
        $c->slug         = $slug;
        $c->status       = 'published';
        $c->currency     = $currency;
        $c->raised_cents = $raisedCents;
        $c->created_at   = $now;
        $c->updated_at   = $now;
        $c->save();
    }
}
