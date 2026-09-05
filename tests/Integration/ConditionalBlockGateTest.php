<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Donations\Donation;
use FundKit\Forms\Form;
use FundKit\Funds\Fund;
use WP_REST_Request;

/**
 * A block hidden by its own display condition submits nothing, and the
 * validator returns before its rules run. The gates that decide whether a
 * fund choice or a public message was offered asked only whether the block
 * existed, so a crafted payload could send a value the block would never have
 * accepted: a fund picker the donor never saw routed money to a restricted
 * fund the form never listed.
 */
final class ConditionalBlockGateTest extends IntegrationTestCase
{
    private int $listed = 0;
    private int $hidden = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->listed = $this->fund('Listed appeal');
        $this->hidden = $this->fund('Restricted endowment');
    }

    private function fund(string $name): int
    {
        $now  = gmdate('Y-m-d H:i:s');
        $fund = Fund::make();
        $fund->name       = $name . ' ' . uniqid();
        $fund->code       = 'f' . substr(uniqid(), -8);
        $fund->is_active  = true;
        $fund->created_at = $now;
        $fund->updated_at = $now;
        $fund->save();

        return (int) $fund->id;
    }

    /** A picker shown only when the donor asks to designate their gift. */
    private function form(): Form
    {
        $picker = wp_json_encode([
            'fundIds'   => [ $this->listed ],
            'condition' => [ 'field' => 'custom.designate', 'op' => '=', 'value' => 'yes' ],
        ]);

        $f = Form::make();
        $f->title      = 'Conditional picker';
        $f->status     = 'published';
        $f->blocks     = '<!-- wp:fundkit/donation-amount /--><!-- wp:fundkit/email /-->'
            . '<!-- wp:fundkit/fund-picker ' . $picker . ' /-->'
            . '<!-- wp:fundkit/submit-button /-->';
        $f->created_at = gmdate('Y-m-d H:i:s');
        $f->updated_at = gmdate('Y-m-d H:i:s');
        $f->save();

        return $f;
    }

    /** @return array{status:int, donation:?Donation} */
    private function donate(Form $form, array $extra): array
    {
        $req = new WP_REST_Request('POST', '/fundkit/v1/donations');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode($extra + [
            'email'        => 'gate-' . uniqid() . '@example.test',
            'amount_cents' => 2500,
            'currency'     => 'USD',
            'gateway'      => 'offline',
            'form_id'      => (int) $form->id,
        ]));

        $res = rest_do_request($req);
        $ref = $res->get_data()['reference'] ?? null;

        return [
            'status'   => $res->get_status(),
            'donation' => $ref ? Donation::query()->where('reference', $ref)->get() : null,
        ];
    }

    public function test_a_picker_the_donor_never_saw_cannot_route_the_money(): void
    {
        $out = $this->donate($this->form(), [
            'custom'  => [ 'designate' => 'no' ],
            'fund_id' => $this->hidden,
        ]);

        $this->assertNotNull($out['donation'], 'the donation itself is fine, only the fund is not the caller\'s to send');
        $this->assertNotSame(
            $this->hidden,
            (int) $out['donation']->fund_id,
            'a restricted fund the form never listed took the money'
        );
    }

    public function test_the_picker_still_works_when_the_donor_was_shown_it(): void
    {
        $out = $this->donate($this->form(), [
            'custom'  => [ 'designate' => 'yes' ],
            'fund_id' => $this->listed,
        ]);

        $this->assertSame($this->listed, (int) $out['donation']->fund_id);
    }

    /** The allow-list still refuses a fund the shown picker does not list. */
    public function test_a_shown_picker_still_refuses_a_fund_it_does_not_list(): void
    {
        $out = $this->donate($this->form(), [
            'custom'  => [ 'designate' => 'yes' ],
            'fund_id' => $this->hidden,
        ]);

        $this->assertSame(400, $out['status']);
        $this->assertNull($out['donation']);
    }

    /** The same rule on the message block, which publishes to the supporter wall. */
    public function test_a_hidden_comment_block_does_not_accept_a_public_message(): void
    {
        $comment = wp_json_encode([ 'condition' => [ 'field' => 'custom.designate', 'op' => '=', 'value' => 'yes' ] ]);

        $f = Form::make();
        $f->title      = 'Conditional comment';
        $f->status     = 'published';
        $f->blocks     = '<!-- wp:fundkit/donation-amount /--><!-- wp:fundkit/email /-->'
            . '<!-- wp:fundkit/comment ' . $comment . ' /-->'
            . '<!-- wp:fundkit/submit-button /-->';
        $f->created_at = gmdate('Y-m-d H:i:s');
        $f->updated_at = gmdate('Y-m-d H:i:s');
        $f->save();

        $out = $this->donate($f, [
            'custom'      => [ 'designate' => 'no' ],
            'note_to_org' => 'Buy crypto at example.test',
            'note_public' => true,
        ]);

        $this->assertSame('', (string) $out['donation']->note_to_org);
    }

    /** An unconditional block is unchanged, which is every shipped form. */
    public function test_an_unconditional_picker_is_untouched(): void
    {
        $picker = wp_json_encode([ 'fundIds' => [ $this->listed ] ]);

        $f = Form::make();
        $f->title      = 'Plain picker';
        $f->status     = 'published';
        $f->blocks     = '<!-- wp:fundkit/donation-amount /--><!-- wp:fundkit/email /-->'
            . '<!-- wp:fundkit/fund-picker ' . $picker . ' /-->'
            . '<!-- wp:fundkit/submit-button /-->';
        $f->created_at = gmdate('Y-m-d H:i:s');
        $f->updated_at = gmdate('Y-m-d H:i:s');
        $f->save();

        $out = $this->donate($f, [ 'fund_id' => $this->listed ]);

        $this->assertSame($this->listed, (int) $out['donation']->fund_id);
    }
}
