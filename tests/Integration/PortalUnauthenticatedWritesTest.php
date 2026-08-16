<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Analytics\ErrorLog;
use Dono\Analytics\Event;
use Dono\Donations\AntiSpamGuard;
use Dono\Donors\Donor;
use Dono\Donors\DonorMetricsService;
use Dono\Donors\DonorService;
use Dono\Donors\MagicLinkService;
use Dono\Donors\MagicLinkToken;
use Dono\Donors\PendingSignup;
use Dono\Donors\PendingSignupRepository;
use Dono\Donors\Portal\PortalSession;
use Dono\Donors\SignupRedemption;
use Dono\Foundation\Identity\IdentityHasher;
use Dono\Foundation\Plugin;
use WP_REST_Request;

/**
 * The three portal routes that take no session: /portal/send-link,
 * /portal/register and /portal/exchange. They are the only unauthenticated
 * writes in the plugin outside a gateway webhook, so what a stranger can do
 * with them is the whole of their contract.
 *
 * The portal token they ask for proves the caller loaded a page, nothing more:
 * it is a day-bucket HMAC printed into every portal page and every donation
 * form, so a third party has one for the asking. Everything below assumes the
 * caller holds it.
 */
final class PortalUnauthenticatedWritesTest extends IntegrationTestCase
{
    private function container()
    {
        return Plugin::instance()->container;
    }

    private function token(): string
    {
        return $this->container()->get(AntiSpamGuard::class)->mintPortalToken();
    }

    /**
     * @param array<string,mixed> $body
     * @param array<string,string> $headers
     */
    private function post(string $route, array $body, array $headers = []): \WP_REST_Response|\WP_Error
    {
        $req = new WP_REST_Request('POST', '/dono/v1/portal/' . $route);
        $req->set_header('content-type', 'application/json');
        foreach ($headers as $name => $value) {
            $req->set_header($name, $value);
        }
        $req->set_body((string) wp_json_encode($body + ['token' => $this->token()]));

        return rest_do_request($req);
    }

    /** What a browser puts on the portal page's own fetch. */
    private function browserHeaders(): array
    {
        return ['Sec-Fetch-Site' => 'same-origin', 'Origin' => home_url()];
    }

    private function hash(string $email): string
    {
        return $this->container()->get(IdentityHasher::class)->emailHash($email);
    }

    private function donor(string $email): Donor
    {
        return $this->container()->get(DonorService::class)->findOrCreate($email);
    }

    private function claim(string $email): ?PendingSignup
    {
        return $this->container()->get(PendingSignupRepository::class)->findByEmailHash($this->hash($email));
    }

    /** Mails captured as the job sends them. */
    private function captureLinkMails(): \ArrayObject
    {
        $sent = new \ArrayObject();
        add_filter('pre_wp_mail', function ($null, $atts) use ($sent) {
            $sent[] = ['to' => $atts['to'] ?? '', 'body' => $atts['message'] ?? ''];
            return false;
        }, 10, 2);

        return $sent;
    }

    /** The raw token out of the link the donor actually received. */
    private function tokenFromMail(string $body): string
    {
        $this->assertSame(1, preg_match('/[?&]token=([A-Za-z0-9_\-]+)/', html_entity_decode($body), $m), 'the mail carries a link');

        return $m[1];
    }

    /** @return list<MagicLinkToken> */
    private function livePortalTokens(int $donorId): array
    {
        return MagicLinkToken::query()
            ->where('donor_id', $donorId)
            ->where('purpose', PortalSession::PORTAL_PURPOSE)
            ->whereIsNull('used_at')
            ->getAll();
    }

