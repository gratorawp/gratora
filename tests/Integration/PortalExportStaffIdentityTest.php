<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donors\DonorMetricsService;
use Dono\Donors\DonorNoteRepository;
use Dono\Donors\DonorService;
use Dono\Foundation\Plugin;
use WP_REST_Request;

/**
 * "Download my data" is self-service, gated on nothing but a magic link, and
 * the bundle it streams is built from the org-side export. Staff notes are the
 * organization's working record of a donor, kept so its people can talk to each
 * other, and they are not handed to the person they discuss. Nobody reviews one
 * before the donor could download it.
 */
final class PortalExportStaffIdentityTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        unset($_COOKIE['dono_donor_session']);
        parent::tearDown();
    }

    /** @return array{0:int,1:int} donor id, author user id */
    private function donorWithStaffNote(): array
    {
        $donor = Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate('note-subject-' . uniqid() . '@example.test');

        $author = self::factory()->user->create([
            'role'         => 'editor',
            'display_name' => 'Jane Smith',
        ]);

        Plugin::instance()->container->get(DonorNoteRepository::class)->create(
            (int) $donor->id,
            'Very difficult on the phone, escalate to Jane before accepting anything else.',
            (int) $author
        );

        return [(int) $donor->id, (int) $author];
    }

    /**
     * The bundle is streamed from a rest_pre_serve_request filter, which only
     * fires when the server actually serves. rest_do_request stops short of
     * that, so the filter is invoked here the way the server would.
     *
     * @return array<string,mixed>
     */
    private function download(int $donorId): array
    {
        $_COOKIE['dono_donor_session'] = $this->portalSession($donorId, 'tok');

        $req = new WP_REST_Request('POST', '/dono/v1/portal/data-export');
        $req->set_header('X-Dono-Csrf', 'tok');
        $res = rest_do_request($req);

        ob_start();
        apply_filters('rest_pre_serve_request', false, $res, $req, rest_get_server());
        $body = ob_get_clean();

        $bundle = json_decode((string) $body, true);
        $this->assertIsArray($bundle, 'the export streams a JSON body');

        return $bundle;
    }

    public function test_staff_notes_do_not_reach_the_donor_at_all(): void
    {
        [$donorId] = $this->donorWithStaffNote();

        $this->assertArrayNotHasKey('notes', $this->download($donorId));
    }

    /** The author is not reachable through the note text either. */
    public function test_no_staff_name_or_role_appears_anywhere_in_the_notes_section(): void
    {
        [$donorId] = $this->donorWithStaffNote();

        $notes = (string) wp_json_encode($this->download($donorId));

        $this->assertStringNotContainsString('Jane Smith', $notes);
        $this->assertStringNotContainsString('editor', $notes);
    }

    /** Not through the note text, and not through the rest of the bundle either. */
    public function test_the_note_body_appears_nowhere_in_the_bundle(): void
    {
        [$donorId] = $this->donorWithStaffNote();

        $bundle = (string) wp_json_encode($this->download($donorId));

        $this->assertStringNotContainsString('escalate to Jane', $bundle);
    }

    /** An admin reading the org-side export still sees who wrote it. */
    public function test_the_org_side_export_still_names_the_author(): void
    {
        [$donorId, $authorId] = $this->donorWithStaffNote();

        $note = Plugin::instance()->container->get(DonorMetricsService::class)
            ->exportData($donorId)['notes'][0] ?? [];

        $this->assertSame('Jane Smith', $note['author_display_name'] ?? null);
        $this->assertSame($authorId, (int) ($note['author_user_id'] ?? 0));
    }
}
