<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\Donation;
use Dono\Donors\Consent;
use Dono\Donors\Donor;
use Dono\Donors\DonorNote;
use Dono\Donors\DonorNoteRepository;
use Dono\Donors\DonorService;
use Dono\Donors\Erasure\ErasureHandler;
use Dono\Donors\Erasure\ErasureRequest;
use Dono\Recurring\RecurringPlan;
use Dono\Donors\MagicLinkService;
use Dono\Donors\MagicLinkToken;
use Dono\Donors\PendingSignup;
use Dono\Donors\PendingSignupRepository;
use Dono\Donors\SignupRedemption;
use Dono\Foundation\Identity\IdentityHasher;
use Dono\Donors\DonorAggregateSyncer;
use Dono\Foundation\Plugin;
use Dono\Vendor\Queryable\DB;
use RuntimeException;
use Throwable;
use WP_REST_Request;

/**
 * The donor side of the same property: a block that cannot finish leaves
 * nothing of itself behind, on the rows a compliance officer or a locked-out
 * donor would be looking at.
 *
 * Covers redemption, donor deletion, the admin profile save and the donor
 * aggregate resync. Erasure is covered in TransactionRollbackTest.
 *
 * DonorAggregateSyncer's transaction is deliberately absent from this file. It
 * holds one write, with nothing after it that can throw, and exists for the
 * FOR UPDATE row lock that serialises concurrent syncs for one donor. There is
 * no partial state for a rollback to undo, so a test asserting one would be
 * asserting the harness.
 */
final class DonorTransactionRollbackTest extends IntegrationTestCase
{
    private const CREATE_FAILURE = 'the add-on could not finish greeting the donor';
    private const DELETE_FAILURE = 'the add-on could not let go of the donor';
    private const SYNC_FAILURE   = 'the block that resynced the donor could not finish';

    /** @since 1.0.0 */
    public const ERASURE_FAILURE = 'this add-on cannot erase its part';

    private function container()
    {
        return Plugin::instance()->container;
    }

    private function hash(string $email): string
    {
        return $this->container()->get(IdentityHasher::class)->emailHash($email);
    }

    private function donor(string $email): ?Donor
    {
        return Donor::query()->where('email_hash', $this->hash($email))->get();
    }

    /** A standing claim on an address plus the link that was mailed for it. */
    private function claimAndLink(string $email): array
    {
        $claim = $this->container()->get(PendingSignupRepository::class)->put($email);
        $raw   = $this->container()->get(MagicLinkService::class)->issue(
            0,
            SignupRedemption::PURPOSE,
            (int) $claim->id,
            PendingSignupRepository::TTL_SECONDS,
            ['first_name' => 'Marguerite', 'last_name' => 'Ashby'],
        );

        return [$claim, $raw];
    }

    private function tokenFor(string $raw): ?MagicLinkToken
    {
        return MagicLinkToken::query()
            ->where('token_hash', hash('sha256', $raw))
            ->where('purpose', SignupRedemption::PURPOSE)
            ->get();
    }

    /** Runs $body with a listener on the in-transaction donor creation seam that throws. */
    private function whileCreationThrows(callable $body): void
    {
        $throw = static function (): void {
            throw new RuntimeException(self::CREATE_FAILURE);
        };
        add_action('dono.donor.created', $throw);

        try {
            $body();
        } finally {
            remove_action('dono.donor.created', $throw);
        }
    }

    /**
     * The link is the only evidence the address belongs to whoever typed it,
     * and consuming it is an atomic conditional update, so a redemption that
     * then fails has to give the link back. Spent with no account behind it,
     * the link in the donor's inbox is dead and their click did nothing: they
     * can register again, because the claim is keyed by address and reused,
     * but nothing tells them that, and the mail they already have never works.
     */
    public function test_a_failed_redemption_does_not_spend_the_link(): void
    {
        $email = 'redeem-rollback@example.test';
        [$claim, $raw] = $this->claimAndLink($email);

        $this->whileCreationThrows(function () use ($raw): void {
            try {
                $this->container()->get(SignupRedemption::class)->redeem($raw);
                $this->fail('the listener exception should reach the caller');
            } catch (RuntimeException $e) {
                $this->assertSame(self::CREATE_FAILURE, $e->getMessage());
            }
        });

        $token = $this->tokenFor($raw);
        $this->assertNotNull($token, 'the link row is gone');
        $this->assertNull($token->used_at, 'the failed redemption spent the link');

        $this->assertNull($this->donor($email), 'a donor survived the failed redemption');
        $this->assertNotNull(
            PendingSignup::query()->where('id', (int) $claim->id)->get(),
            'the claim the link points at is gone, so no second link can be mailed'
        );

        // The same link, second time, with nothing throwing: it still works.
        $donorId = $this->container()->get(SignupRedemption::class)->redeem($raw);

        $this->assertGreaterThan(0, $donorId, 'the link no longer redeems');
        $this->assertNotNull($this->donor($email), 'the donor was not created');
        $this->assertNull(
            PendingSignup::query()->where('id', (int) $claim->id)->get(),
            'the claim was not consumed by the successful redemption'
        );
        // The claim going takes its links with it, so the link is spent in the
        // strongest sense once it has actually bought an account.
        $this->assertNull($this->tokenFor($raw), 'the successful redemption left the link standing');
    }

