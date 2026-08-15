<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\AntiSpamGuard;
use Dono\Donors\Donor;
use Dono\Donors\PendingSignupRepository;
use Dono\Donors\SignupRedemption;
use Dono\Foundation\Identity\IdentityHasher;
use Dono\Foundation\Plugin;
use WP_REST_Request;

/**
 * Who gets to name the donor a claim becomes. Nobody calling /portal/register
 * has proved the mailbox, and redemption prints the claim's names onto the
 * donor row, the receipts and the year-end statement, where refreshProfile only
 * back-fills and so never corrects them.
 *
 * Driven end to end rather than against the claim row: register, run the job so
 * the mail actually goes, attack, then redeem the token parsed out of the mail
 * the victim received. A rule that holds on the claim and not on the donor row
 * is not the property anyone cares about.
 */
final class PortalClaimNameOwnershipTest extends IntegrationTestCase
{
    private function c()
    {
        return Plugin::instance()->container;
    }

    /** @param array<string,mixed> $body */
    private function register(array $body): \WP_REST_Response|\WP_Error
    {
        $req = new WP_REST_Request('POST', '/dono/v1/portal/register');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode(
            $body + ['token' => $this->c()->get(AntiSpamGuard::class)->mintPortalToken()]
        ));

        return rest_do_request($req);
    }

    private function claimRow(string $email)
    {
        $hasher = $this->c()->get(IdentityHasher::class);

        return $this->c()->get(PendingSignupRepository::class)
            ->findByEmailHash($hasher->emailHash($hasher->normalizeEmail($email)));
    }

    private function mailbox(): \ArrayObject
    {
        $sent = new \ArrayObject();
        add_filter('pre_wp_mail', function ($null, $atts) use ($sent) {
            $sent[] = (string) ($atts['message'] ?? '');
            return false;
        }, 10, 2);

        return $sent;
    }

    private function tokenIn(string $body): string
    {
        $this->assertSame(1, preg_match('/[?&]token=([A-Za-z0-9_\-]+)/', html_entity_decode($body), $m));

        return $m[1];
    }

    /**
     * The blocker the wave-four review reproduced: an address with a claim
     * already mailed, then two identical anonymous posts. The first was said
     * to clear the contested fields and the second to fill the blanks it made,
     * so the owner redeemed their own link into the attacker's name.
     */
    public function test_two_anonymous_posts_do_not_put_the_attackers_name_on_the_donor_row(): void
    {
        $email = 'claim-victim-' . uniqid() . '@example.test';
        $sent  = $this->mailbox();

        $this->register(['email' => $email, 'first_name' => 'Alice', 'last_name' => 'Okafor']);
        $this->runPendingAsyncJobs();
        $this->assertCount(1, $sent, 'the victim has their link');
        $victimLink = $this->tokenIn((string) $sent[0]);

        $this->register(['email' => $email, 'first_name' => 'Mallory', 'last_name' => 'Attacker']);
        $this->register(['email' => $email, 'first_name' => 'Mallory', 'last_name' => 'Attacker']);

        $claim = $this->claimRow($email);
        $this->assertNotNull($claim);
        $this->assertNotSame('Mallory', (string) $claim->first_name, 'no attacker first name on the claim');
        $this->assertNotSame('Attacker', (string) $claim->last_name, 'no attacker surname on the claim');

        $donorId = $this->c()->get(SignupRedemption::class)->redeem($victimLink);
        $donor   = Donor::query()->where('id', $donorId)->get();

        $this->assertNotNull($donor, 'the victim link still redeems');
        $this->assertNotSame('Mallory', (string) $donor->first_name, "the attacker's name is not on the donor row");
        $this->assertNotSame('Attacker', (string) $donor->last_name);
    }

    /**
     * The one-request variant: the shipped form requires a first name and not
     * a surname, so the common claim shape has a blank surname for a stranger
     * to fill.
     */
    public function test_a_lone_surname_from_a_stranger_never_reaches_the_donor_row(): void
    {
        $email = 'claim-inject-' . uniqid() . '@example.test';
        $sent  = $this->mailbox();

        $this->register(['email' => $email, 'first_name' => 'Alice']);
        $this->runPendingAsyncJobs();
        $victimLink = $this->tokenIn((string) $sent[0]);

        $this->register(['email' => $email, 'last_name' => 'Attacker']);

        $donorId = $this->c()->get(SignupRedemption::class)->redeem($victimLink);
        $donor   = Donor::query()->where('id', $donorId)->get();

        $this->assertNotNull($donor);
        $this->assertSame('', (string) $donor->last_name, "the stranger's surname reaches nobody");
    }

    /**
     * The honest donor whose second registration retypes the name they already
     * proved: the name has to survive onto the donor row rather than being
     * destroyed as a dispute.
     */
    public function test_an_honest_donor_retyping_their_own_name_keeps_it_on_the_donor_row(): void
    {
        $email = 'claim-honest-' . uniqid() . '@example.test';
        $sent  = $this->mailbox();

        $this->register(['email' => $email, 'first_name' => 'Alice', 'last_name' => 'Okafor']);
        $this->runPendingAsyncJobs();
        $link = $this->tokenIn((string) $sent[0]);

        // Same person, same name, typed the way a phone keyboard offers it.
        $this->register(['email' => $email, 'first_name' => 'alice', 'last_name' => ' okafor ']);

        $donorId = $this->c()->get(SignupRedemption::class)->redeem($link);
        $donor   = Donor::query()->where('id', $donorId)->get();

        $this->assertNotNull($donor);
        $this->assertSame('Alice', (string) $donor->first_name, 'the name they proved is the name they get');
        $this->assertSame('Okafor', (string) $donor->last_name);
    }

    /**
     * Records what a second submission can and cannot do to a claim, so the
     * trade the fix makes is written down rather than inferred: an addition is
     * refused along with a contradiction.
     */
    public function test_a_second_submission_adding_a_surname_is_refused_not_taken(): void
    {
        $email = 'claim-add-' . uniqid() . '@example.test';
        $sent  = $this->mailbox();

        $this->register(['email' => $email, 'first_name' => 'Alice']);
        $this->runPendingAsyncJobs();
        $link = $this->tokenIn((string) $sent[0]);

        $this->register(['email' => $email, 'first_name' => 'Alice', 'last_name' => 'Okafor']);

        $donorId = $this->c()->get(SignupRedemption::class)->redeem($link);
        $donor   = Donor::query()->where('id', $donorId)->get();

        $this->assertNotNull($donor);
        $this->assertSame('Alice', (string) $donor->first_name, 'the agreed name stands');
        $this->assertSame('', (string) $donor->last_name, 'and the addition is not taken from an unproven caller');
    }
}
