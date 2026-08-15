<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\AntiSpamGuard;
use Dono\Donors\Donor;
use Dono\Donors\DonorService;
use Dono\Donors\MagicLinkService;
use Dono\Donors\PendingSignup;
use Dono\Donors\PendingSignupRepository;
use Dono\Donors\Portal\PortalSession;
use Dono\Donors\SignupRedemption;
use Dono\Foundation\Identity\IdentityHasher;
use Dono\Foundation\Plugin;
use WP_REST_Request;

/**
 * Signing up records a claim on an address. Redeeming the emailed link is what
 * makes it a donor.
 *
 * The endpoint takes no session and proves nothing about who is calling, so an
 * address typed into it is a claim, not a fact: anyone can type anyone's. A
 * claim that becomes a donor immediately puts a stranger's chosen address on
 * the Donors screen, in the export and in the counts, and keeps it for the
 * retention window. Controlling the mailbox is the only evidence the address
 * belonged to whoever typed it, so that is what creates the account.
 */
final class DeferredSignupTest extends IntegrationTestCase
{
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

    private function claim(string $email): ?PendingSignup
    {
        return $this->container()->get(PendingSignupRepository::class)->findByEmailHash($this->hash($email));
    }

    /** @param array<string,mixed> $extra */
    private function signUp(string $email, array $extra = []): \WP_REST_Response|\WP_Error
    {
        $req = new WP_REST_Request('POST', '/dono/v1/portal/register');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode($extra + [
            'email' => $email,
            'token' => $this->container()->get(AntiSpamGuard::class)->mintPortalToken(),
        ]));

