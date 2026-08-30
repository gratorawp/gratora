<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Donations\Donation;
use FundKit\Funds\Fund;
use FundKit\Funds\FundResolver;
use FundKit\Funds\FundRepository;
use WP_REST_Request;

/**
 * The fund editor offers a start and an end date. An org that sets one means
 * the fund stops taking money then, so the donor's picker, the resolver that
 * files a donation and the funds list all have to read the window back.
 */
final class FundScheduleWindowTest extends IntegrationTestCase
{
    private int $campaignId;

    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->campaignId = $this->createCampaign();
    }

    private function fund(string $code, string $name, ?string $startsAt, ?string $endsAt): Fund
    {
        $req = new WP_REST_Request('POST', '/fundkit/v1/admin/funds');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode(array_filter([
            'code'      => $code,
            'name'      => $name,
            'starts_at' => $startsAt,
            'ends_at'   => $endsAt,
        ], static fn ($v) => $v !== null)));

        $id = (int) rest_do_request($req)->get_data()['id'];

        return Fund::query()->find('id', $id);
    }

    private function createCampaign(): int
    {
        $req = new WP_REST_Request('POST', '/fundkit/v1/admin/campaigns');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode(['title' => 'Window campaign', 'status' => 'published']));

        return (int) rest_do_request($req)->get_data()['id'];
    }

    private function formWithFundPicker(): array
    {
        $req = new WP_REST_Request('POST', '/fundkit/v1/admin/forms');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode([
            'title'       => 'Pick a fund',
            'campaign_id' => $this->campaignId,
            'blocks'      => '<!-- wp:fundkit/fund-picker /-->'
                . '<!-- wp:fundkit/submit-button {"label":"Give"} /-->',
        ]));
        $created = rest_do_request($req)->get_data();

        $form = \FundKit\Forms\Form::query()->find('id', (int) $created['id']);
        $form->status = 'published';
        $form->save();

        return $created;
    }

    private function yesterday(): string
    {
        return gmdate('Y-m-d', time() - 2 * DAY_IN_SECONDS);
    }

    private function nextMonth(): string
    {
        return gmdate('Y-m-d', time() + 30 * DAY_IN_SECONDS);
    }

    public function test_a_fund_past_its_end_date_is_not_offered_to_the_donor(): void
    {
        $closed = $this->fund('winter-appeal', 'Winter appeal', null, $this->yesterday());
        $open   = $this->fund('general-giving', 'General giving', null, null);

        $form = $this->formWithFundPicker();
        $html = do_shortcode('[fundkit_donation_form slug="' . $form['slug'] . '"]');

        $this->assertStringContainsString('General giving', $html, 'the fund still running is still on the form');
        $this->assertStringNotContainsString('Winter appeal', $html, 'the fund that ended is not a choice');
        $this->assertNotSame((int) $closed->id, (int) $open->id);
    }

    public function test_a_fund_that_has_not_started_is_not_offered_to_the_donor(): void
    {
        $this->fund('spring-appeal', 'Spring appeal', $this->nextMonth(), null);
        $this->fund('general-giving', 'General giving', null, null);

        $form = $this->formWithFundPicker();
        $html = do_shortcode('[fundkit_donation_form slug="' . $form['slug'] . '"]');

        $this->assertStringNotContainsString('Spring appeal', $html);
    }

    public function test_a_donation_naming_a_closed_fund_is_not_filed_against_it(): void
    {
        $closed = $this->fund('winter-appeal', 'Winter appeal', null, $this->yesterday());
        $open   = $this->fund('general-giving', 'General giving', null, null);

        $resolved = (new FundResolver(new FundRepository()))->resolve((int) $closed->id, null, null);

        $this->assertNotSame((int) $closed->id, $resolved, 'the fund stopped taking donations on its end date');
        $this->assertSame((int) $open->id, $resolved);
    }

    public function test_a_donation_lands_on_a_fund_inside_its_window(): void
    {
        $open = $this->fund('winter-appeal', 'Winter appeal', $this->yesterday(), $this->nextMonth());

        $create = new WP_REST_Request('POST', '/fundkit/v1/donations');
        $create->set_header('content-type', 'application/json');
        $create->set_body((string) wp_json_encode([
            'email'        => 'window.donor@example.org',
            'amount_cents' => 2500,
            'currency'     => 'USD',
            'gateway'      => 'offline',
            'fund_id'      => (int) $open->id,
        ]));
        $reference = (string) rest_do_request($create)->get_data()['reference'];

        $donation = Donation::query()->find('reference', $reference);
        $this->assertSame((int) $open->id, (int) $donation->fund_id);
    }

    public function test_the_funds_list_says_a_scheduled_fund_is_not_taking_donations_yet(): void
    {
        $this->fund('spring-appeal', 'Spring appeal', $this->nextMonth(), null);
        $this->fund('winter-appeal', 'Winter appeal', null, $this->yesterday());

        $rows = [];
        foreach ((array) rest_do_request(new WP_REST_Request('GET', '/fundkit/v1/admin/funds'))->get_data() as $row) {
            $rows[$row['code']] = $row;
        }

        $this->assertTrue($rows['spring-appeal']['is_active'], 'nobody deactivated it');
        $this->assertSame('scheduled', $rows['spring-appeal']['schedule_state']);
        $this->assertSame('ended', $rows['winter-appeal']['schedule_state']);
    }
}