    /**
     * Everything deletion reaches, so a rollback has something to put back:
     * the donor's own row, a consent record, a staff note, a portal link, and
     * a standing claim on their address with its own link.
     *
     * @return array{donor:Donor, consent:int, note:int, link:int, claim:int, claimLink:int}
     */
    private function donorWithEverythingDeletionReaches(string $email): array
    {
        $donor = $this->container()->get(DonorService::class)->findOrCreate($email, [
            'first_name' => 'Hollis',
            'last_name'  => 'Vane',
        ]);

        $now = gmdate('Y-m-d H:i:s');

        $consent = Consent::make();
        $consent->donor_id        = (int) $donor->id;
        $consent->purpose         = 'marketing';
        $consent->granted         = true;
        $consent->source          = 'donation_form';
        $consent->ip_hash         = str_repeat('a', 64);
        $consent->user_agent_hash = str_repeat('b', 64);
        $consent->occurred_at     = $now;
        $consent->save();

        $note = $this->container()->get(DonorNoteRepository::class)
            ->create((int) $donor->id, 'a staff note that predates the deletion', 1);

        $portalLink = $this->container()->get(MagicLinkService::class)
            ->issue((int) $donor->id, 'donor_portal');
        $linkId = (int) MagicLinkToken::query()
            ->where('token_hash', hash('sha256', $portalLink))
            ->get()->id;

        [$claim, $claimRaw] = $this->claimAndLink($email);

        return [
            'donor'     => $donor,
            'consent'   => (int) $consent->id,
            'note'      => (int) $note['id'],
            'link'      => $linkId,
            'claim'     => (int) $claim->id,
            'claimLink' => (int) $this->tokenFor($claimRaw)->id,
        ];
    }

    /** @param array{donor:Donor, consent:int, note:int, link:int, claim:int, claimLink:int} $rows */
    private function assertDeletionRowsPresent(array $rows, bool $present): void
    {
        $exists = [
            'the donor'          => Donor::query()->where('id', (int) $rows['donor']->id)->exists(),
            'their consent'      => Consent::query()->where('id', $rows['consent'])->exists(),
            'the staff note'     => DonorNote::query()->where('id', $rows['note'])->exists(),
            'their portal link'  => MagicLinkToken::query()->where('id', $rows['link'])->exists(),
            'the standing claim' => PendingSignup::query()->where('id', $rows['claim'])->exists(),
            'the claim link'     => MagicLinkToken::query()->where('id', $rows['claimLink'])->exists(),
        ];

        foreach ($exists as $what => $found) {
            $this->assertSame(
                $present,
                $found,
                $present ? "{$what} did not survive the failed deletion" : "{$what} outlived the deletion"
            );
        }
    }

    /**
     * Deletion is a cascade across six tables, and it ends by telling add-ons
     * the donor is gone. A listener that cannot finish its part has to put the
     * whole cascade back rather than leave a donor whose consent record, staff
     * notes and live portal links have already been destroyed.
     */
    public function test_a_failed_deletion_puts_the_whole_cascade_back(): void
    {
        $email = 'delete-rollback@example.test';
        $rows  = $this->donorWithEverythingDeletionReaches($email);

        $throw = static function (): void {
            throw new RuntimeException(self::DELETE_FAILURE);
        };
        add_action('dono.donor.deleted', $throw);

        try {
            $this->container()->get(DonorService::class)->delete($rows['donor']);
            $this->fail('the listener exception should reach the caller');
        } catch (Throwable $e) {
            $this->assertSame(self::DELETE_FAILURE, $e->getMessage());
        } finally {
            remove_action('dono.donor.deleted', $throw);
        }

        $this->assertDeletionRowsPresent($rows, true);

        // The same call with nothing throwing: the cascade lands in full.
        $this->container()->get(DonorService::class)->delete($this->donor($email));

        $this->assertDeletionRowsPresent($rows, false);
    }