        return rest_do_request($req);
    }

    /** The link the job would email, minted the same way. */
    private function linkFor(PendingSignup $claim): string
    {
        return $this->container()->get(MagicLinkService::class)->issue(
            0,
            SignupRedemption::PURPOSE,
            (int) $claim->id,
            PendingSignupRepository::TTL_SECONDS
        );
    }

    public function test_signing_up_creates_no_donor(): void
    {
        $email = 'unproven-' . uniqid() . '@example.test';

        $this->assertSame(200, $this->signUp($email, ['first_name' => 'Ada'])->get_status());

        $this->assertNull($this->donor($email), 'an unproven address is not a donor');
        $this->assertNotNull($this->claim($email), 'the claim waits for the link');
    }

    public function test_redeeming_the_link_creates_the_donor_and_clears_the_claim(): void
    {
        $email = 'proven-' . uniqid() . '@example.test';
        $this->signUp($email, ['first_name' => 'Ada', 'last_name' => 'Lovelace']);

        $donorId = $this->container()->get(SignupRedemption::class)->redeem($this->linkFor($this->claim($email)));

        $this->assertGreaterThan(0, $donorId);
        $donor = $this->donor($email);
        $this->assertNotNull($donor, 'proving the address creates the donor');
        $this->assertSame('Ada', (string) $donor->first_name);
        $this->assertSame('Lovelace', (string) $donor->last_name);
        $this->assertNull($this->claim($email), 'the claim is spent');
    }

    /** The portal opens straight from the link, without a second step. */
    public function test_the_link_opens_a_session(): void
    {
        $email = 'session-' . uniqid() . '@example.test';
        $this->signUp($email, ['first_name' => 'Grace']);

        $session = $this->container()->get(PortalSession::class)->startFromToken($this->linkFor($this->claim($email)));

        $this->assertIsArray($session);
        $this->assertSame((int) $this->donor($email)->id, (int) $session['donor_id']);
    }

    public function test_a_link_cannot_be_redeemed_twice(): void
    {
        $email = 'twice-' . uniqid() . '@example.test';
        $this->signUp($email);
        $raw = $this->linkFor($this->claim($email));

        $first  = $this->container()->get(SignupRedemption::class)->redeem($raw);
        $second = $this->container()->get(SignupRedemption::class)->redeem($raw);

        $this->assertGreaterThan(0, $first);
        $this->assertSame(0, $second, 'a spent link creates nothing');
        $this->assertSame(1, Donor::query()->where('email_hash', $this->hash($email))->count());
    }

    public function test_an_expired_claim_cannot_be_redeemed(): void
    {
        $email = 'stale-' . uniqid() . '@example.test';
        $this->signUp($email);
        $claim = $this->claim($email);
        $raw   = $this->linkFor($claim);

        $claim->expires_at = gmdate('Y-m-d H:i:s', time() - 60);
        $claim->save();

        $this->assertSame(0, $this->container()->get(SignupRedemption::class)->redeem($raw));
        $this->assertNull($this->donor($email));
    }

    /**
     * The risk the deferral moves rather than removes. Anyone can claim an
     * address before its owner is a donor; if the owner then donates and later
     * opens that link, findOrCreate would fill their empty name with the
     * claimant's, and it would print on their receipts and year-end statement.
     */
    public function test_a_claim_cannot_name_a_donor_it_did_not_create(): void
    {
        $email = 'claimed-' . uniqid() . '@example.test';
        $this->signUp($email, ['first_name' => 'Rude', 'last_name' => 'Word']);
        $raw = $this->linkFor($this->claim($email));

        // The real owner becomes a donor by giving, with no name on file.
        $this->container()->get(DonorService::class)->findOrCreate($email);
        $this->assertNull($this->donor($email)->first_name);

        $this->container()->get(SignupRedemption::class)->redeem($raw);

        $this->assertNull($this->donor($email)->first_name, 'the claim cannot name them');
        $this->assertNull($this->donor($email)->last_name);
    }

    /**
     * Signing up twice is one person who lost the first email, so it refreshes
     * the one row rather than opening a second claim on the address. The name
     * is the exception: the endpoint proves nothing about who is calling, so a
     * second caller cannot take a name the first wrote.
     */
    public function test_a_second_signup_replaces_the_claim_rather_than_adding_one(): void
    {
        $email = 'again-' . uniqid() . '@example.test';
        $this->signUp($email, ['first_name' => 'First']);
        $firstId = (int) $this->claim($email)->id;

        // The per-mailbox send limit is the thing being stepped over here, not
        // the behaviour under test.
        delete_transient('dono_send_link_mailbox_' . substr($this->hash($email), 0, 32));
        $this->signUp($email, ['first_name' => 'Second', 'last_name' => 'Surname']);

        $this->assertSame(1, PendingSignup::query()->where('email_hash', $this->hash($email))->count());
        $this->assertSame($firstId, (int) $this->claim($email)->id, 'the same row is updated');
        $this->assertNull($this->claim($email)->first_name, 'a disputed name reaches nobody');
        $this->assertNull($this->claim($email)->last_name, 'and a second caller does not get to write one either');
    }

    /** An address nobody proved is not kept past its window. */
    public function test_the_daily_sweep_drops_expired_claims(): void
    {
        $email = 'swept-' . uniqid() . '@example.test';
        $this->signUp($email);
        $claim = $this->claim($email);
        $claim->expires_at = gmdate('Y-m-d H:i:s', time() - 60);
        $claim->save();

        $this->container()->get(PendingSignupRepository::class)->purgeExpired();

        $this->assertNull($this->claim($email));
    }

    /**
     * A claim has no donor id, so erasure reaches it by address or not at all.
     * Left behind, its link would still be live and would rebuild the donor.
     */
    public function test_erasing_a_donor_takes_the_claim_on_their_address_with_it(): void
    {
        $email = 'erased-' . uniqid() . '@example.test';
        $donor = $this->container()->get(DonorService::class)->findOrCreate($email, ['first_name' => 'Ada']);
        $this->signUp($email);
        $this->assertNotNull($this->claim($email), 'a claim is standing on the address');

        $this->container()->get(DonorService::class)->redact($donor);

        $this->assertNull($this->claim($email), 'erasure took the claim too');
    }

    /** Erasure is a decision, not a lapsed state; signing up must not undo it. */
    public function test_a_claim_cannot_rebuild_an_erased_donor(): void
    {
        $email = 'gone-' . uniqid() . '@example.test';
        $donor = $this->container()->get(DonorService::class)->findOrCreate($email, ['first_name' => 'Ada']);
        $this->signUp($email);
        $raw = $this->linkFor($this->claim($email));
        $this->container()->get(DonorService::class)->redact($donor);

        $this->assertSame(0, $this->container()->get(SignupRedemption::class)->redeem($raw));
        $this->assertNotNull($this->donor($email)->redacted_at, 'still erased');
    }

    /**
     * Registering must not answer whether an address is already a donor. Both
     * paths return the same flat 200 and neither reveals which branch it took.
     */
    public function test_signing_up_says_nothing_about_whether_the_address_is_known(): void
    {
        $known = 'known-' . uniqid() . '@example.test';
        $this->container()->get(DonorService::class)->findOrCreate($known);

        $a = $this->signUp($known);
        $b = $this->signUp('stranger-' . uniqid() . '@example.test');

        $this->assertSame($a->get_status(), $b->get_status());
        $this->assertSame($a->get_data(), $b->get_data());
    }

    /**
     * The job, not the request, decides what to send, and it is reached through
     * Action Scheduler. That spreads the enqueued array with array_values, so a
     * job carrying one named value arrives as one positional string. Nothing
     * exercised that path end to end, and a sign-in link nobody was sent looks
     * exactly like a sign-in link nobody clicked.
     */
    public function test_asking_for_a_sign_in_link_actually_mails_one(): void
    {
        $email = 'signin-' . uniqid() . '@example.test';
        $donor = $this->container()->get(DonorService::class)->findOrCreate($email);

        $req = new WP_REST_Request('POST', '/dono/v1/portal/send-link');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode([
            'email' => $email,
            'token' => $this->container()->get(AntiSpamGuard::class)->mintPortalToken(),
        ]));
        rest_do_request($req);

        $this->runPendingAsyncJobs();

        $this->assertSame(
            1,
            \Dono\Donors\MagicLinkToken::query()
                ->where('donor_id', (int) $donor->id)
                ->where('purpose', 'donor_portal')
                ->count(),
            'a link was issued for the donor who asked'
        );
    }

    /** And a signup mails a link that carries the claim rather than a donor. */
    public function test_signing_up_mails_a_link_that_carries_the_claim(): void
    {
        $email = 'mailed-' . uniqid() . '@example.test';
        $this->signUp($email, ['first_name' => 'Ada']);

        $this->runPendingAsyncJobs();

        $token = \Dono\Donors\MagicLinkToken::query()
            ->where('purpose', SignupRedemption::PURPOSE)
            ->get();

        $this->assertNotNull($token, 'the signup link was issued');
        $this->assertSame(0, (int) $token->donor_id, 'it names no donor, because there is none yet');
        $this->assertSame((int) $this->claim($email)->id, (int) $token->target_id, 'it points at the claim');
    }

    /** An address that is already a donor gets signed in, not claimed. */
    public function test_signing_up_with_a_known_address_sends_a_sign_in_link(): void
    {
        $email = 'already-' . uniqid() . '@example.test';
        $donor = $this->container()->get(DonorService::class)->findOrCreate($email);

        $this->signUp($email);
        $this->runPendingAsyncJobs();

        $this->assertNull($this->claim($email), 'the moot claim is cleared');
        $this->assertSame(
            1,
            \Dono\Donors\MagicLinkToken::query()
                ->where('donor_id', (int) $donor->id)
                ->where('purpose', 'donor_portal')
                ->count()
        );
    }
}
