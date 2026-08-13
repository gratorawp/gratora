<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donors\Donor;
use Dono\Donors\DonorService;
use Dono\Foundation\Plugin;

/**
 * A donor's picture is a WordPress attachment on a public uploads URL, and the
 * donor row holds the only pointer to it. Deleting the donor without deleting
 * the file leaves the photograph served to anyone with the URL, with nothing
 * left in the site that says whose it was: not the uninstall wipe, which reads
 * the ids out of dono_donors, and not the admin, who is looking at a media item
 * with no owner. Erasure already takes the file, and deletion is the more
 * complete of the two, so it cannot take less.
 */
final class DonorDeleteAvatarTest extends IntegrationTestCase
{
    private function service(): DonorService
    {
        return Plugin::instance()->container->get(DonorService::class);
    }

    private function attachment(string $title): int
    {
        return (int) wp_insert_attachment([
            'post_mime_type' => 'image/png',
            'post_title'     => $title,
            'post_content'   => '',
            'post_status'    => 'inherit',
        ]);
    }

    /** A supporter who signed up, uploaded a picture and never donated. */
    private function donorWithAvatar(int $attachmentId): Donor
    {
        $donor = $this->service()->findOrCreate('delete-avatar-' . uniqid() . '@example.test');

        $donor->avatar_attachment_id = $attachmentId;
        $donor->save();

        return $donor;
    }

    public function test_deleting_a_donor_takes_the_picture_they_uploaded(): void
    {
        $attachmentId = $this->attachment('donor-avatar-deleted');
        $donor        = $this->donorWithAvatar($attachmentId);

        $this->service()->delete($donor);

        $this->assertNull(
            get_post($attachmentId),
            'the picture is still served from the uploads directory with no donor left to link it to'
        );
    }

    /** Erasure has the same obligation, and the donor row survives it. */
    public function test_erasing_a_donor_takes_the_picture_and_the_pointer(): void
    {
        $attachmentId = $this->attachment('donor-avatar-redacted');
        $donor        = $this->donorWithAvatar($attachmentId);

        $this->service()->redact($donor);

        $this->assertNull(get_post($attachmentId), 'the file goes');
        $this->assertNull(
            Donor::query()->where('id', (int) $donor->id)->get()->avatar_attachment_id,
            'and the column no longer points at an attachment that is not there'
        );
    }

    /** Media the org uploaded itself is not the donor path's to take. */
    public function test_deleting_a_donor_without_a_picture_touches_no_media(): void
    {
        $orgMedia = $this->attachment('annual-report-cover');
        $donor    = $this->service()->findOrCreate('no-avatar-' . uniqid() . '@example.test');

        $this->service()->delete($donor);

        $this->assertNotNull(get_post($orgMedia), 'unrelated media survives a donor deletion');
    }
}
