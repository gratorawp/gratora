<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donors\Donor;
use Dono\Donors\DonorService;
use Dono\Mail\Mailer;
use Dono\Settings\SettingsService;

/**
 * Full-codebase QA Batch 2 (security/privacy) regressions.
 */
final class Batch2SecurityTest extends IntegrationTestCase
{
    public function test_magic_link_email_is_never_bcc_to_admin(): void
    {
        $c = \Dono\Foundation\Plugin::instance()->container;
        // Admin opted into BCC copies of donor mail.
        $c->get(SettingsService::class)->update('email', ['bcc_admin' => true]);
        update_option('admin_email', 'org-admin@example.com');

        $mails  = $this->captureMails();
        $mailer = $c->get(Mailer::class);

        $mailer->sendTemplate('magic_link', 'donor@example.com', [
            'organisation_name' => 'Test Org',
            'magic_link'        => 'https://example.com/portal?token=secret',
        ]);
        $mailer->sendTemplate('donation_receipt', 'donor@example.com', [
            'donor_first_name'  => 'Dee',
            'amount'            => '$10.00',
            'organisation_name' => 'Test Org',
        ]);

        $magic   = $this->findMail($mails, 'sign-in');
        $receipt = $this->findMail($mails, 'Thank you for your donation');

        $this->assertNotNull($magic, 'magic-link email sent');
        $this->assertNotNull($receipt, 'receipt email sent');

        $this->assertFalse(
            $this->hasAdminBcc($magic),
            'magic-link sign-in URL must never be BCC\'d to the admin'
        );
        $this->assertTrue(
            $this->hasAdminBcc($receipt),
            'receipt still honours bcc_admin so the feature works'
        );
    }

    public function test_redacted_donor_redonating_reactivates_the_same_row(): void
    {
        $svc = \Dono\Foundation\Plugin::instance()->container->get(DonorService::class);

        $donor = $svc->findOrCreate('repeat@example.com', ['first_name' => 'Reed']);
        $id    = (int) $donor->id;

        $svc->redact($donor);
        $redacted = Donor::query()->where('id', $id)->get();
        $this->assertNotNull($redacted->redacted_at);
        $this->assertNull($svc->decryptEmail($redacted));

        // Same email donates again (the donation path passes reactivate=true)
        // -> same row, re-activated (one donor per email).
        $again = $svc->findOrCreate('repeat@example.com', ['first_name' => 'Reed'], true);
        $this->assertSame($id, (int) $again->id, 'one donor per email - no second row');
        $this->assertNull($again->redacted_at, 'row is re-activated, not redacted-with-PII');
        $this->assertSame('repeat@example.com', $svc->decryptEmail($again));
    }

    public function test_bare_lookup_does_not_reactivate_or_repopulate_a_redacted_donor(): void
    {
        $svc = \Dono\Foundation\Plugin::instance()->container->get(DonorService::class);

        $donor = $svc->findOrCreate('erased@example.com', ['first_name' => 'Ann']);
        $id    = (int) $donor->id;
        $svc->redact($donor);

        // A bare lookup - e.g. the unauthenticated portal register/link request
        // - must never un-redact the donor or plant PII on the erased row.
        $again = $svc->findOrCreate('erased@example.com', ['first_name' => 'Mallory']);
        $this->assertSame($id, (int) $again->id, 'still one donor per email');

        $fresh = Donor::query()->where('id', $id)->get();
        $this->assertNotNull($fresh->redacted_at, 'the erasure stays intact');
        $this->assertNull($fresh->first_name, 'no name is planted onto the erased row');
    }

    public function test_editing_a_redacted_donor_is_rejected(): void
    {
        $svc   = \Dono\Foundation\Plugin::instance()->container->get(DonorService::class);
        $donor = $svc->findOrCreate('noedit@example.com', ['first_name' => 'Nia']);
        $svc->redact($donor);

        $this->expectException(\InvalidArgumentException::class);
        $svc->editProfile($donor, ['first_name' => 'Hacker']);
    }

