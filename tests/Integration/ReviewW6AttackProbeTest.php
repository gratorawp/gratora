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
 * Independent probe of the claimed property, written against the mail a
 * mailbox actually receives rather than against any row: register, drain the
 * queue so the mail is issued, let a stranger register the same address, then
 * redeem the token parsed out of the victim's own message and read the donor
 * back by address.
 */
final class ReviewW6AttackProbeTest extends IntegrationTestCase
{
    /** @param array<string,mixed> $body */
    private function postRegister(string $email, ?string $first, ?string $last): int
    {
        $guard = Plugin::instance()->container->get(AntiSpamGuard::class);

        $req = new WP_REST_Request('POST', '/dono/v1/portal/register');
        $req->set_header('content-type', 'application/json');
        $body = ['email' => $email, 'token' => $guard->mintPortalToken()];
        if ($first !== null) $body['first_name'] = $first;
        if ($last !== null)  $body['last_name']  = $last;
        $req->set_body((string) wp_json_encode($body));

        return rest_do_request($req)->get_status();
    }

    private function linkFrom(\ArrayObject $mails, int $index): string
    {
        $this->assertGreaterThan($index, count($mails), "no mail at index {$index}");
        $body = html_entity_decode((string) $mails[$index]['message']);
        $this->assertSame(1, preg_match('/token=([0-9a-f]{48})/', $body, $m), 'no signup token in the mail body');

        return $m[1];
    }

    private function donorFor(string $email): ?Donor
    {
        $c      = Plugin::instance()->container;
        $hasher = $c->get(\Dono\Foundation\Identity\IdentityHasher::class);

        return $c->get(\Dono\Donors\DonorRepository::class)
            ->findByEmailHash($hasher->emailHash($hasher->normalizeEmail($email)));
    }

    /**
     * The attack, driven end to end. Whatever the stranger posts afterwards,
     * the victim's own link writes the victim's own name.
     */
    public function test_probe_the_victims_link_writes_the_victims_name_after_the_attack(): void
    {
        $email = 'probe-victim-' . uniqid() . '@example.test';
        $mails = $this->captureMails();

        $this->assertSame(200, $this->postRegister($email, 'Alice', 'Okafor'));
        $this->runPendingAsyncJobs();
        $victimLink = $this->linkFrom($mails, 0);

        $this->assertSame(200, $this->postRegister($email, 'Mallory', 'Attacker'));
        $this->runPendingAsyncJobs();

        $id = Plugin::instance()->container->get(SignupRedemption::class)->redeem($victimLink);
        $this->assertGreaterThan(0, $id, 'the victim link still redeems');

        $donor = $this->donorFor($email);
        $this->assertNotNull($donor);
        $this->assertSame('Alice', (string) $donor->first_name);
        $this->assertSame('Okafor', (string) $donor->last_name);
    }

    /** The same with the stranger first, so no ordering rescues the property. */
    public function test_probe_a_stranger_registering_first_does_not_steer_the_victims_link(): void
    {
        $email = 'probe-first-' . uniqid() . '@example.test';
        $mails = $this->captureMails();

        $this->assertSame(200, $this->postRegister($email, 'Mallory', 'Attacker'));
        $this->runPendingAsyncJobs();

        $this->assertSame(200, $this->postRegister($email, 'Alice', 'Okafor'));
        $this->runPendingAsyncJobs();

        $this->assertCount(2, $mails, 'both registrations mailed the mailbox');

        $id = Plugin::instance()->container->get(SignupRedemption::class)->redeem($this->linkFrom($mails, 1));
        $this->assertGreaterThan(0, $id);

        $donor = $this->donorFor($email);
        $this->assertNotNull($donor);
        $this->assertSame('Alice', (string) $donor->first_name);
        $this->assertSame('Okafor', (string) $donor->last_name);
    }

    /** What the attacker does own: their own link, and nothing past it. */
    public function test_probe_the_attackers_link_writes_only_the_attackers_name(): void
    {
        $email = 'probe-attacker-' . uniqid() . '@example.test';
        $mails = $this->captureMails();

        $this->assertSame(200, $this->postRegister($email, 'Alice', 'Okafor'));
        $this->runPendingAsyncJobs();

        $this->assertSame(200, $this->postRegister($email, 'Mallory', 'Attacker'));
        $this->runPendingAsyncJobs();

        $id = Plugin::instance()->container->get(SignupRedemption::class)->redeem($this->linkFrom($mails, 1));
        $this->assertGreaterThan(0, $id);

        $donor = $this->donorFor($email);
        $this->assertNotNull($donor);
        $this->assertSame('Mallory', (string) $donor->first_name);
    }

    /**
     * A donor who comes back to add a surname gets it, which the shared row
     * could not give without also handing it to a stranger.
     */
    public function test_probe_a_second_registration_may_add_a_surname_for_itself(): void
    {
        $email = 'probe-surname-' . uniqid() . '@example.test';
        $mails = $this->captureMails();

        $this->assertSame(200, $this->postRegister($email, 'Alice', null));
        $this->runPendingAsyncJobs();

        $this->assertSame(200, $this->postRegister($email, 'Alice', 'Okafor'));
        $this->runPendingAsyncJobs();

        $id = Plugin::instance()->container->get(SignupRedemption::class)->redeem($this->linkFrom($mails, 1));
        $this->assertGreaterThan(0, $id);

        $donor = $this->donorFor($email);
        $this->assertNotNull($donor);
        $this->assertSame('Alice', (string) $donor->first_name);
        $this->assertSame('Okafor', (string) $donor->last_name);
    }

    /** Two registrations, two tokens, each holding what its own caller typed. */
    public function test_probe_each_registration_mints_a_token_carrying_its_own_name(): void
    {
        $email = 'probe-tokens-' . uniqid() . '@example.test';
        $this->captureMails();

        $this->postRegister($email, 'Alice', 'Okafor');
        $this->runPendingAsyncJobs();
        $this->postRegister($email, 'Mallory', 'Attacker');
        $this->runPendingAsyncJobs();

        $tokens = MagicLinkToken::query()
            ->where('purpose', SignupRedemption::PURPOSE)
            ->orderBy('id')
            ->getAll();

        $names = array_map(
            static fn ($t): string => (string) $t->first_name . ' ' . (string) $t->last_name,
            array_values($tokens)
        );

        $this->assertContains('Alice Okafor', $names);
        $this->assertContains('Mallory Attacker', $names);
    }
}
