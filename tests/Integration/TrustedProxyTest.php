<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Foundation\Http\ClientIp;

/**
 * Reading a forwarded header without being fooled by one.
 *
 * Behind a CDN or reverse proxy REMOTE_ADDR is the proxy for every visitor, so
 * a per-address cap becomes one bucket the whole site shares: an attacker takes
 * the donation form offline for everyone at forty requests an hour, and on a
 * busy day donors lock each other out with no attacker at all.
 *
 * The obvious fix is the dangerous one. Anyone can send X-Forwarded-For, so
 * believing it turns a cap that is too small into no cap at all. Everything
 * here is about the difference between those two.
 */
final class TrustedProxyTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_CF_CONNECTING_IP']);
        delete_option('fundkit_privacy');
        remove_all_filters('fundkit.spam.trusted_proxies');
        remove_all_filters('fundkit.spam.client_ip');
        parent::tearDown();
    }

    private function trust(array $cidrs): void
    {
        update_option('fundkit_privacy', ['trusted_proxies' => $cidrs]);
    }

    private function request(string $remote, ?string $xff = null): void
    {
        $_SERVER['REMOTE_ADDR'] = $remote;
        if ($xff === null) {
            unset($_SERVER['HTTP_X_FORWARDED_FOR']);
        } else {
            $_SERVER['HTTP_X_FORWARDED_FOR'] = $xff;
        }
    }

    public function test_nothing_declared_means_nothing_believed(): void
    {
        $this->request('198.51.100.9', '203.0.113.1');

        $this->assertSame(
            '198.51.100.9',
            ClientIp::resolve(),
            'a header from an undeclared source must not move the address'
        );
    }

    /** The attack the whole design exists to refuse. */
    public function test_a_stranger_cannot_mint_an_address_by_sending_a_header(): void
    {
        $this->trust(['10.0.0.0/8']);
        $this->request('198.51.100.9', '1.2.3.4');

        $this->assertSame(
            '198.51.100.9',
            ClientIp::resolve(),
            'the caller is not a declared proxy, so its header is worth nothing'
        );
    }

    public function test_a_declared_proxy_is_believed(): void
    {
        $this->trust(['10.0.0.0/8']);
        $this->request('10.0.0.7', '203.0.113.5');

        $this->assertSame('203.0.113.5', ClientIp::resolve());
    }

    /**
     * The header is appended left to right, so the leftmost entry is whatever
     * the original caller sent. Reading from the left is the classic mistake
     * and is exactly as exploitable as believing the header outright.
     */
    public function test_a_pre_populated_leftmost_entry_does_not_win(): void
    {
        $this->trust(['10.0.0.0/8']);
        // The attacker sent "1.2.3.4"; the edge appended the address it saw.
        $this->request('10.0.0.7', '1.2.3.4, 203.0.113.5');

        $this->assertSame(
            '203.0.113.5',
            ClientIp::resolve(),
            'the hop our own edge wrote is the one to believe'
        );
    }

    public function test_a_chain_of_our_own_hops_is_walked_through(): void
    {
        $this->trust(['10.0.0.0/8', '172.16.0.0/12']);
        $this->request('10.0.0.7', '203.0.113.5, 172.16.4.4, 10.0.0.9');

        $this->assertSame('203.0.113.5', ClientIp::resolve());
    }

    /** Every hop ours, so the chain says nothing about who called. */
    public function test_an_all_trusted_chain_falls_back_to_the_proxy(): void
    {
        $this->trust(['10.0.0.0/8']);
        $this->request('10.0.0.7', '10.0.0.8, 10.0.0.9');

        $this->assertSame(
            '10.0.0.7',
            ClientIp::resolve(),
            'falling back to a constant would merge every such request into one bucket'
        );
    }

    public function test_a_malformed_header_falls_back_rather_than_collapsing(): void
    {
        $this->trust(['10.0.0.0/8']);
        $this->request('10.0.0.7', 'not-an-ip, still-not');

        $this->assertSame('10.0.0.7', ClientIp::resolve());
    }

    public function test_ports_and_brackets_are_stripped(): void
    {
        $this->trust(['10.0.0.0/8']);

        $this->request('10.0.0.7', '203.0.113.5:41234');
        $this->assertSame('203.0.113.5', ClientIp::resolve());

        $this->request('10.0.0.7', '[2001:db8::5]:443');
        $this->assertSame('2001:db8::5', ClientIp::resolve());
    }

    public function test_a_bare_ipv6_hop_survives_intact(): void
    {
        $this->trust(['10.0.0.0/8']);
        $this->request('10.0.0.7', '2001:db8::dead:beef');

        $this->assertSame('2001:db8::dead:beef', ClientIp::resolve());
    }

    /** A v4 range must never match a v6 address, or a /8 would trust the world. */
    public function test_families_do_not_cross(): void
    {
        $this->assertFalse(ClientIp::inRanges('2001:db8::1', ['10.0.0.0/8']));
        $this->assertFalse(ClientIp::inRanges('10.0.0.1', ['2001:db8::/32']));
        $this->assertTrue(ClientIp::inRanges('2001:db8::1', ['2001:db8::/32']));
    }

    public function test_a_bare_address_is_an_exact_range(): void
    {
        $this->trust(['198.51.100.9']);

        $this->request('198.51.100.9', '203.0.113.5');
        $this->assertSame('203.0.113.5', ClientIp::resolve());

        $this->request('198.51.100.10', '203.0.113.5');
        $this->assertSame('198.51.100.10', ClientIp::resolve(), 'a neighbour is not the same host');
    }

    public function test_developers_can_declare_proxies_in_code(): void
    {
        $this->request('10.0.0.7', '203.0.113.5');
        $this->assertSame('10.0.0.7', ClientIp::resolve());

        add_filter('fundkit.spam.trusted_proxies', static fn (): array => ['10.0.0.0/8']);
        $this->assertSame('203.0.113.5', ClientIp::resolve());
    }

    /** For a host that already normalises, or puts the client somewhere else. */
    public function test_a_site_can_override_the_answer_outright(): void
    {
        $this->request('10.0.0.7', '203.0.113.5');

        add_filter('fundkit.spam.client_ip', static fn (): string => '198.51.100.77');
        $this->assertSame('198.51.100.77', ClientIp::resolve());
    }

    public function test_an_override_that_is_not_an_address_is_ignored(): void
    {
        $this->request('198.51.100.9');

        add_filter('fundkit.spam.client_ip', static fn (): string => 'nonsense');
        $this->assertSame('198.51.100.9', ClientIp::resolve());
    }

    /**
     * A private REMOTE_ADDR plus a forwarded header can only mean something in
     * front is terminating the connection, so the cap is already one bucket for
     * the whole site. That is a fact about the request, not a guess.
     */
    public function test_an_undeclared_proxy_is_detectable(): void
    {
        $this->request('10.0.0.7', '203.0.113.5');
        $this->assertTrue(ClientIp::looksProxied());

        $this->trust(['10.0.0.0/8']);
        $this->assertFalse(ClientIp::looksProxied(), 'declared is not a problem to report');
    }

    /**
     * Cloudflare's edge is public, so the private-address test alone misses the
     * commonest proxied site there is. CF-Ray is on every request it proxies.
     */
    public function test_cloudflare_is_detected_and_names_its_own_fix(): void
    {
        $_SERVER['REMOTE_ADDR'] = '162.158.1.1';
        $_SERVER['HTTP_CF_RAY'] = '8a1b2c3d4e5f6789-LHR';

        $this->assertSame('cloudflare', ClientIp::undeclaredProxy());

        $this->trust(['cloudflare']);
        $this->assertNull(ClientIp::undeclaredProxy());

        unset($_SERVER['HTTP_CF_RAY']);
    }

    public function test_a_private_edge_names_its_own_fix(): void
    {
        $this->request('10.0.0.7', '203.0.113.5');

        $this->assertSame('private_ranges', ClientIp::undeclaredProxy());
    }

    /** The setting takes a word, because the people who need it do not write CIDRs. */
    public function test_the_cloudflare_keyword_trusts_cloudflares_edge(): void
    {
        $this->trust(['cloudflare']);
        $this->request('162.158.1.1', '203.0.113.5');

        $this->assertSame('203.0.113.5', ClientIp::resolve());
    }

    public function test_the_private_ranges_keyword_trusts_an_internal_edge(): void
    {
        $this->trust(['private_ranges']);

        $this->request('10.0.0.7', '203.0.113.5');
        $this->assertSame('203.0.113.5', ClientIp::resolve());

        // And says nothing about the public internet.
        $this->request('198.51.100.9', '1.2.3.4');
        $this->assertSame('198.51.100.9', ClientIp::resolve());
    }

    public function test_an_unknown_keyword_is_not_a_wildcard(): void
    {
        $this->trust(['everyone', 'all', '*']);
        $this->request('198.51.100.9', '1.2.3.4');

        $this->assertSame('198.51.100.9', ClientIp::resolve(), 'junk must never widen the list');
    }

    public function test_an_ordinary_public_request_is_not_reported(): void
    {
        $this->request('198.51.100.9');

        $this->assertFalse(ClientIp::looksProxied());
    }
}
