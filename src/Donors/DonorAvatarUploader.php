<?php

declare(strict_types=1);

namespace Dono\Donors;

use WP_Error;

/**
 * Takes a picture a donor uploaded in the portal and turns it into an
 * attachment their record points at.
 *
 * The care here is because of where the result ends up: a donor is not a
 * logged-in WordPress user, and the picture renders on a public campaign page.
 * So the file is checked for what it actually is rather than what it claims,
 * the previous one is deleted rather than orphaned, and an admin can suppress
 * the donor entirely (public_hidden_at) when a check is not enough.
 *
 * @version 1.0.0
 */
final class DonorAvatarUploader
{
    private const MAX_BYTES = 3145728; // 3 MB

    /** Raster only. SVG is markup, and markup on a public page is a script. */
    private const ALLOWED = [
        'jpg|jpeg|jpe' => 'image/jpeg',
        'png'          => 'image/png',
        'gif'          => 'image/gif',
        'webp'         => 'image/webp',
    ];

    /**
     * @param  array<string, mixed> $file a single $_FILES entry
     * @return int|WP_Error the new attachment id
     */
    public function store(Donor $donor, array $file): int|WP_Error
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            return new WP_Error('dono_upload_failed', __('That file did not upload. Try again.', 'dono'), ['status' => 400]);
        }

        if ((int) ($file['size'] ?? 0) > self::MAX_BYTES) {
            return new WP_Error('dono_upload_too_large', __('Pictures need to be under 3 MB.', 'dono'), ['status' => 413]);
        }

        // getimagesize reads the file's own header, so a .png that is really
        // something else fails here rather than on someone's campaign page.
        $probe = @getimagesize((string) ($file['tmp_name'] ?? ''));
        if ($probe === false || ! in_array($probe[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true)) {
            return new WP_Error('dono_upload_not_image', __('That does not look like a picture. JPEG, PNG, GIF or WebP.', 'dono'), ['status' => 415]);
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $overrides = [
            'test_form' => false,
            'mimes'     => self::ALLOWED,
        ];

        $moved = wp_handle_upload($file, $overrides);
        if (! is_array($moved) || isset($moved['error'])) {
            return new WP_Error('dono_upload_failed', (string) ($moved['error'] ?? __('That file could not be saved.', 'dono')), ['status' => 400]);
        }

        $attachmentId = wp_insert_attachment([
            'post_mime_type' => (string) $moved['type'],
            // Never the donor's name: attachment titles are guessable public
            // URLs, and the point is a picture, not an index of who gave.
            'post_title'     => sprintf('donor-avatar-%d', (int) $donor->id),
            'post_content'   => '',
            'post_status'    => 'inherit',
        ], (string) $moved['file']);

        if (is_wp_error($attachmentId) || (int) $attachmentId <= 0) {
            @unlink((string) $moved['file']);
            return new WP_Error('dono_upload_failed', __('That file could not be saved.', 'dono'), ['status' => 500]);
        }

        wp_update_attachment_metadata(
            (int) $attachmentId,
            wp_generate_attachment_metadata((int) $attachmentId, (string) $moved['file'])
        );

        $previous = (int) ($donor->avatar_attachment_id ?? 0);
        $donor->avatar_attachment_id = (int) $attachmentId;
        $donor->save();
        $this->deleteAttachment($previous);

        return (int) $attachmentId;
    }

    /** Clears the picture and takes the file with it. */
    public function remove(Donor $donor): void
    {
        $previous = (int) ($donor->avatar_attachment_id ?? 0);
        $donor->avatar_attachment_id = null;
        $donor->save();
        $this->deleteAttachment($previous);
    }

    /**
     * Deleted after the record stops pointing at it, so a failure here leaves
     * an unused file rather than a donor pointing at nothing.
     */
    private function deleteAttachment(int $id): void
    {
        if ($id > 0) {
            wp_delete_attachment($id, true);
        }
    }
}
