<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donors\Donor;
use Dono\Donors\DonorService;
use Dono\Foundation\Plugin;
use Dono\Foundation\Uninstall\DataEraser;

/**
 * A donor's picture is a WordPress attachment on a public uploads URL, and the
 * only thing that says whose it is is a column of dono_donors. Dropping the
 * table destroys that pointer, so a site that ticked "delete all data" is left
 * serving every supporter photograph forever with nothing left to find them by.
 *
 * Nothing here calls erase(): it would drop the tables of the shared test
 * database. What is asserted is the same thing the rest of the wipe asserts,
 * the set it would take.
 */
final class UninstallDonorAvatarsTest extends IntegrationTestCase
{
    private function attachment(string $title): int
    {
        return (int) wp_insert_attachment([
            'post_mime_type' => 'image/png',
            'post_title'     => $title,
            'post_content'   => '',
            'post_status'    => 'inherit',
        ]);
    }

    private function donorWithAvatar(int $attachmentId): Donor
    {
        $donor = Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate('avatar-' . uniqid() . '@example.test');

        $donor->avatar_attachment_id = $attachmentId;
        $donor->save();

        return $donor;
    }

    public function test_a_picture_a_donor_uploaded_is_planned_for_deletion(): void
    {
        $attachmentId = $this->attachment('donor-avatar-probe');
        $this->donorWithAvatar($attachmentId);

        $this->assertContains($attachmentId, (new DataEraser())->avatarAttachmentIds());
    }

    /** The ids come out of the table, so they have to be readable before the drop. */
    public function test_every_donor_with_a_picture_is_covered(): void
    {
        $first  = $this->attachment('donor-avatar-one');
        $second = $this->attachment('donor-avatar-two');
        $this->donorWithAvatar($first);
        $this->donorWithAvatar($second);

        $planned = (new DataEraser())->avatarAttachmentIds();

        $this->assertContains($first, $planned);
        $this->assertContains($second, $planned);
    }

    /** Media the org uploaded itself is not core's to take. */
    public function test_an_attachment_no_donor_points_at_is_left_alone(): void
    {
        $orgMedia = $this->attachment('annual-report-cover');

        $this->assertNotContains($orgMedia, (new DataEraser())->avatarAttachmentIds());
    }
}