    /**
     * The rate key and the identity key have to name the same thing. Keyed on
     * the mailbox while the job resolves the donor by the literal address, an
     * alias of a donor's address spends the donor's allowance and mails nobody:
     * the donor is told a link is on its way, no link is sent, and the org sees
     * a 200.
     */
    public function test_an_alias_of_a_donors_address_cannot_spend_their_sign_in_quota(): void
    {
        $email = 'quota-victim-' . uniqid() . '@example.test';
        $at    = strpos($email, '@');
        $this->donor($email);

        $sent = $this->captureLinkMails();

        // Enough aliases to exhaust the per-address allowance outright, so the
        // donor's own address is refused unless its key is genuinely its own.
        for ($i = 0; $i < 4; $i++) {
            $alias = substr($email, 0, $at) . '+attacker' . $i . substr($email, $at);
            $this->assertSame(200, $this->post('send-link', ['email' => $alias])->get_status());
            $this->runPendingAsyncJobs();
        }
        $this->assertCount(0, $sent, 'an address that is nobody mails nobody');

        $this->assertSame(200, $this->post('send-link', ['email' => $email])->get_status());
        $this->runPendingAsyncJobs();

        $this->assertCount(1, $sent, 'the donor still gets the link they asked for');
        $this->assertSame($email, (string) $sent[0]['to']);
    }

    /**
     * The limit that matters is the one on the inbox, and it is spent where the
     * mail is: five links to one mailbox in the window, however many addresses
     * they were asked for under.
     */
    public function test_the_mailbox_limit_still_bounds_what_reaches_one_inbox(): void
    {
        $mailbox = 'flood-' . uniqid() . '@example.test';
        $at      = strpos($mailbox, '@');

        $sent = $this->captureLinkMails();

        // Every plus tag is a distinct address and a distinct claim, and all of
        // them land in one inbox. Per-address counting cannot see that.
        for ($i = 0; $i < 8; $i++) {
            $this->post('register', [
                'email' => substr($mailbox, 0, $at) . '+' . $i . substr($mailbox, $at),
            ]);
            $this->runPendingAsyncJobs();
        }

        $this->assertLessThanOrEqual(5, count($sent), 'one inbox is not a mailing list');
        $this->assertGreaterThan(0, count($sent), 'and a real signup is still served');
    }

    /**
     * Read-then-write lets concurrent callers all read the last allowed value
     * and all write it back. A counter that is raised before it is judged keeps
     * climbing past the ceiling, which is what this reads: the stored value
     * counts refused attempts too.
     */
    public function test_every_send_link_attempt_is_counted_not_only_the_allowed_ones(): void
    {
        global $wpdb;
        $email = 'counted-' . uniqid() . '@example.test';

        for ($i = 0; $i < 5; $i++) {
            $this->post('send-link', ['email' => $email]);
        }

        $stored = (int) $wpdb->get_var(
            "SELECT option_value FROM {$wpdb->options}
             WHERE option_name LIKE '\_transient\_dono\_send\_link\_addr\_%'
             ORDER BY option_id DESC LIMIT 1"
        );

        $this->assertSame(5, $stored, 'the counter is atomic and counts every attempt');
    }

    /**
     * A suppressed sign-in link is silent to the donor waiting for it, so the
     * org has to be able to see that it happened.
     */
    public function test_hitting_the_mailbox_limit_is_recorded_where_the_org_can_see_it(): void
    {
        $mailbox = 'logged-' . uniqid() . '@example.test';
        $at      = strpos($mailbox, '@');

        for ($i = 0; $i < 7; $i++) {
            $this->post('register', [
                'email' => substr($mailbox, 0, $at) . '+' . $i . substr($mailbox, $at),
            ]);
            $this->runPendingAsyncJobs();
        }

        $logged = Event::query()
            ->whereLike('type', ErrorLog::PREFIX . 'portal.send_link')
            ->getAll();

        $this->assertCount(1, $logged, 'once per window, not once per attempt behind it');
    }

