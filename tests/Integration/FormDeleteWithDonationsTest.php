<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Campaigns\Campaign;
use FundKit\Donations\Donation;
use FundKit\Forms\Form;
use FundKit\Forms\FormService;
use FundKit\Foundation\Plugin;
use WP_REST_Request;

/**
 * A form's donation rows keep pointing at form_id after the row goes: the
 * per-form breakdown can no longer name them, the donations list shows a blank
 * form for each, and the stats row holding that form's raised total stays in
 * the table with no form to belong to. The campaign the form sits under already
 * refuses the same delete, so an operator has no reason to expect this one to
 * succeed.
 */
final class FormDeleteWithDonationsTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    private function form(): Form
    {
        $now = gmdate('Y-m-d H:i:s');

        $campaign = Campaign::make();
        $campaign->title      = 'Delete probe ' . uniqid();
        $campaign->slug       = 'delete-probe-' . uniqid();
        $campaign->status     = 'published';
        $campaign->created_at = $now;
        $campaign->updated_at = $now;
        $campaign->save();

        $form = Form::make();
        $form->title       = 'Second form';
        $form->slug        = 'second-' . uniqid();
        $form->status      = 'published';
        $form->blocks      = '';
        $form->campaign_id = (int) $campaign->id;
        $form->created_at  = $now;
        $form->updated_at  = $now;
        $form->save();

        return $form;
    }

    private function donationOn(Form $form): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $d   = Donation::make();
        $d->reference         = 'FUNDKIT-FORMDEL-' . uniqid();
        $d->donor_id          = 1;
        $d->form_id           = (int) $form->id;
        $d->campaign_id       = (int) $form->campaign_id;
        $d->amount_cents      = 2500;
        $d->net_cents         = 2500;
        $d->currency          = 'USD';
        $d->base_amount_cents = 2500;
        $d->base_currency     = 'USD';
        $d->fx_rate           = '1.00000000';
        $d->gateway           = 'offline';
        $d->status            = 'paid';
        $d->is_test           = false;
        $d->paid_at           = $now;
        $d->created_at        = $now;
        $d->updated_at        = $now;
        $d->save();
    }

    private function service(): FormService
    {
        return Plugin::instance()->container->get(FormService::class);
    }

    public function test_a_form_that_took_donations_is_not_deleted(): void
    {
        $form = $this->form();
        $this->donationOn($form);

        $this->expectException(\RuntimeException::class);
        $this->service()->delete($form);
    }

    public function test_its_donations_are_still_reachable_afterwards(): void
    {
        $form = $this->form();
        $this->donationOn($form);

        try {
            $this->service()->delete($form);
        } catch (\RuntimeException $e) {
            // expected
        }

        $this->assertNotNull(Form::query()->where('id', (int) $form->id)->get());
        $this->assertSame(
            1,
            (int) Donation::query()->where('form_id', (int) $form->id)->count(),
            'the donations would have been left pointing at nothing'
        );
    }

    /** A test-mode donation is still a record that would be orphaned. */
    public function test_even_a_test_donation_holds_the_form(): void
    {
        $form = $this->form();
        $now  = gmdate('Y-m-d H:i:s');
        $d    = Donation::make();
        $d->reference   = 'FUNDKIT-FORMDEL-T-' . uniqid();
        $d->donor_id    = 1;
        $d->form_id     = (int) $form->id;
        $d->amount_cents = 100;
        $d->net_cents    = 100;
        $d->currency     = 'USD';
        $d->base_amount_cents = 100;
        $d->base_currency = 'USD';
        $d->fx_rate      = '1.00000000';
        $d->gateway      = 'offline';
        $d->status       = 'failed';
        $d->is_test      = true;
        $d->created_at   = $now;
        $d->updated_at   = $now;
        $d->save();

        $this->expectException(\RuntimeException::class);
        $this->service()->delete($form);
    }

    /** A form nobody gave through is still deletable. */
    public function test_an_unused_form_is_still_deleted(): void
    {
        $form = $this->form();

        $this->service()->delete($form);

        $this->assertNull(Form::query()->where('id', (int) $form->id)->get());
    }

    /** The route explains it rather than answering 500. */
    public function test_the_route_says_why(): void
    {
        $form = $this->form();
        $this->donationOn($form);

        $req = new WP_REST_Request('DELETE', '/fundkit/v1/admin/forms/' . (int) $form->id);
        $req->set_param('id', (int) $form->id);
        $res = rest_do_request($req);

        $this->assertSame(422, $res->get_status());
        $this->assertSame('fundkit_form_delete_blocked', (string) $res->get_data()['code']);
    }
}
