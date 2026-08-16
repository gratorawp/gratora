<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\AntiSpamGuard;
use Dono\Donors\Donor;
use Dono\Donors\MagicLinkToken;
use Dono\Donors\SignupRedemption;
use Dono\Foundation\Plugin;
use WP_REST_Request;

/**
 * What a link does is decided by the registration that minted it, and by
 * nothing else. /portal/register takes no session and proves nothing about who
 * is calling, so anyone can post any address; the one property that has to
 * hold is that they cannot change what somebody else's link does.
 *
 * Driven end to end rather than against a row: register, run the job so the
 * mail actually goes, attack, then redeem the token parsed out of the mail the
 * victim received. A rule that holds on a row and not on the donor the link
 * creates is not the property anyone cares about.
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

    private function redeem(string $rawToken): ?Donor
    {
        $id = $this->c()->get(SignupRedemption::class)->redeem($rawToken);

        return Donor::query()->where('id', $id)->get();
    }

    /**
     * The property, and the reason the name rides the token: the victim's link
     * carries what the victim typed, whatever anyone else posts to the same
     * address afterwards. Ordering decides nothing, because there is nothing
     * shared left to reorder.
     */
    public function test_an_attacker_cannot_change_what_the_victims_own_link_does(): void
    {
        $email = 'own-link-' . uniqid() . '@example.test';
        $sent  = $this->mailbox();

        $this->register(['email' => $email, 'first_name' => 'Alice', 'last_name' => 'Okafor']);
        $this->runPendingAsyncJobs();
        $this->assertCount(1, $sent, 'the victim has their link');
        $victimLink = $this->tokenIn((string) $sent[0]);

        $this->register(['email' => $email, 'first_name' => 'Mallory', 'last_name' => 'Attacker']);
        $this->register(['email' => $email, 'first_name' => 'Mallory', 'last_name' => 'Attacker']);
        $this->runPendingAsyncJobs();

        $donor = $this->redeem($victimLink);

        $this->assertNotNull($donor, 'the victim link still redeems');
        $this->assertSame('Alice', (string) $donor->first_name, 'the name the victim typed is the name they get');
        $this->assertSame('Okafor', (string) $donor->last_name);
    }

    /**
     * The same, with the attacker first. A stranger who claims an address
     * before its owner ever visits cannot decide what the owner's own link
     * writes either.
     */
    public function test_a_stranger_who_registers_first_does_not_own_the_name(): void
    {
        $email = 'first-mover-' . uniqid() . '@example.test';
        $sent  = $this->mailbox();

        $this->register(['email' => $email, 'first_name' => 'Mallory', 'last_name' => 'Attacker']);
        $this->runPendingAsyncJobs();

        $this->register(['email' => $email, 'first_name' => 'Alice', 'last_name' => 'Okafor']);
        $this->runPendingAsyncJobs();
        $this->assertCount(2, $sent, 'both attempts mailed the mailbox');

        $donor = $this->redeem($this->tokenIn((string) $sent[1]));

        $this->assertNotNull($donor);
        $this->assertSame('Alice', (string) $donor->first_name);
        $this->assertSame('Okafor', (string) $donor->last_name);
    }

    /**
     * The first cost the shared row charged: a stranger could contest a name
     * into nothing, and the owner was created nameless off their own link.
     */
    public function test_a_stranger_cannot_blank_the_name_the_donor_typed(): void
    {
        $email = 'no-wipe-' . uniqid() . '@example.test';
        $sent  = $this->mailbox();

        $this->register(['email' => $email, 'first_name' => 'Alice', 'last_name' => 'Okafor']);
        $this->runPendingAsyncJobs();
        $victimLink = $this->tokenIn((string) $sent[0]);

        // Both shapes the old rule cleared a standing name on: a name that
        // disagrees, and a submission that names one field and leaves the
        // other blank.
        $this->register(['email' => $email, 'first_name' => 'Mallory']);
        $this->register(['email' => $email, 'last_name' => 'Attacker']);
        $this->runPendingAsyncJobs();

        $donor = $this->redeem($victimLink);

        $this->assertNotNull($donor);
        $this->assertSame('Alice', (string) $donor->first_name, 'no stranger blanks a name they cannot read');
        $this->assertSame('Okafor', (string) $donor->last_name);
    }

    /**
     * The second cost: the donor who registers with a first name, the only
     * field the form requires, and comes back to add a surname. They get what
     * they typed on the link they then click.
     */
    public function test_a_donor_adding_a_surname_gets_it_from_the_link_they_click(): void
    {
        $email = 'surname-' . uniqid() . '@example.test';
        $sent  = $this->mailbox();

        $this->register(['email' => $email, 'first_name' => 'Alice']);
        $this->runPendingAsyncJobs();

        $this->register(['email' => $email, 'first_name' => 'Alice', 'last_name' => 'Okafor']);
        $this->runPendingAsyncJobs();
        $this->assertCount(2, $sent);

        $donor = $this->redeem($this->tokenIn((string) $sent[1]));

        $this->assertNotNull($donor);
        $this->assertSame('Alice', (string) $donor->first_name);
        $this->assertSame('Okafor', (string) $donor->last_name, 'the surname they added is theirs to add');
    }

    /**
     * And the first link still says what it said when it was sent. Two live
     * links for one address are two separate answers, not one row read twice.
     */
    public function test_the_earlier_link_still_carries_what_it_was_minted_with(): void
    {
        $email = 'earlier-' . uniqid() . '@example.test';
        $sent  = $this->mailbox();

        $this->register(['email' => $email, 'first_name' => 'Alice']);
        $this->runPendingAsyncJobs();
        $firstLink = $this->tokenIn((string) $sent[0]);

        $this->register(['email' => $email, 'first_name' => 'Alice', 'last_name' => 'Okafor']);
        $this->runPendingAsyncJobs();

        $donor = $this->redeem($firstLink);

        $this->assertNotNull($donor);
        $this->assertSame('Alice', (string) $donor->first_name);
        $this->assertSame('', (string) $donor->last_name, 'the earlier link never carried a surname');
    }

    /**
     * What an attacker still gets, written down rather than discovered: a
     * second mail in somebody else's inbox, which no design can prevent. It
     * names the attacker only if the victim opens the attacker's mail instead
     * of their own, and it can still only create the account the victim was
     * signing up for.
     */
    public function test_the_attackers_own_link_is_all_their_registration_steers(): void
    {
        $email = 'attacker-link-' . uniqid() . '@example.test';
        $sent  = $this->mailbox();

        $this->register(['email' => $email, 'first_name' => 'Alice', 'last_name' => 'Okafor']);
        $this->runPendingAsyncJobs();

        $this->register(['email' => $email, 'first_name' => 'Mallory', 'last_name' => 'Attacker']);
        $this->runPendingAsyncJobs();
        $this->assertCount(2, $sent, 'both mails went to the address, which is the mailbox owner to read');

        $donor = $this->redeem($this->tokenIn((string) $sent[1]));

        $this->assertNotNull($donor);
        $this->assertSame('Mallory', (string) $donor->first_name, 'the attacker steers their own link and no other');
    }

    /**
     * The name is on the token because the claim is one row per address that
     * every registration shares. Nothing may put it back on the shared row.
     */
    public function test_each_registration_mints_its_own_token_carrying_its_own_name(): void
    {
        $email = 'per-token-' . uniqid() . '@example.test';
        $this->mailbox();

        $this->register(['email' => $email, 'first_name' => 'Alice', 'last_name' => 'Okafor']);
        $this->runPendingAsyncJobs();
        $this->register(['email' => $email, 'first_name' => 'Mallory', 'last_name' => 'Attacker']);
        $this->runPendingAsyncJobs();

        $names = array_map(
            static fn (MagicLinkToken $t): string => trim(($t->first_name ?? '') . ' ' . ($t->last_name ?? '')),
            MagicLinkToken::query()->where('purpose', SignupRedemption::PURPOSE)->getAll()
        );
        sort($names);

        $this->assertSame(['Alice Okafor', 'Mallory Attacker'], $names, 'one token per registration, one name each');
    }
}
