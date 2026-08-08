<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donors\Donor;
use Dono\Donors\DonorAvatars;
use Dono\Foundation\Crypto\Crypto;
use Dono\Foundation\Plugin;
use Dono\Settings\SettingsService;

/**
 * Hiding a donor is the answer to a bad picture or an unwanted name. Before it
 * existed the only lever was redaction, which destroys the record the org still
 * has to account for, so an admin had to choose between leaving it up and
 * losing the donation history.
 *
 * It has to reach everything a visitor can see, not just the picture.
 */
final class DonorPublicSuppressionTest extends IntegrationTestCase
{
    private function avatars(): DonorAvatars
    {
        return Plugin::instance()->container->get(DonorAvatars::class);
    }

    private function donor(int $id, bool $hidden = false, int $attachmentId = 0): Donor
    {
        $crypto = Plugin::instance()->container->get(Crypto::class);

        $d = Donor::make();
        $d->id = $id;
        $d->email_encrypted = $crypto->encrypt("donor{$id}@example.com");
        $d->public_hidden_at = $hidden ? '2026-08-08 00:00:00' : null;
        $d->avatar_attachment_id = $attachmentId > 0 ? $attachmentId : null;

        return $d;
    }

    protected function tearDown(): void
    {
        delete_option('dono_privacy');
        parent::tearDown();
    }

    public function test_a_visible_donor_is_not_hidden(): void
    {
        $this->assertFalse($this->avatars()->hidden($this->donor(1)));
    }

    public function test_a_hidden_donor_is_reported_hidden(): void
    {
        $this->assertTrue($this->avatars()->hidden($this->donor(1, true)));
    }

    /** Gravatar on, donor hidden: still no picture. */
    public function test_a_hidden_donor_gets_no_gravatar(): void
    {
        Plugin::instance()->container->get(SettingsService::class)
            ->update('privacy', ['gravatar_avatars' => true]);

        $urls = $this->avatars()->urlsFor([
            1 => $this->donor(1, true),
            2 => $this->donor(2),
        ]);

        $this->assertArrayNotHasKey(1, $urls, 'a hidden donor must not reach gravatar');
        $this->assertArrayHasKey(2, $urls);
    }

    /**
     * Hiding has to beat their own upload too. A picture they chose is still a
     * picture an admin has decided the public should not see.
     */
    public function test_hiding_beats_a_donor_uploaded_picture(): void
    {
        $attachmentId = $this->attachment();

        $visible = $this->avatars()->urlsFor([1 => $this->donor(1, false, $attachmentId)]);
        $hidden  = $this->avatars()->urlsFor([1 => $this->donor(1, true, $attachmentId)]);

        $this->assertArrayHasKey(1, $visible, 'precondition: an uploaded picture resolves');
        $this->assertArrayNotHasKey(1, $hidden);

        wp_delete_attachment($attachmentId, true);
    }

    /** Their own upload wins over Gravatar: they chose this one, here. */
    public function test_an_upload_beats_gravatar(): void
    {
        Plugin::instance()->container->get(SettingsService::class)
            ->update('privacy', ['gravatar_avatars' => true]);

        $attachmentId = $this->attachment();

        $urls = $this->avatars()->urlsFor([1 => $this->donor(1, false, $attachmentId)]);

        $this->assertArrayHasKey(1, $urls);
        $this->assertStringNotContainsString('gravatar.com', $urls[1]);

        wp_delete_attachment($attachmentId, true);
    }

    /** With no upload and Gravatar off, nothing resolves and the initial shows. */
    public function test_nothing_resolves_without_an_upload_or_gravatar(): void
    {
        $this->assertSame([], $this->avatars()->urlsFor([1 => $this->donor(1)]));
    }

    private function attachment(): int
    {
        $id = wp_insert_attachment([
            'post_mime_type' => 'image/png',
            'post_title'     => 'donor-avatar-test',
            'post_status'    => 'inherit',
        ], wp_upload_dir()['path'] . '/donor-avatar-test.png');

        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);

        return (int) $id;
    }
}
