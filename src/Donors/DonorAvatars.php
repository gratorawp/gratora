<?php

declare(strict_types=1);

namespace Dono\Donors;

use Dono\Foundation\Crypto\Crypto;
use Dono\Settings\SettingsService;

/**
 * Resolves Gravatar URLs for the donor-activity blocks.
 *
 * Off unless the org turns it on. Asking Gravatar for a picture sends a hash of
 * the donor's address to a third party from every visitor's browser, on a page
 * that is public, and Dono encrypts those addresses at rest precisely so they
 * are not casually exposed. That trade is the org's to make.
 *
 * Resolves to a URL rather than handing an address to the caller: the blocks
 * only need something to put in a src, and a plaintext address travelling
 * further than this class is a leak waiting for somewhere to happen.
 *
 * @version 1.0.0
 */
final class DonorAvatars
{
    public function __construct(
        private Crypto $crypto,
        private SettingsService $settings,
    ) {
    }

    public function enabled(): bool
    {
        return (bool) ($this->settings->get('privacy')['gravatar_avatars'] ?? false);
    }

    /**
     * Suppressed from every public surface by an admin. The record and the
     * money stay; only what a visitor can see goes. Redaction is the other
     * lever and it destroys the donor, which is no way to answer a bad picture.
     */
    public function hidden(Donor $donor): bool
    {
        return ($donor->public_hidden_at ?? null) !== null;
    }

    /**
     * A donor's own picture, which beats Gravatar because they chose it here.
     * Unlike Gravatar this needs no setting: nothing leaves the site, and a
     * donor who uploaded one has already asked for it to be shown.
     */
    public function uploadedUrl(Donor $donor): string
    {
        $id = (int) ($donor->avatar_attachment_id ?? 0);
        if ($id <= 0) {
            return '';
        }

        $url = wp_get_attachment_image_url($id, 'thumbnail');

        return is_string($url) ? $url : '';
    }

    /**
     * Avatar URL per donor id, keyed for lookup while rendering a list.
     *
     * Anonymous donors are excluded by the caller passing them as anonymous:
     * a picture would name someone who asked not to be named, and the request
     * would leak their address hash while doing it.
     *
     * @param  array<int, Donor> $donors     keyed by donor id
     * @param  array<int, bool>  $anonymous  donor id => is anonymous
     * @return array<int, string>            donor id => url, absent when none
     */
    public function urlsFor(array $donors, array $anonymous = []): array
    {
        if ($donors === []) {
            return [];
        }

        $gravatar = $this->enabled();
        $out = [];
        foreach ($donors as $id => $donor) {
            // Hidden by an admin, or hidden by their own choice: either way the
            // public gets the plain initial, never a face.
            if (! empty($anonymous[$id]) || $this->hidden($donor)) {
                continue;
            }

            // Their own upload first. They chose it here, so it beats a picture
            // they may have set on some other service years ago.
            $uploaded = $this->uploadedUrl($donor);
            if ($uploaded !== '') {
                $out[(int) $id] = $uploaded;
                continue;
            }

            if (! $gravatar) {
                continue;
            }

            $email = $this->crypto->decrypt((string) ($donor->email_encrypted ?? ''));
            if ($email === null || trim($email) === '') {
                continue;
            }

            // 'blank' is what makes the initial underneath usable as the
            // fallback: no image on file returns a transparent pixel rather
            // than a silhouette painted over the letter.
            // get_avatar_url rather than a hand-built gravatar.com URL: it
            // honours the site's own avatar settings and the filters a host
            // theme or privacy plugin may already rely on.
            $url = get_avatar_url($email, ['size' => 96, 'default' => 'blank']);
            if (is_string($url) && $url !== '') {
                $out[(int) $id] = $url;
            }
        }

        return $out;
    }
}