    /**
     * The same property on the per-IP counter, which is the one a burst hits
     * first. Checking before counting is what makes a limit raceable: the
     * stored value pins at the ceiling because only allowed attempts write.
     */
    public function test_the_ip_counter_climbs_past_its_own_ceiling(): void
    {
        global $wpdb;

        for ($i = 0; $i < 12; $i++) {
            $this->post('send-link', ['email' => 'burst-' . $i . '-' . uniqid() . '@example.test']);
        }

        $stored = (int) $wpdb->get_var(
            "SELECT option_value FROM {$wpdb->options}
             WHERE option_name LIKE '\_transient\_dono\_send\_link\_ip\_%'
             ORDER BY option_id DESC LIMIT 1"
        );

        $this->assertSame(12, $stored, 'refused attempts are counted too, so the count cannot be raced');
    }

    /**
     * An emailed link is a bearer credential. Thirty days of it sitting in a
     * mailbox, a forwarded thread or a support ticket is thirty days of anyone
     * who reads it holding the donor's history, their statements and their
     * recurring donation.
     */
    public function test_a_sign_in_link_expires_within_the_hour(): void
    {
        $email = 'ttl-' . uniqid() . '@example.test';
        $donor = $this->donor($email);

        $this->post('send-link', ['email' => $email]);
        $this->runPendingAsyncJobs();

        $tokens = $this->livePortalTokens((int) $donor->id);
        $this->assertCount(1, $tokens);
        $this->assertLessThanOrEqual(
            time() + HOUR_IN_SECONDS + 60,
            strtotime((string) $tokens[0]->expires_at),
            'the emailed sign-in link is short lived'
        );
    }

    /**
     * The mail has to name the lifetime the token actually has. One template
     * serves the portal link and the signup claim, so a fixed sentence is
     * wrong for at least one of them, and a donor told to take their time is a
     * donor whose key dies before they use it.
     */
    public function test_the_mail_names_the_lifetime_the_link_actually_has(): void
    {
        $sent = $this->captureLinkMails();

        $donorEmail = 'expiry-donor-' . uniqid() . '@example.test';
        $this->donor($donorEmail);
        $this->post('send-link', ['email' => $donorEmail]);
        $this->runPendingAsyncJobs();

        $this->assertCount(1, $sent);
        $this->assertStringContainsString('1 hour', (string) $sent[0]['body'], 'the portal link is an hour long');
        $this->assertStringNotContainsString('30 days', (string) $sent[0]['body']);

        $claimEmail = 'expiry-claim-' . uniqid() . '@example.test';
        $this->post('register', ['email' => $claimEmail, 'first_name' => 'Ada']);
        $this->runPendingAsyncJobs();

        $this->assertCount(2, $sent);
        $this->assertStringContainsString('1 week', (string) $sent[1]['body'], 'the claim link runs a week');
        $this->assertStringNotContainsString('30 days', (string) $sent[1]['body']);
    }

    /**
     * A donor whose mailbox limit is spent is helped by support minting a link
     * for them, which is the fallback that makes the flood limit survivable. It
     * is not a fallback if any stranger who knows the address can delete it by
     * posting to an endpoint that proves nothing about who is calling.
     */
    public function test_an_anonymous_request_cannot_revoke_the_link_support_issued(): void
    {
        $email = 'fallback-' . uniqid() . '@example.test';
        $donor = $this->donor($email);

        $link = $this->container()->get(DonorMetricsService::class)->issuePortalLink($donor);
        $this->assertIsArray($link);
        parse_str((string) wp_parse_url((string) $link['url'], PHP_URL_QUERY), $query);
        $staffToken = (string) ($query['token'] ?? '');
        $this->assertNotSame('', $staffToken);

        $this->post('send-link', ['email' => $email]);
        $this->runPendingAsyncJobs();

        $this->assertNotNull(
            $this->container()->get(PortalSession::class)->startFromToken($staffToken),
            'the link support read out over the phone still signs the donor in'
        );
    }