    /**
     * The picture the donor uploaded sits on a public URL and the donor row is
     * the only pointer to it, so deleting it is both irreversible and the last
     * thing that can be done. A deletion that fails must not have already
     * destroyed it: the file would be gone with the donor still on the site.
     */
    public function test_a_failed_deletion_leaves_the_donor_picture_alone(): void
    {
        $email = 'delete-avatar-rollback@example.test';
        $donor = $this->container()->get(DonorService::class)->findOrCreate($email);

        $attachmentId = wp_insert_attachment([
            'post_title'     => 'donor-picture',
            'post_mime_type' => 'image/png',
            'post_status'    => 'inherit',
        ]);
        $donor->avatar_attachment_id = $attachmentId;
        $donor->save();

        $throw = static function (): void {
            throw new RuntimeException(self::DELETE_FAILURE);
        };
        add_action('dono.donor.deleted', $throw);

        try {
            $this->container()->get(DonorService::class)->delete($donor);
            $this->fail('the listener exception should reach the caller');
        } catch (Throwable $e) {
            $this->assertSame(self::DELETE_FAILURE, $e->getMessage());
        } finally {
            remove_action('dono.donor.deleted', $throw);
        }

        $this->assertNotNull(get_post($attachmentId), 'the failed deletion destroyed the picture');
        $this->assertSame(
            $attachmentId,
            (int) $this->donor($email)->avatar_attachment_id,
            'the donor lost the only pointer to their picture'
        );

        $this->container()->get(DonorService::class)->delete($this->donor($email));

        $this->assertNull(get_post($attachmentId), 'the picture outlived the donor');
    }

    /** PATCH the admin donor profile the way the Donors screen does. */
    private function patchDonor(int $donorId, array $body): \WP_REST_Response
    {
        $req = new WP_REST_Request('PATCH', "/dono/v1/admin/donors/{$donorId}");
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode($body));

