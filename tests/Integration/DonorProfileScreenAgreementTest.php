<?php

declare(strict_types=1);

namespace GiveFlow\Tests\Integration;

use GiveFlow\Donors\DonorNote;
use GiveFlow\Donors\DonorNoteRepository;
use GiveFlow\Donors\DonorService;
use GiveFlow\Foundation\Plugin;
use WP_REST_Request;

/**
 * Three places where the donor profile told an operator something the server
 * would not have agreed with: a delete button on a note the server refuses, a
 * tab badge counting rows the list below it does not hold, and one error
 * message standing in for two unrelated failures.
 */
final class DonorProfileScreenAgreementTest extends IntegrationTestCase
{
    private function donors(): DonorService
    {
        return Plugin::instance()->container->get(DonorService::class);
    }

    private function notes(): DonorNoteRepository
    {
        return Plugin::instance()->container->get(DonorNoteRepository::class);
    }

    /**
     * The screen renders Delete per note; DonorsController::deleteNote refuses
     * another author's unless the user can manage_options. The note now carries
     * the verdict so the two cannot disagree.
     */
    public function test_a_note_the_server_would_refuse_offers_no_delete(): void
    {
        $donor  = $this->donors()->findOrCreate('notes-' . uniqid() . '@example.test', ['first_name' => 'Ada']);
        $mine   = self::factory()->user->create(['role' => 'administrator']);
        $theirs = self::factory()->user->create(['role' => 'administrator']);

        wp_set_current_user($theirs);
        $this->notes()->create((int) $donor->id, 'Written by a colleague', $theirs);

        wp_set_current_user($mine);
        $this->notes()->create((int) $donor->id, 'Written by me', $mine);

        // An editor holds giveflow_edit_donors but not manage_options.
        $editor = self::factory()->user->create(['role' => 'editor']);
        get_user_by('id', $editor)->add_cap('giveflow_edit_donors');
        wp_set_current_user($editor);

        $seen = [];
        foreach ($this->notes()->listForDonor((int) $donor->id) as $note) {
            $seen[$note['body']] = $note['can_delete'];
        }

        $this->assertFalse($seen['Written by a colleague'], 'not theirs to delete');
        $this->assertFalse($seen['Written by me'], 'not theirs either');

        wp_set_current_user($mine);
        $mineSeen = [];
        foreach ($this->notes()->listForDonor((int) $donor->id) as $note) {
            $mineSeen[$note['body']] = $note['can_delete'];
        }

        $this->assertTrue($mineSeen['Written by me'], 'an author can delete their own');
        $this->assertTrue($mineSeen['Written by a colleague'], 'and manage_options can delete any');
    }

    /**
     * The badge came from the capped array's length, so it agreed with the list
     * and with nothing else. It counts the donor's notes now, and the tab says
     * how many of them are on screen.
     */
    public function test_the_notes_badge_counts_notes_the_list_does_not_hold(): void
    {
        $donor = $this->donors()->findOrCreate('many-' . uniqid() . '@example.test', ['first_name' => 'Grace']);
        $user  = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($user);

        for ($i = 0; $i < 55; $i++) {
            $this->notes()->create((int) $donor->id, 'Note ' . $i, $user);
        }

        $this->assertSame(55, $this->notes()->countForDonor((int) $donor->id));
        $this->assertCount(50, $this->notes()->listForDonor((int) $donor->id), 'the list is still capped');

        $req = new WP_REST_Request('GET', '/giveflow/v1/admin/donors/' . (int) $donor->id . '/profile');
        $res = rest_do_request($req);
        $this->assertSame(200, $res->get_status());

        $data = (array) $res->get_data();
        $this->assertSame(55, $data['notes_total'], 'the badge has the real number to show');
        $this->assertCount(50, $data['notes'], 'and the screen knows it is holding fewer');
    }

    /**
     * The button is only rendered for a donor who is not erased, so the erasure
     * message could only ever appear when something else went wrong.
     */
    public function test_an_erased_donor_is_the_only_thing_the_erasure_message_reports(): void
    {
        $donor = $this->donors()->findOrCreate('link-' . uniqid() . '@example.test', ['first_name' => 'Alan']);
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $ok = rest_do_request(new WP_REST_Request('POST', '/giveflow/v1/admin/donors/' . (int) $donor->id . '/portal-link'));
        $this->assertSame(201, $ok->get_status(), 'a live donor gets a link');

        $this->donors()->redact($donor);

        $refused = rest_do_request(new WP_REST_Request('POST', '/giveflow/v1/admin/donors/' . (int) $donor->id . '/portal-link'));
        $this->assertSame(409, $refused->get_status());
        $this->assertSame('giveflow_portal_link_unavailable', $refused->as_error()->get_error_code());
    }
}
