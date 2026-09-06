<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Donations\Donation;
use FundKit\Donations\DonationNote;
use FundKit\Donations\DonationNoteRepository;
use FundKit\Donors\DonorNote;
use FundKit\Donors\DonorNoteRepository;
use FundKit\Donors\DonorService;
use FundKit\Foundation\Plugin;
use WP_REST_Request;

/**
 * A note body is stored as AES-GCM ciphertext in a TEXT column, and wpdb runs
 * without STRICT_TRANS_TABLES, so an oversize body truncates rather than
 * erroring. The tag then never verifies, decrypt returns null, and the note
 * reads back as an empty box with an author and a timestamp. The write answered
 * 201 because the response was shaped from the model in memory.
 */
final class NoteLengthCapTest extends IntegrationTestCase
{
    private function admin(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    private function post(string $route, string $body): int
    {
        $req = new WP_REST_Request('POST', $route);
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode(['body' => $body]));

        return rest_do_request($req)->get_status();
    }

    private function donorId(): int
    {
        return (int) Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate('note-' . uniqid() . '@example.test', [])->id;
    }

    private function donation(): Donation
    {
        $d = Donation::make();
        $d->reference    = 'NOTE-' . strtoupper(bin2hex(random_bytes(4)));
        $d->status       = 'paid';
        $d->gateway      = 'offline';
        $d->kind         = 'donation';
        $d->amount_cents = 1000;
        $d->currency     = 'EUR';
        $d->created_at   = gmdate('Y-m-d H:i:s');
        $d->save();

        return $d;
    }

    public function test_an_oversize_donor_note_is_refused_rather_than_truncated(): void
    {
        $this->admin();
        $id = $this->donorId();

        $this->assertSame(400, $this->post("/fundkit/v1/admin/donors/{$id}/notes", str_repeat('a', 60000)));
        $this->assertSame(0, DonorNote::query()->where('donor_id', $id)->count());
    }

    public function test_an_oversize_donation_note_is_refused_rather_than_truncated(): void
    {
        $this->admin();
        $donation = $this->donation();

        $this->assertSame(400, $this->post("/fundkit/v1/admin/donations/{$donation->reference}/notes", str_repeat('a', 60000)));
        $this->assertSame(0, DonationNote::query()->where('donation_id', (int) $donation->id)->count());
    }

    /**
     * Read back through the repository, never from the 201 payload: that is
     * shaped in memory and is exactly what hid the loss.
     */
    public function test_a_donor_note_at_the_cap_reads_back_as_it_was_typed(): void
    {
        $this->admin();
        $id   = $this->donorId();
        $body = str_repeat('b', 12000);

        $this->assertSame(201, $this->post("/fundkit/v1/admin/donors/{$id}/notes", $body));

        $notes = Plugin::instance()->container->get(DonorNoteRepository::class)->listForDonor($id);
        $this->assertSame($body, (string) $notes[0]['body']);
    }

    public function test_a_donation_note_at_the_cap_reads_back_as_it_was_typed(): void
    {
        $this->admin();
        $donation = $this->donation();
        $body     = str_repeat('b', 12000);

        $this->assertSame(201, $this->post("/fundkit/v1/admin/donations/{$donation->reference}/notes", $body));

        $notes = Plugin::instance()->container->get(DonationNoteRepository::class)->listForDonation((int) $donation->id);
        $this->assertSame($body, (string) $notes[0]['body']);
    }

    /**
     * The cap counts characters and the column counts bytes, so the four-byte
     * worst case is what the arithmetic behind the number is chosen for.
     */
    public function test_a_multibyte_note_at_the_cap_reads_back_as_it_was_typed(): void
    {
        $this->admin();
        $id   = $this->donorId();
        $body = str_repeat("\u{1F600}", 12000);

        $this->assertSame(201, $this->post("/fundkit/v1/admin/donors/{$id}/notes", $body));

        $notes = Plugin::instance()->container->get(DonorNoteRepository::class)->listForDonor($id);
        $this->assertSame($body, (string) $notes[0]['body']);
    }
}
