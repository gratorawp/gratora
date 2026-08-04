<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\Donation;
use Dono\Forms\Form;
use WP_REST_Request;

/**
 * note_public puts the donor's text on the campaign's supporter wall. A form
 * with no comment block never offered that, so accepting one from a crafted
 * payload is an unmoderated publish route onto a public page.
 */
final class DonorMessageGateTest extends IntegrationTestCase
{
    private function form(bool $withComment): Form
    {
        $blocks = '<!-- wp:dono/donation-amount /--><!-- wp:dono/email /-->'
            . ($withComment ? '<!-- wp:dono/comment /-->' : '')
            . '<!-- wp:dono/submit-button /-->';

        $f = Form::make();
        $f->title      = 'Gate test';
        $f->status     = 'published';
        $f->blocks     = $blocks;
        $f->created_at = gmdate('Y-m-d H:i:s');
        $f->updated_at = gmdate('Y-m-d H:i:s');
        $f->save();

        return $f;
    }

    private function donate(Form $form, array $extra): ?Donation
    {
        $req = new WP_REST_Request('POST', '/dono/v1/donations');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode($extra + [
            'email'        => 'msg-' . uniqid() . '@example.test',
            'amount_cents' => 2500,
            'currency'     => 'USD',
            'gateway'      => 'offline',
            'form_id'      => (int) $form->id,
        ]));

        $res = rest_do_request($req);
        $ref = $res->get_data()['reference'] ?? null;

        return $ref ? Donation::query()->where('reference', $ref)->get() : null;
    }

    public function test_a_form_with_a_comment_block_keeps_the_message(): void
    {
        $d = $this->donate($this->form(true), [
            'note_to_org' => 'Thanks for the work you do',
            'note_public' => true,
        ]);

        $this->assertNotNull($d);
        $this->assertSame('Thanks for the work you do', (string) $d->note_to_org);
    }

    public function test_a_form_without_one_drops_it(): void
    {
        $d = $this->donate($this->form(false), [
            'note_to_org' => 'Buy crypto at example.test',
            'note_public' => true,
        ]);

        $this->assertNotNull($d);
        $this->assertSame('', (string) $d->note_to_org, 'a form that never asked for a message does not accept one');
    }
}