    /**
     * The same property for the donor themselves: clicking resend and then
     * opening the first mail is not an attack, and the client strips the token
     * from the URL before exchanging, so a refused link is a dead end.
     */
    public function test_a_resend_does_not_kill_the_link_already_in_the_mailbox(): void
    {
        $email = 'resend-' . uniqid() . '@example.test';
        $donor = $this->donor($email);

        $this->post('send-link', ['email' => $email]);
        $this->runPendingAsyncJobs();
        $first = $this->livePortalTokens((int) $donor->id);
        $this->assertCount(1, $first);

        $this->post('send-link', ['email' => $email]);
        $this->runPendingAsyncJobs();

        $live = array_map(static fn ($t) => (int) $t->id, $this->livePortalTokens((int) $donor->id));
        $this->assertContains((int) $first[0]->id, $live, 'the first mail still works');
        $this->assertCount(2, $live);
    }

    /**
     * Signing out everywhere has to mean it. Ending the sessions and leaving an
     * unredeemed link live leaves the door the donor came to shut. Driven
     * through the route rather than the service, because the route is what the
     * donor's button reaches and nothing else revokes an unopened link.
     */
    public function test_signing_out_everywhere_kills_links_that_were_never_clicked(): void
    {
        $email = 'revoke-' . uniqid() . '@example.test';
        $donor = $this->donor($email);

        $raw = $this->container()->get(MagicLinkService::class)
            ->issue((int) $donor->id, PortalSession::PORTAL_PURPOSE, null, HOUR_IN_SECONDS);

        $session = $this->container()->get(PortalSession::class);
        $session->open((int) $donor->id);

        $csrf = bin2hex(random_bytes(8));
        $_COOKIE['dono_donor_session'] = $this->portalSession((int) $donor->id, $csrf);

        try {
            $req = new WP_REST_Request('POST', '/dono/v1/portal/logout-everywhere');
            $req->set_header('X-Dono-Csrf', $csrf);
            $res = rest_do_request($req);

            $this->assertSame(200, $res->get_status());
            $this->assertSame(1, ((array) $res->get_data())['ended'], 'the session on the other device is ended');
        } finally {
            unset($_COOKIE['dono_donor_session']);
        }

        $this->assertNull($session->startFromToken($raw), 'the unclicked link is dead too');
    }

    /**
     * A signup link names no donor and points at a claim, so a sweep keyed on
     * donor_id never sees it. Anyone can mint one against an address before it
     * is a donor, and it opens a full session once the address becomes one, so
     * the door this button exists to shut stays open unless the claim is swept
     * too.
     */
    public function test_signing_out_everywhere_kills_a_signup_link_minted_before_the_donor_existed(): void
    {
        $email = 'presignup-' . uniqid() . '@example.test';

        // A stranger claims the address while it belongs to nobody.
        $this->post('register', ['email' => $email, 'first_name' => 'Mallory']);
        $this->runPendingAsyncJobs();
        $stranger = MagicLinkToken::query()->where('purpose', SignupRedemption::PURPOSE)->get();
        $this->assertNotNull($stranger, 'the stranger holds a signup link');

        // The real owner becomes a donor by giving, never having signed up.
        $donor   = $this->donor($email);
        $session = $this->container()->get(PortalSession::class);

        $session->destroyAllFor((int) $donor->id);

        $this->assertNull(
            MagicLinkToken::query()->where('id', (int) $stranger->id)->get(),
            'the signup link against this address goes with the sessions'
        );
    }

    /**
     * WP honours _method on a GET, so an unguarded write route is reachable by
     * a plain link in an email or a forum post: no form, no fetch, no CORS.
     * Planting a name on a stranger's pending signup is exactly what that
     * delivers.
     */
    public function test_a_get_dressed_as_a_post_cannot_plant_a_claim(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? null;
        $_SERVER['REQUEST_METHOD'] = 'GET';

        try {
            $email = 'link-attack-' . uniqid() . '@example.test';

            $req = new WP_REST_Request('POST', '/dono/v1/portal/register');
            $req->set_query_params([
                '_method'    => 'POST',
                'email'      => $email,
                'first_name' => 'Mallory',
                'token'      => $this->token(),
            ]);

            $res = rest_do_request($req);

            $this->assertSame(403, $res->get_status());
            $this->assertNull($this->claim($email), 'and nothing was written');
        } finally {
            if ($method === null) {
                unset($_SERVER['REQUEST_METHOD']);
            } else {
                $_SERVER['REQUEST_METHOD'] = $method;
            }
        }
    }

