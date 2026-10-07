<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Campaigns\Campaign;
use Gratora\Campaigns\CampaignService;
use Gratora\Cli\DemoSeeder;
use Gratora\Dashboard\FirstRun;
use Gratora\Donations\AggregateSyncer;
use Gratora\Donations\Donation;
use Gratora\Donations\DonationDeleter;
use Gratora\Donations\DonationIntent;
use Gratora\Donations\DonationService;
use Gratora\Donors\DonorService;
use Gratora\Foundation\Plugin;
use Gratora\Foundation\Time\Clock;
use Gratora\Funds\FundService;
use Gratora\Recurring\RecurringPlanRepository;
use WP_REST_Request;

/**
 * Until a site takes its first real donation the dashboard carries a card that
 * says what is left. Every test starts from a site as a new install leaves it:
 * test mode on, nothing connected, no campaign.
 */
final class TheDashboardSaysWhatIsLeftBeforeAFirstDonationTest extends IntegrationTestCase
{
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        update_option('gratora_gateway_config', ['test_mode' => true]);
    }

    public function test_a_new_site_has_no_page_no_test_donation_and_no_way_to_take_money(): void
    {
        $this->assertSame([
            'page'               => 'none',
            'page_title'         => null,
            'page_url'           => null,
            'test_mode'          => true,
            'test_donation'      => null,
            'test_donation_made' => false,
            'payments'           => false,
            'payment_methods'    => [],
        ], $this->card());
    }

    /** @return array<string, array{0: array<string,mixed>}> */
    public function campaignsNoDonorCanGiveTo(): array
    {
        return [
            'a draft'                     => [['status' => 'draft']],
            'one whose form is a draft'   => [['status' => 'published', 'skip_template' => true]],
            'one that ended last year'    => [['status' => 'published', 'ends_at' => '2025-01-31']],
        ];
    }

    /**
     * @dataProvider campaignsNoDonorCanGiveTo
     *
     * @param array<string,mixed> $with
     */
    public function test_a_campaign_no_donor_can_give_to_is_told_apart_from_none(array $with): void
    {
        $this->campaign(['title' => 'Winter food drive'] + $with);

        $card = $this->card();

        $this->assertSame('closed', $card['page']);
        $this->assertNull($card['page_title']);
        $this->assertNull($card['page_url']);
    }

    public function test_the_page_offered_is_one_that_will_take_the_donation(): void
    {
        $this->campaign(['title' => 'Last year', 'status' => 'published', 'ends_at' => '2025-01-31']);
        $open = $this->campaign(['title' => 'This year', 'status' => 'published']);

        $card = $this->card();

        $this->assertSame('live', $card['page']);
        $this->assertSame('This year', $card['page_title']);
        $this->assertSame(get_permalink((int) $open->page_id), $card['page_url']);
    }

    public function test_a_live_page_is_named_and_linked(): void
    {
        $campaign = $this->campaign(['title' => 'Winter food drive', 'status' => 'published']);

        $card = $this->card();

        $this->assertSame('live', $card['page']);
        $this->assertSame('Winter food drive', $card['page_title']);
        $this->assertSame(get_permalink((int) $campaign->page_id), $card['page_url']);
    }

    public function test_of_two_live_pages_the_older_one_is_shown(): void
    {
        $this->campaign(['title' => 'The first one', 'status' => 'published']);
        $this->campaign(['title' => 'A later one', 'status' => 'published']);

        $this->assertSame('The first one', $this->card()['page_title']);
    }

    public function test_the_newest_paid_test_donation_is_shown(): void
    {
        $this->donation(['is_test' => true, 'amount_cents' => 1000, 'donor' => ['Ada', 'Lovelace']]);
        $this->donation(['is_test' => true, 'amount_cents' => 2603, 'donor' => ['Maya', 'Chen']]);

        $this->assertSame([
            'amount_cents' => 2603,
            'currency'     => 'USD',
            'donor'        => 'Maya Chen',
            'url'          => admin_url('admin.php?page=gratora-donations&include_test=1'),
        ], $this->card()['test_donation']);
    }

    public function test_a_test_donation_that_was_never_paid_is_not_shown(): void
    {
        $this->donation(['is_test' => true, 'status' => 'pending']);
        $this->donation(['is_test' => true, 'status' => 'failed']);

        $this->assertNull($this->card()['test_donation']);
    }

    public function test_a_test_donation_given_without_a_name_shows_none(): void
    {
        $this->donation(['is_test' => true, 'is_anonymous' => true]);

        $this->assertNull($this->card()['test_donation']['donor']);
    }

    /** @return array<string, array{0: array<string,mixed>}> */
    public function paidRowsThatAreNotATestDonation(): array
    {
        return [
            'a real donation'              => [[]],
            'a ticket bought in test mode' => [['is_test' => true, 'kind' => 'order']],
            'a test donation since binned' => [['is_test' => true, 'trashed_at' => '2026-10-01 09:00:00']],
        ];
    }

    /**
     * Read from what the wizard is given, which answers whether or not the
     * card is still due.
     *
     * @dataProvider paidRowsThatAreNotATestDonation
     *
     * @param array<string,mixed> $with
     */
    public function test_only_a_test_donation_is_shown_as_one(array $with): void
    {
        $this->donation($with);

        $finished = rest_do_request(new WP_REST_Request('POST', '/gratora/v1/admin/onboarding/finalize'))->get_data();

        $this->assertNull($finished['first_run']['test_donation']);
        $this->assertFalse($finished['first_run']['test_donation_made']);
    }

    /**
     * The step a test donation finishes stays finished. Tidying the donation
     * away is the next thing a newcomer does with it, and a card that then asks
     * for another has forgotten what it saw.
     */
    public function test_a_test_donation_that_came_in_still_counts_once_it_is_deleted(): void
    {
        $donation = $this->given(new DonationIntent(
            email:        'ada@example.test',
            amount_cents: 2500,
            currency:     'USD',
            gateway:      'offline',
        ));

        Plugin::instance()->container->get(DonationDeleter::class)->delete($donation, null, false);

        $card = $this->card();

        $this->assertNull($card['test_donation']);
        $this->assertTrue($card['test_donation_made']);
    }

    // Written straight in, as one given before anything was remembered.
    public function test_one_the_card_has_shown_still_counts_once_it_is_gone(): void
    {
        $this->donation(['is_test' => true]);
        $this->assertTrue($this->card()['test_donation_made']);

        Donation::query()->where('is_test', 1)->delete();

        $card = $this->card();

        $this->assertNull($card['test_donation']);
        $this->assertTrue($card['test_donation_made']);
    }

    public function test_a_real_donation_coming_in_is_not_remembered_as_a_test(): void
    {
        $this->given(new DonationIntent(
            email:        'ada@example.test',
            amount_cents: 2500,
            currency:     'USD',
            gateway:      'offline',
            is_test:      false,
        ));

        $this->assertFalse($this->wizardFacts()['test_donation_made']);
    }

    public function test_a_ticket_bought_in_test_mode_is_not_remembered_as_a_test_donation(): void
    {
        $this->given(new DonationIntent(
            email:        'ada@example.test',
            amount_cents: 2500,
            currency:     'USD',
            gateway:      'offline',
            kind:         'order',
        ));

        $this->assertFalse($this->wizardFacts()['test_donation_made']);
    }

    /** A donation taken the way the form takes one: asked for, then paid. */
    private function given(DonationIntent $intent): Donation
    {
        $service = Plugin::instance()->container->get(DonationService::class);

        return $service->confirm($service->createPending($intent)['donation'], ['gateway_txn_id' => 'txn-' . ++$this->seq]);
    }

    /**
     * The facts as the wizard is given them, which answers whether or not the
     * card is still due.
     *
     * @return array<string, mixed>
     */
    private function wizardFacts(): array
    {
        return rest_do_request(new WP_REST_Request('POST', '/gratora/v1/admin/onboarding/finalize'))->get_data()['first_run'];
    }

    public function test_payments_are_ready_once_bank_details_are_written(): void
    {
        update_option('gratora_gateway_config', [
            'test_mode' => true,
            'offline'   => ['bank_details' => 'IBAN HR12 1001 0051 8630 0016 0'],
        ]);

        $card = $this->card();

        $this->assertTrue($card['payments']);
        $this->assertSame(['Offline donations'], $card['payment_methods']);
    }

    /** @return array<string, array{0: array<string,mixed>, 1: ?string}> */
    public function sitesTheSetupTabAlsoJudges(): array
    {
        $bank = ['offline' => ['bank_details' => 'IBAN HR12 1001 0051 8630 0016 0']];

        return [
            'a new site'                         => [['test_mode' => true], null],
            'bank details, still in test mode'   => [['test_mode' => true] + $bank, null],
            'bank details and a draft campaign'  => [['test_mode' => true] + $bank, 'draft'],
            'a page and nothing to take money'   => [['test_mode' => true], 'published'],
            'a page and bank details, test mode' => [['test_mode' => true] + $bank, 'published'],
            'no page, test mode off'             => [['test_mode' => false] + $bank, null],
        ];
    }

    /**
     * The card links to the Setup tab as the full check, so the two have to
     * give one answer about the page and one about payments.
     *
     * @dataProvider sitesTheSetupTabAlsoJudges
     *
     * @param array<string,mixed> $payments
     */
    public function test_the_card_and_the_setup_tab_give_one_answer(array $payments, ?string $campaign): void
    {
        update_option('gratora_gateway_config', $payments);
        if ($campaign !== null) {
            $this->campaign(['title' => 'Winter food drive', 'status' => $campaign]);
        }

        $card  = $this->card();
        $setup = [];
        foreach (rest_do_request(new WP_REST_Request('GET', '/gratora/v1/admin/readiness'))->get_data()['checks'] as $row) {
            $setup[$row['id']] = $row['status'];
        }

        $this->assertSame($card['payments'], $setup['gateway'] === 'pass', 'payments');
        $this->assertSame($card['page'] === 'live', $setup['donation-page'] === 'pass', 'the page');
    }

    public function test_it_says_when_test_mode_is_off(): void
    {
        update_option('gratora_gateway_config', ['test_mode' => false]);

        $this->assertFalse($this->card()['test_mode']);
    }

    public function test_a_real_donation_given_on_the_site_ends_it(): void
    {
        $this->donation();

        $this->assertNull($this->dashboard()['first_run']);
    }

    /** @return array<string, array{0: array<string,mixed>}> */
    public function donationsThatAreNotARealOneGivenHere(): array
    {
        return [
            'a test donation'          => [['is_test' => true]],
            'one that was never paid'  => [['status' => 'pending']],
            'one an import brought'    => [['gateway' => 'imported']],
            'one an admin recorded'    => [['source_attribution' => ['utm_medium' => 'manual']]],
        ];
    }

    /**
     * @dataProvider donationsThatAreNotARealOneGivenHere
     *
     * @param array<string,mixed> $with
     */
    public function test_anything_short_of_a_real_donation_given_here_does_not_end_it(array $with): void
    {
        $this->donation($with);

        $this->assertIsArray($this->dashboard()['first_run']);
    }

    // The public demo is this site: a year of sample giving, with test mode on.
    public function test_a_site_filled_with_sample_data_is_not_shown_it(): void
    {
        $c = Plugin::instance()->container;

        (new DemoSeeder(
            $c->get(DonationService::class),
            $c->get(DonorService::class),
            $c->get(CampaignService::class),
            $c->get(FundService::class),
            $c->get(AggregateSyncer::class),
            $c->get(RecurringPlanRepository::class),
            $c->get(Clock::class),
        ))->run(static fn (string $line) => null);

        $this->assertNull($this->dashboard()['first_run']);
    }

    public function test_a_site_that_is_live_no_longer_sees_it(): void
    {
        $this->campaign(['title' => 'Winter food drive', 'status' => 'published']);
        update_option('gratora_gateway_config', [
            'test_mode' => false,
            'offline'   => ['bank_details' => 'IBAN HR12 1001 0051 8630 0016 0'],
        ]);

        $this->assertNull($this->dashboard()['first_run']);
    }

    /** @return array<string, array{0: bool, 1: array<string,mixed>}> */
    public function sitesOneStepShortOfLive(): array
    {
        $ready = ['offline' => ['bank_details' => 'IBAN HR12 1001 0051 8630 0016 0']];

        return [
            'no page'               => [false, ['test_mode' => false] + $ready],
            'nothing to take money' => [true, ['test_mode' => false]],
            'test mode still on'    => [true, ['test_mode' => true] + $ready],
        ];
    }

    /**
     * @dataProvider sitesOneStepShortOfLive
     *
     * @param array<string,mixed> $payments
     */
    public function test_a_site_one_step_short_of_live_still_sees_it(bool $page, array $payments): void
    {
        if ($page) {
            $this->campaign(['title' => 'Winter food drive', 'status' => 'published']);
        }
        update_option('gratora_gateway_config', $payments);

        $this->assertIsArray($this->dashboard()['first_run']);
    }

    public function test_hiding_it_is_for_that_person_only(): void
    {
        $request = new WP_REST_Request('POST', '/gratora/v1/admin/me/first-run');
        $this->assertSame(200, rest_do_request($request)->get_status());

        $this->assertNull($this->dashboard()['first_run']);

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->assertIsArray($this->dashboard()['first_run'], 'A colleague has not hidden it.');
    }

    public function test_someone_who_only_reads_the_reports_does_not_see_it(): void
    {
        $reader = self::factory()->user->create(['role' => 'editor']);
        get_userdata($reader)->add_cap('gratora_view_reports');
        wp_set_current_user($reader);

        $this->assertNull($this->dashboard()['first_run']);
    }

    /**
     * The first step makes a campaign. Someone who may not is not shown a
     * button that would refuse them.
     */
    public function test_someone_who_may_not_create_campaigns_does_not_see_it(): void
    {
        wp_set_current_user($this->someoneWho(['gratora_view_reports', 'gratora_manage_settings']));

        $this->assertNull($this->dashboard()['first_run']);
    }

    public function test_someone_who_may_not_open_donations_is_given_no_link_to_one(): void
    {
        $this->donation(['is_test' => true]);
        wp_set_current_user($this->someoneWho(['gratora_view_reports', 'gratora_manage_settings', 'gratora_manage_campaigns']));

        $given = $this->card()['test_donation'];

        $this->assertSame(2500, $given['amount_cents']);
        $this->assertNull($given['url']);
    }

    /** @param list<string> $capabilities */
    private function someoneWho(array $capabilities): int
    {
        $person = self::factory()->user->create(['role' => 'editor']);
        foreach ($capabilities as $capability) {
            get_userdata($person)->add_cap($capability);
        }

        return $person;
    }

    public function test_the_wizard_s_last_screen_is_given_the_same_facts(): void
    {
        $this->campaign(['title' => 'Winter food drive', 'status' => 'published']);
        $card = $this->card();

        $finished = rest_do_request(new WP_REST_Request('POST', '/gratora/v1/admin/onboarding/finalize'))->get_data();

        $this->assertSame($card, $finished['first_run']);
    }

    public function test_the_wizard_is_given_the_facts_even_by_someone_who_hid_the_card(): void
    {
        rest_do_request(new WP_REST_Request('POST', '/gratora/v1/admin/me/first-run'));

        $finished = rest_do_request(new WP_REST_Request('POST', '/gratora/v1/admin/onboarding/finalize'))->get_data();

        $this->assertSame('none', $finished['first_run']['page']);
    }

    public function test_a_new_site_has_taken_none_of_the_steps(): void
    {
        $this->assertSame(
            ['page' => false, 'test_donation' => false, 'payments' => false, 'donation' => false],
            $this->progress()
        );
    }

    public function test_a_campaign_counts_as_a_page_made_even_while_it_is_a_draft(): void
    {
        $this->campaign(['title' => 'Spring appeal', 'status' => 'draft']);

        $this->assertSame(
            ['page' => true, 'test_donation' => false, 'payments' => false, 'donation' => false],
            $this->progress()
        );
    }

    public function test_a_test_donation_counts_as_a_step_and_not_as_a_donor_giving(): void
    {
        $this->donation(['is_test' => true]);

        $this->assertSame(
            ['page' => false, 'test_donation' => true, 'payments' => false, 'donation' => false],
            $this->progress()
        );
    }

    public function test_a_way_to_take_real_money_counts_as_a_step(): void
    {
        update_option('gratora_gateway_config', [
            'test_mode' => true,
            'offline'   => ['bank_details' => 'IBAN HR12 1001 0051 8630 0016 0'],
        ]);

        $this->assertSame(
            ['page' => false, 'test_donation' => false, 'payments' => true, 'donation' => false],
            $this->progress()
        );
    }

    public function test_a_donor_giving_on_the_site_counts_as_the_last_step(): void
    {
        $this->donation();

        $this->assertSame(
            ['page' => false, 'test_donation' => false, 'payments' => false, 'donation' => true],
            $this->progress()
        );
    }

    /** @return array{page:bool,test_donation:bool,payments:bool,donation:bool} */
    private function progress(): array
    {
        return Plugin::instance()->container->get(FirstRun::class)->progress();
    }

    /** @return array<string, mixed> */
    private function dashboard(): array
    {
        $request = new WP_REST_Request('GET', '/gratora/v1/admin/dashboard');
        $request->set_param('include', '');

        return rest_do_request($request)->get_data();
    }

    /** @return array<string, mixed> */
    private function card(): array
    {
        $card = $this->dashboard()['first_run'];
        $this->assertIsArray($card, 'the card is due');

        return $card;
    }

    /** @param array<string,mixed> $input */
    private function campaign(array $input): Campaign
    {
        return Plugin::instance()->container->get(CampaignService::class)->create($input);
    }

    /** @param array<string, mixed> $with */
    private function donation(array $with = []): void
    {
        [$first, $last] = $with['donor'] ?? ['Ada', 'Lovelace'];
        $donor = Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate(strtolower($first) . '@example.test', ['first_name' => $first, 'last_name' => $last]);

        $cents = (int) ($with['amount_cents'] ?? 2500);
        $now   = gmdate('Y-m-d H:i:s');

        $d = Donation::make();
        $d->reference         = 'DN-FIRST-RUN-' . ++$this->seq;
        $d->donor_id          = (int) $donor->id;
        $d->amount_cents      = $cents;
        $d->net_cents         = $cents;
        $d->currency          = 'USD';
        $d->base_amount_cents = $cents;
        $d->base_currency     = 'USD';
        $d->fx_rate           = '1.00000000';
        $d->gateway           = (string) ($with['gateway'] ?? 'offline');
        $d->status            = (string) ($with['status'] ?? 'paid');
        $d->is_test           = (bool) ($with['is_test'] ?? false);
        $d->is_anonymous      = (bool) ($with['is_anonymous'] ?? false);
        $d->kind              = (string) ($with['kind'] ?? 'donation');
        $d->trashed_at        = $with['trashed_at'] ?? null;
        $d->source_attribution = $with['source_attribution'] ?? ['landing' => 'https://example.org/donate/'];
        $d->paid_at           = $now;
        $d->created_at        = $now;
        $d->updated_at        = $now;
        $d->save();
    }
}