    public function test_redaction_revokes_outstanding_magic_link_tokens(): void
    {
        $c     = \Dono\Foundation\Plugin::instance()->container;
        $svc   = $c->get(DonorService::class);
        $magic = $c->get(\Dono\Donors\MagicLinkService::class);

        $donor = $svc->findOrCreate('revoke@example.com', ['first_name' => 'Rev']);
        $magic->issue((int) $donor->id, 'donor_portal');
        $this->assertGreaterThan(
            0,
            (int) \Dono\Donors\MagicLinkToken::query()->where('donor_id', (int) $donor->id)->count(),
            'a token exists before redaction'
        );

        $svc->redact($donor);

        $this->assertSame(
            0,
            (int) \Dono\Donors\MagicLinkToken::query()->where('donor_id', (int) $donor->id)->count(),
            'redaction revokes the donor\'s magic-link tokens'
        );
    }

    public function test_redaction_erases_staff_notes(): void
    {
        $c     = \Dono\Foundation\Plugin::instance()->container;
        $svc   = $c->get(DonorService::class);
        $notes = $c->get(\Dono\Donors\DonorNoteRepository::class);

        $donor = $svc->findOrCreate('noted@example.com', ['first_name' => 'Nora']);
        $notes->create((int) $donor->id, 'Prefers phone contact; lives at 12 Elm St.', 1);
        $this->assertGreaterThan(
            0,
            (int) \Dono\Donors\DonorNote::query()->where('donor_id', (int) $donor->id)->count(),
            'a staff note exists before redaction'
        );

        $svc->redact($donor);

        $this->assertSame(
            0,
            (int) \Dono\Donors\DonorNote::query()->where('donor_id', (int) $donor->id)->count(),
            'redaction removes free-text staff notes (DSAR-scope PII)'
        );
    }

    public function test_redaction_erases_tribute_pii(): void
    {
        $svc   = \Dono\Foundation\Plugin::instance()->container->get(DonorService::class);
        $donor = $svc->findOrCreate('tribute@example.com', ['first_name' => 'Tilly']);
        $now   = gmdate('Y-m-d H:i:s');

        $d = \Dono\Donations\Donation::make();
        $d->reference = 'DONO-TRIB-1';
        $d->donor_id = (int) $donor->id;
        $d->amount_cents = 5000;
        $d->net_cents = 5000;
        $d->currency = 'USD';
        $d->base_amount_cents = 5000;
        $d->base_currency = 'USD';
        $d->fx_rate = '1.00000000';
        $d->gateway = 'offline';
        $d->status = 'paid';
        $d->is_test = false;
        $d->paid_at = $now;
        $d->created_at = $now;
        $d->updated_at = $now;
        $d->save();

        // In-honor tribute: donor message + a third party's notify email + the
        // honoree's name - all PII the erasure must remove.
        $t = \Dono\Donations\DonationTribute::make();
        $t->donation_id = (int) $d->id;
        $t->donor_id = (int) $donor->id;
        $t->type = 'in_honor';
        $t->name = 'Jane Honoree';
        $t->notify_email_encrypted = 'enc:third-party@example.com';
        $t->message_encrypted = 'enc:please celebrate';
        $t->created_at = $now;
        $t->save();

        $svc->redact($donor);

        $fresh = \Dono\Donations\DonationTribute::query()->where('id', (int) $t->id)->get();
        $this->assertSame('', $fresh->name, 'honoree name erased on redaction');
        $this->assertNull($fresh->notify_email_encrypted, 'third-party notify email erased');
        $this->assertNull($fresh->message_encrypted, 'tribute message erased');
    }

    /** @param \ArrayObject<int,array<string,mixed>> $mails */
    private function findMail(\ArrayObject $mails, string $needle): ?array
    {
        foreach ($mails as $m) {
            if (stripos((string) ($m['subject'] ?? ''), $needle) !== false) return $m;
        }
        return null;
    }

    /** @param array<string,mixed> $mail */
    private function hasAdminBcc(array $mail): bool
    {
        foreach ((array) ($mail['headers'] ?? []) as $h) {
            if (stripos((string) $h, 'Bcc:') === 0 && stripos((string) $h, 'org-admin@example.com') !== false) {
                return true;
            }
        }
        return false;
    }
}