    /**
     * The guard on the two writing routes has to let the page they exist for
     * through: the portal and the donation form post with fetch from a page on
     * this site, which the browser labels same-origin and stamps with an Origin
     * of this site.
     */
    public function test_the_portals_own_signup_and_resend_still_work(): void
    {
        $email = 'same-origin-' . uniqid() . '@example.test';

        $signup = $this->post('register', ['email' => $email, 'first_name' => 'Alice'], $this->browserHeaders());
        $this->assertSame(200, $signup->get_status());
        $this->assertNotNull($this->claim($email), 'the signup the donor typed is written');

        $resend = $this->post('send-link', ['email' => $email], $this->browserHeaders());
        $this->assertSame(200, $resend->get_status());
    }

    /**
     * A stranger can put a second mail in somebody's inbox, and a donor who
     * opens that one instead of their own is created under the name it carried.
     * The way back is the portal itself, so that path is asserted rather than
     * assumed: whoever proves the mailbox owns the record from there.
     */
    public function test_the_donor_who_proves_the_mailbox_names_themselves(): void
    {
        $email = 'recover-' . uniqid() . '@example.test';
        $sent  = $this->captureLinkMails();

        $this->post('register', ['email' => $email, 'first_name' => 'Mallory', 'last_name' => 'Attacker']);
        $this->runPendingAsyncJobs();

        $donorId = $this->container()->get(SignupRedemption::class)
            ->redeem($this->tokenFromMail((string) $sent[0]['body']));
        $this->assertGreaterThan(0, $donorId, 'the link redeems');

        $csrf = bin2hex(random_bytes(8));
        $_COOKIE['dono_donor_session'] = $this->portalSession($donorId, $csrf);

        try {
            $req = new WP_REST_Request('POST', '/dono/v1/portal/profile');
            $req->set_header('content-type', 'application/json');
            $req->set_header('X-Dono-Csrf', $csrf);
            $req->set_body((string) wp_json_encode(['first_name' => 'Alice', 'last_name' => 'Okafor']));

            $this->assertSame(200, rest_do_request($req)->get_status());
        } finally {
            unset($_COOKIE['dono_donor_session']);
        }

        $donor = Donor::query()->where('id', $donorId)->get();
        $this->assertSame('Alice', (string) $donor->first_name);
        $this->assertSame('Okafor', (string) $donor->last_name);
    }

    /** @return \WP_REST_Response|\WP_Error */
    private function exchange(string $rawToken, array $headers = [], array $query = [])
    {
        $req = new WP_REST_Request('POST', '/dono/v1/portal/exchange');
        $req->set_header('content-type', 'application/json');
        foreach ($headers as $name => $value) {
            $req->set_header($name, $value);
        }
        if ($query !== []) {
            $req->set_query_params($query);
        }
        $req->set_body((string) wp_json_encode(['token' => $rawToken]));

        return rest_do_request($req);
    }

    private function portalLinkFor(string $email): string
    {
        return $this->container()->get(MagicLinkService::class)
            ->issue((int) $this->donor($email)->id, PortalSession::PORTAL_PURPOSE, null, HOUR_IN_SECONDS);
    }

    /**
     * Exchanging a token sets the session cookie, so a forged cross-site POST
     * signs the visitor into whichever account the attacker's token names, and
     * /portal/me then hands them the CSRF token that guards every write. The
     * page is cached, so a nonce cannot be the guard; the browser's own label
     * on the request is.
     */
    public function test_a_cross_site_post_cannot_open_a_session(): void
    {
        $raw = $this->portalLinkFor('csrf-' . uniqid() . '@example.test');

        $res = $this->exchange($raw, ['Sec-Fetch-Site' => 'cross-site']);

        $this->assertSame(403, $res->get_status());
        $this->assertNotNull(
            $this->container()->get(PortalSession::class)->startFromToken($raw),
            "and the refused attempt does not burn the donor's own link"
        );
    }

