<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Campaigns\Campaign;
use Gratora\Donations\Donation;
use Gratora\Forms\Form;
use Gratora\Forms\FormService;
use Gratora\Foundation\Plugin;
use WP_REST_Request;

/**
 * Deleting a form is refused when its records hold it in place, and the screen
 * was asking afterwards.
 *
 * Every form with a donation was offered the action, took the operator through
 * a confirmation saying only that it could not be undone, and then failed with
 * the real reason arriving as an error above the table. The server has always
 * known; the row did not carry the answer.
 */
final class AFormSaysWhyItCannotGoTest extends IntegrationTestCase
{
    private function campaign(): Campaign
    {
        $now = gmdate('Y-m-d H:i:s');

        $c = Campaign::make();
        $c->title      = 'Campaign ' . bin2hex(random_bytes(3));
        $c->slug       = 'c-' . bin2hex(random_bytes(4));
        $c->status     = 'published';
        $c->created_at = $now;
        $c->updated_at = $now;
        $c->save();

        return $c;
    }

    private function form(int $campaignId): Form
    {
        $now = gmdate('Y-m-d H:i:s');

        $f = Form::make();
        $f->title       = 'Form ' . bin2hex(random_bytes(3));
        $f->slug        = 'f-' . bin2hex(random_bytes(4));
        $f->status      = 'published';
        $f->campaign_id = $campaignId;
        $f->created_at  = $now;
        $f->updated_at  = $now;
        $f->save();

        return $f;
    }

    private function donationOn(int $formId): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $d = Donation::make();
        $d->reference    = 'DON-' . strtoupper(bin2hex(random_bytes(4)));
        $d->donor_id     = 1;
        $d->form_id      = $formId;
        $d->kind         = 'donation';
        $d->status       = 'paid';
        $d->amount_cents = 2500;
        $d->currency     = 'USD';
        $d->paid_at      = $now;
        $d->created_at   = $now;
        $d->updated_at   = $now;
        $d->save();
    }

    /** @return array<string,mixed>|null */
    private function rowFor(int $formId): ?array
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $res = rest_do_request(new WP_REST_Request('GET', '/gratora/v1/admin/forms'));

        foreach ((array) $res->get_data() as $row) {
            if ((int) $row['id'] === $formId) {
                return $row;
            }
        }

        return null;
    }

    public function test_a_form_with_nothing_against_it_can_go(): void
    {
        $form = $this->form((int) $this->campaign()->id);

        $row = $this->rowFor((int) $form->id);

        $this->assertTrue($row['deletable']);
        $this->assertNull($row['delete_blocked']);
    }

    public function test_a_form_its_donations_hold_says_so_on_the_row(): void
    {
        $form = $this->form((int) $this->campaign()->id);
        $this->donationOn((int) $form->id);

        $row = $this->rowFor((int) $form->id);

        $this->assertFalse($row['deletable']);
        $this->assertStringContainsString('donations', (string) $row['delete_blocked']);
    }

    /** And the reason names something that can actually be done about it. */
    public function test_the_refusal_names_a_way_out(): void
    {
        $form = $this->form((int) $this->campaign()->id);
        $this->donationOn((int) $form->id);

        $this->assertStringContainsString('draft', (string) $this->rowFor((int) $form->id)['delete_blocked']);
    }

    public function test_the_campaign_default_says_so_too(): void
    {
        $campaign = $this->campaign();
        $form     = $this->form((int) $campaign->id);

        Campaign::query()->where('id', (int) $campaign->id)->update(['default_form_id' => (int) $form->id]);

        $row = $this->rowFor((int) $form->id);

        $this->assertFalse($row['deletable']);
        $this->assertStringContainsString('default', (string) $row['delete_blocked']);
    }

    /** The row's answer is the one the route enforces, not a second opinion. */
    public function test_the_row_agrees_with_what_the_route_does(): void
    {
        $campaign = $this->campaign();
        $blocked  = $this->form((int) $campaign->id);
        $this->donationOn((int) $blocked->id);
        $free = $this->form((int) $campaign->id);

        $service = Plugin::instance()->container->get(FormService::class);

        $this->assertSame($this->rowFor((int) $blocked->id)['delete_blocked'], $service->deleteRefusal($blocked));
        $this->assertNull($service->deleteRefusal($free));

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $res = rest_do_request(new WP_REST_Request('DELETE', '/gratora/v1/admin/forms/' . (int) $blocked->id));

        $this->assertSame(422, $res->get_status());
        $this->assertNotNull(Form::query()->where('id', (int) $blocked->id)->get(), 'and it is still there');
    }
}
