<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\AntiSpamGuard;
use Dono\Donors\Donor;
use Dono\Foundation\Identity\IdentityHasher;
use Dono\Donors\PendingSignupRepository;
use Dono\Foundation\Plugin;
use WP_REST_Request;

/**
 * Signing up and asking for a link are the only writes on the portal with no
 * session behind them, so both ask the caller to prove they loaded the page.
 *
 * The proof is the same HMAC day-bucket token the donation form has always
 * required, and for the same reason: these endpoints write a donor row and send
 * mail on demand. It is a coarse bucket so a page-cached portal keeps working,
 * which means it costs a determined attacker one GET. What it stops is the
 * traffic that never loads the page at all.
 */
final class PortalSignupGuardTest extends IntegrationTestCase
{
    private function guard(): AntiSpamGuard
    {
        return Plugin::instance()->container->get(AntiSpamGuard::class);
    }

    /** @param array<string,mixed> $body */
    private function post(string $route, array $body): \WP_REST_Response|\WP_Error
    {
        $req = new WP_REST_Request('POST', '/dono/v1/portal/' . $route);
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode($body));

        return rest_do_request($req);
    }

    private function donorExists(string $email): bool
    {
        return Donor::query()
            ->where('email_hash', Plugin::instance()->container->get(IdentityHasher::class)->emailHash($email))
            ->get() !== null;
    }

    public function test_a_signup_with_no_token_is_refused_and_writes_nothing(): void
    {
        $email = 'no-token-' . uniqid() . '@example.test';

        $res = $this->post('register', ['email' => $email]);

        $this->assertSame(400, $res->get_status());
        $this->assertFalse($this->donorExists($email), 'a refused signup must not mint a donor');
    }

    public function test_a_signup_with_a_forged_token_is_refused(): void
    {
        $email = 'forged-' . uniqid() . '@example.test';

        $res = $this->post('register', ['email' => $email, 'token' => '20000.deadbeef']);

        $this->assertSame(400, $res->get_status());
        $this->assertFalse($this->donorExists($email));
    }

    public function test_a_signup_from_the_portal_page_records_a_claim(): void
    {
        $email = 'with-token-' . uniqid() . '@example.test';

        $res = $this->post('register', [
            'email'      => $email,
            'first_name' => 'Ada',
            'token'      => $this->guard()->mintPortalToken(),
        ]);

        $this->assertSame(200, $res->get_status());
        $this->assertFalse($this->donorExists($email), 'an unproven address is not a donor');
        $this->assertNotNull(
            Plugin::instance()->container->get(PendingSignupRepository::class)->findByEmailHash(
                Plugin::instance()->container->get(IdentityHasher::class)->emailHash($email)
            ),
            'the claim is waiting for the link'
        );
    }

    /**
     * The donation form mints tokens too. Letting one stand in for the other
     * would mean any public form on the site opened this endpoint.
     */
    public function test_a_donation_form_token_does_not_open_the_portal(): void
    {
        $email = 'cross-' . uniqid() . '@example.test';

        $res = $this->post('register', [
            'email' => $email,
            'token' => $this->guard()->mintFormToken(1),
        ]);

        $this->assertSame(400, $res->get_status());
        $this->assertFalse($this->donorExists($email));
    }

    /** Asking for a sign-in link mails somebody, so it is gated the same way. */
    public function test_send_link_is_gated_too(): void
    {
        $this->assertSame(400, $this->post('send-link', ['email' => 'x@example.test'])->get_status());

        $this->assertSame(
            200,
            $this->post('send-link', [
                'email' => 'x@example.test',
                'token' => $this->guard()->mintPortalToken(),
            ])->get_status()
        );
    }

    /**
     * A refusal must stay silent about the address. Known and unknown both come
     * back as the same generic 400, or the gate becomes an enumeration oracle.
     */
    public function test_the_refusal_says_nothing_about_the_address(): void
    {
        $known = 'known-' . uniqid() . '@example.test';
        Plugin::instance()->container->get(\Dono\Donors\DonorService::class)->findOrCreate($known);
        $this->assertTrue($this->donorExists($known));

        $a = $this->post('send-link', ['email' => $known]);
        $b = $this->post('send-link', ['email' => 'stranger-' . uniqid() . '@example.test']);

        $this->assertSame($a->get_status(), $b->get_status());
        $this->assertSame(
            $a->as_error()?->get_error_code(),
            $b->as_error()?->get_error_code()
        );
    }
}