    public function test_a_foreign_origin_cannot_open_a_session(): void
    {
        $raw = $this->portalLinkFor('origin-' . uniqid() . '@example.test');

        $res = $this->exchange($raw, ['Origin' => 'https://attacker.example']);

        $this->assertSame(403, $res->get_status());
    }

    /**
     * WP honours _method on a GET, so without this the whole attack is a link:
     * no form, no fetch, no CORS, just a top-level navigation.
     */
    public function test_a_get_dressed_as_a_post_cannot_open_a_session(): void
    {
        $raw = $this->portalLinkFor('method-' . uniqid() . '@example.test');

        // The guard reads the transport's own verb, so the test states it
        // rather than borrowing whatever the harness left in $_SERVER.
        $method = $_SERVER['REQUEST_METHOD'] ?? null;
        $_SERVER['REQUEST_METHOD'] = 'GET';

        try {
            $res = $this->exchange($raw, [], ['_method' => 'POST']);
            $this->assertSame(403, $res->get_status());
        } finally {
            if ($method === null) {
                unset($_SERVER['REQUEST_METHOD']);
            } else {
                $_SERVER['REQUEST_METHOD'] = $method;
            }
        }
    }

    /**
     * On an install that answers on both www and the apex without redirecting,
     * the portal page is served from one and rest_url() names the other, so the
     * browser stamps the portal's own fetch with a host the site does not
     * recognise. The guard sits on all three unauthenticated routes, so
     * refusing that pair takes down sign-in, signup and resend together.
     */
    public function test_the_www_and_apex_pair_is_one_site(): void
    {
        $host = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
        $this->assertStringStartsNotWith('www.', $host, 'the fixture host is the apex');
        $partner = ['Sec-Fetch-Site' => 'same-site', 'Origin' => 'https://www.' . $host];

        $raw = $this->portalLinkFor('aliased-' . uniqid() . '@example.test');
        $this->assertSame(200, $this->exchange($raw, $partner)->get_status(), 'the donor can still sign in');

        $email = 'aliased-signup-' . uniqid() . '@example.test';
        $this->assertSame(200, $this->post('register', ['email' => $email, 'first_name' => 'Alice'], $partner)->get_status());
        $this->assertNotNull($this->claim($email), 'and can still sign up');
    }

    /**
     * The other half of that install, which the fixture host cannot show: an
     * org whose canonical address is www, serving the page from the apex. It is
     * the same outage in the opposite direction, and it needs the opposite arm
     * of the pairing.
     */
    public function test_the_pair_holds_when_the_canonical_host_is_www(): void
    {
        $host = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
        $home = get_option('home');
        $site = get_option('siteurl');
        $sent = $_SERVER['HTTP_HOST'] ?? null;

        // Every host this WordPress knows is the www one, which is what the
        // portal's own fetch reaches: it goes to rest_url, and only the page it
        // was fired from sits on the apex.
        update_option('home', 'https://www.' . $host);
        update_option('siteurl', 'https://www.' . $host);
        $_SERVER['HTTP_HOST'] = 'www.' . $host;

        try {
            $raw = $this->portalLinkFor('www-canonical-' . uniqid() . '@example.test');
            $res = $this->exchange($raw, ['Sec-Fetch-Site' => 'same-site', 'Origin' => 'https://' . $host]);

            $this->assertSame(200, $res->get_status(), 'the apex is not a stranger to a www install');
        } finally {
            update_option('home', $home);
            update_option('siteurl', $site);
            if ($sent === null) {
                unset($_SERVER['HTTP_HOST']);
            } else {
                $_SERVER['HTTP_HOST'] = $sent;
            }
        }
    }