        return rest_do_request($req);
    }

    /**
     * The profile save writes the plain columns with one UPDATE, the phone and
     * address with their own, and the email with a third that carries the
     * lookup hash. A collision on the email is refused last, by which point the
     * other two have already been written, so the refusal has to take them with
     * it: an admin told the save did not happen must not find the name changed
     * and the phone replaced, with only the address they typed missing.
     */
    public function test_a_refused_email_change_takes_the_rest_of_the_profile_save_with_it(): void
    {
        $service = $this->container()->get(DonorService::class);
        $donor   = $service->findOrCreate('profile-rollback@example.test', [
            'first_name' => 'Hollis',
            'last_name'  => 'Vane',
        ]);
        $service->findOrCreate('profile-rollback-owner@example.test');

        $edit = [
            'first_name' => 'Perpetua',
            'company'    => 'Ashby Trust',
            'phone'      => '+15550100',
        ];

        $refused = $this->patchDonor((int) $donor->id, $edit + [
            'email' => 'profile-rollback-owner@example.test',
        ]);

        $this->assertSame(409, $refused->get_status(), 'the collision was not refused');
        $this->assertSame('dono_email_collision', $refused->get_data()['code'] ?? null);

        $after = Donor::query()->where('id', (int) $donor->id)->get();

        $this->assertSame('Hollis', $after->first_name, 'the refused save changed the name anyway');
        $this->assertNull($after->company, 'the refused save wrote the company anyway');
        $this->assertEmpty($after->phone_encrypted, 'the refused save stored the phone anyway');
        $this->assertSame(
            $this->hash('profile-rollback@example.test'),
            (string) $after->email_hash,
            'the refused save moved the donor off their address'
        );

        // The same edit without the collision: all of it lands.
        $ok = $this->patchDonor((int) $donor->id, $edit);
        $this->assertSame(200, $ok->get_status());

        $saved = Donor::query()->where('id', (int) $donor->id)->get();
        $this->assertSame('Perpetua', $saved->first_name);
        $this->assertSame('Ashby Trust', $saved->company);
        $this->assertNotEmpty($saved->phone_encrypted, 'the phone never landed');
    }

    private function paidDonation(int $donorId, int $cents, string $paidAt): void
    {
        $donation = Donation::make();
        $donation->reference         = 'AGG-' . strtoupper(bin2hex(random_bytes(4)));
        $donation->donor_id          = $donorId;
        $donation->amount_cents      = $cents;
        $donation->net_cents         = $cents;
        $donation->base_amount_cents = $cents;
        $donation->base_currency     = 'USD';
        $donation->currency          = 'USD';
        $donation->gateway           = 'offline';
        $donation->status            = 'paid';
        $donation->frequency         = 'one_time';
        $donation->kind              = 'donation';
        $donation->is_test           = false;
        $donation->paid_at           = $paidAt;
        $donation->created_at        = $paidAt;
        $donation->updated_at        = $paidAt;
        $donation->save();
    }

    /** @return array<string,mixed> */
    private function donorRow(int $donorId): array
    {
        return (array) DB::table('dono_donors')->where('id', $donorId)->get();
    }

    /**
     * Changing the name and the email in one save. The email write goes through
     * the donor model, which carries the whole row, so it can only be correct
     * if it is carrying what the rest of the save just wrote: otherwise it puts
     * the old name back, the response reports it, and the admin is told the
     * save worked while their edit is gone.
     */
    public function test_changing_the_email_alongside_the_name_keeps_both(): void
    {
        $donor = $this->container()->get(DonorService::class)
            ->findOrCreate('combined-edit@example.test', ['first_name' => 'Hollis', 'last_name' => 'Vane']);

        $response = $this->patchDonor((int) $donor->id, [
            'first_name' => 'Perpetua',
            'company'    => 'Ashby Trust',
            'email'      => 'combined-edit-new@example.test',
        ]);

        $this->assertSame(200, $response->get_status());

        $saved = Donor::query()->where('id', (int) $donor->id)->get();

        $this->assertSame(
            $this->hash('combined-edit-new@example.test'),
            (string) $saved->email_hash,
            'the email never moved'
        );
        $this->assertSame('Perpetua', $saved->first_name, 'the email write put the old name back');
        $this->assertSame('Ashby Trust', $saved->company, 'the email write put the old company back');
    }

    /**
     * Erasure stops the donor's mandates first, on purpose: erasing while a
     * plan still bills leaves it renewing and writing their name and email back
     * into the webhook log every month. Cancelling reaches the processor, so it
     * is outside the transaction and outside what a rollback can undo, and an
     * erasure that then fails leaves exactly this: a donor with all their data,
     * whose recurring donation has stopped for good.
     *
     * Pinned rather than fixed. It is the deliberate half of the trade, and the
     * org has to be able to see which half they are holding.
     */
    public function test_an_erasure_that_fails_has_already_stopped_the_recurring_donation(): void
    {
        $donor = $this->container()->get(DonorService::class)
            ->findOrCreate('erasure-recurring@example.test', ['first_name' => 'Hollis', 'last_name' => 'Vane']);

        $now  = gmdate('Y-m-d H:i:s');
        $plan = RecurringPlan::make();
        $plan->donor_id       = (int) $donor->id;
        // Offline, so the cancellation is local: what this pins is when it
        // happens, not which processor held the mandate.
        $plan->gateway        = 'offline';
        $plan->amount_cents   = 5000;
        $plan->currency       = 'USD';
        $plan->interval_unit  = 'month';
        $plan->interval_count = 1;
        $plan->status         = 'active';
        $plan->is_test        = false;
        $plan->started_at     = $now;
        $plan->created_at     = $now;
        $plan->updated_at     = $now;
        $plan->save();

        $handler = new class implements ErasureHandler {
            public function key(): string
            {
                return 'test.donor.rollback';
            }

            public function erase(ErasureRequest $request): void
            {
                throw new RuntimeException(DonorTransactionRollbackTest::ERASURE_FAILURE);
            }
        };
        $add = static function (array $handlers) use ($handler): array {
            $handlers[] = $handler;
            return $handlers;
        };
        add_filter('dono.donor.erasure_handlers', $add);

        try {
            $this->container()->get(DonorService::class)->redact($donor);
            $this->fail('the handler exception should reach the caller');
        } catch (Throwable $e) {
            $this->assertSame(self::ERASURE_FAILURE, $e->getMessage());
        } finally {
            remove_filter('dono.donor.erasure_handlers', $add);
        }

        $after = Donor::query()->where('id', (int) $donor->id)->get();
        $this->assertNull($after->redacted_at, 'the failed erasure marked the donor erased anyway');
        $this->assertSame('Hollis', $after->first_name, 'the failed erasure kept some of the donor cleared');

        $this->assertSame(
            'cancelled',
            (string) RecurringPlan::query()->where('id', (int) $plan->id)->get()->status,
            'the recurring donation was left billing a donor the org was told to forget'
        );
    }
}