    /**
     * WordPress answers on site_url too, and it is a different host from
     * home_url on more installs than the docs suggest. A portal page reached
     * through it posts with that Origin.
     */
    public function test_the_host_wordpress_itself_runs_on_is_this_site(): void
    {
        $siteUrl = get_option('siteurl');
        update_option('siteurl', 'https://cms.example.org');

        try {
            $raw = $this->portalLinkFor('siteurl-' . uniqid() . '@example.test');
            $res = $this->exchange($raw, ['Sec-Fetch-Site' => 'same-site', 'Origin' => 'https://cms.example.org']);

            $this->assertSame(200, $res->get_status());
        } finally {
            update_option('siteurl', $siteUrl);
        }
    }

    /**
     * The third host in the list, and the one neither option can name: behind a
     * reverse proxy or on a mapped domain, WordPress stores one address and the
     * browser reaches it on another, so the Origin on the portal's own fetch
     * matches neither home_url nor site_url. Refusing it takes sign-in, signup
     * and resend down together on exactly those installs.
     */
    public function test_the_host_the_request_arrived_on_is_this_site(): void
    {
        $mapped = 'portal.mapped.test';
        $sent   = $_SERVER['HTTP_HOST'] ?? null;

        foreach ([home_url(), site_url()] as $known) {
            $this->assertNotSame(
                $mapped,
                strtolower((string) wp_parse_url($known, PHP_URL_HOST)),
                'the mapped host has to be one WordPress does not already know'
            );
        }

        $_SERVER['HTTP_HOST'] = $mapped;

        try {
            $raw = $this->portalLinkFor('mapped-' . uniqid() . '@example.test');
            $res = $this->exchange($raw, ['Sec-Fetch-Site' => 'same-site', 'Origin' => 'https://' . $mapped]);

            $this->assertSame(200, $res->get_status(), 'the host the browser reached is not a stranger');
        } finally {
            if ($sent === null) {
                unset($_SERVER['HTTP_HOST']);
            } else {
                $_SERVER['HTTP_HOST'] = $sent;
            }
        }
    }

    /**
     * The port is the browser's to vary and not part of the site's identity, so
     * a request that arrives on one is still the host it names.
     */
    public function test_a_port_on_the_arriving_host_is_not_a_different_site(): void
    {
        $mapped = 'portal.mapped.test';
        $sent   = $_SERVER['HTTP_HOST'] ?? null;

        $_SERVER['HTTP_HOST'] = $mapped . ':8443';

        try {
            $raw = $this->portalLinkFor('mapped-port-' . uniqid() . '@example.test');
            $res = $this->exchange($raw, ['Sec-Fetch-Site' => 'same-site', 'Origin' => 'https://' . $mapped . ':8443']);

            $this->assertSame(200, $res->get_status());
        } finally {
            if ($sent === null) {
                unset($_SERVER['HTTP_HOST']);
            } else {
                $_SERVER['HTTP_HOST'] = $sent;
            }
        }
    }

    /**
     * The pairing is that one label and nothing else. A sibling subdomain is a
     * different site, and on shared hosting somebody else's.
     */
    public function test_a_sibling_subdomain_is_not_this_site(): void
    {
        $host = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));

        $raw = $this->portalLinkFor('sibling-' . uniqid() . '@example.test');
        $res = $this->exchange($raw, ['Sec-Fetch-Site' => 'same-site', 'Origin' => 'https://blog.' . $host]);

        $this->assertSame(403, $res->get_status());
    }

    /** The portal's own call is same-origin and must keep working. */
    public function test_the_portals_own_exchange_still_works(): void
    {
        $email = 'ok-' . uniqid() . '@example.test';
        $donor = $this->donor($email);
        $raw   = $this->container()->get(MagicLinkService::class)
            ->issue((int) $donor->id, PortalSession::PORTAL_PURPOSE, null, HOUR_IN_SECONDS);

        $res = $this->exchange($raw, [
            'Sec-Fetch-Site' => 'same-origin',
            'Origin'         => home_url(),
        ]);

        $this->assertSame(200, $res->get_status());
        $this->assertSame((int) $donor->id, ((array) $res->get_data())['donor_id']);
    }
}
