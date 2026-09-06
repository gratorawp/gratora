<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Analytics\Event;
use FundKit\Donors\Consent;
use FundKit\Donors\DonorService;
use FundKit\Foundation\Identity\IdentityHasher;
use FundKit\Foundation\Plugin;
use FundKit\Settings\SettingsService;
use WP_REST_Request;

/**
 * A consent row records the address the donor agreed from, and it is the whole
 * of what the record proves about who agreed. Behind a CDN, REMOTE_ADDR is the
 * edge for every visitor, so a row that reads it directly names the same
 * address for every donor on the site and evidences nothing.
 *
 * The rate limiter has honoured the site's declared proxies since it was
 * written. The evidence has to agree with it, or the two disagree about who
 * the caller was in the same request.
 */
final class ConsentEvidenceBehindProxyTest extends IntegrationTestCase
{
    private const EDGE  = '198.51.100.9';
    private const DONOR = '203.0.113.44';

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeOfflinePayable();

        update_option('fundkit_privacy', ['trusted_proxies' => [self::EDGE . '/32']]);
        Plugin::instance()->container->get(SettingsService::class)->update('consents', [
            'purposes' => [[
                'key' => 'updates', 'label' => 'Send me updates', 'description' => '',
                'required' => false, 'default' => false, 'version' => 1,
            ]],
        ]);
        $_SERVER['REMOTE_ADDR']          = self::EDGE;
        $_SERVER['HTTP_X_FORWARDED_FOR'] = self::DONOR;
    }

    protected function tearDown(): void
    {
        unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR']);
        delete_option('fundkit_privacy');
        parent::tearDown();
    }

    private function hashOf(string $ip): string
    {
        return (string) Plugin::instance()->container->get(IdentityHasher::class)->ipHash($ip);
    }

    public function test_a_consent_row_names_the_donor_not_the_edge(): void
    {
        $this->donate(['consents' => ['updates' => true]]);

        $consent = Consent::query()->orderBy('id', 'DESC')->get();
        $this->assertNotNull($consent, 'the donation recorded a consent');

        $this->assertSame($this->hashOf(self::DONOR), (string) $consent->ip_hash);
        $this->assertNotSame($this->hashOf(self::EDGE), (string) $consent->ip_hash);
    }

    public function test_the_analytics_row_agrees_with_it(): void
    {
        Plugin::instance()->container->get(SettingsService::class)
            ->update('privacy', ['anonymize_ips' => true, 'trusted_proxies' => [self::EDGE . '/32']]);

        $this->donate([]);

        $event = Event::query()->whereIsNotNull('ip_hash')->orderBy('id', 'DESC')->get();
        $this->assertNotNull($event, 'an event carrying an address was recorded');
        $this->assertSame($this->hashOf(self::DONOR), (string) $event->ip_hash);
    }

    /** The portal writes the same evidence when a donor changes their mind. */
    public function test_a_withdrawal_from_the_portal_names_the_donor_too(): void
    {
        $donor = Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate('portal-proxy-' . uniqid() . '@example.test');

        $csrf = 'proxy-csrf';
        $_COOKIE['fundkit_donor_session'] = $this->portalSession((int) $donor->id, $csrf);

        $req = new WP_REST_Request('POST', '/fundkit/v1/portal/consents');
        $req->set_header('content-type', 'application/json');
        $req->set_header('X-FundKit-Csrf', $csrf);
        $req->set_body((string) wp_json_encode([
            'items' => [['key' => 'updates', 'granted' => true]],
        ]));
        $this->assertSame(200, rest_do_request($req)->get_status());

        unset($_COOKIE['fundkit_donor_session']);

        $consent = Consent::query()->where('donor_id', (int) $donor->id)->orderBy('id', 'DESC')->get();
        $this->assertNotNull($consent);
        $this->assertSame($this->hashOf(self::DONOR), (string) $consent->ip_hash);
    }

    /** @param array<string,mixed> $extra */
    private function donate(array $extra): void
    {
        $req = new WP_REST_Request('POST', '/fundkit/v1/donations');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode(array_merge([
            'email'        => 'proxy-donor-' . uniqid() . '@example.test',
            'amount_cents' => 2500,
            'currency'     => 'USD',
            'gateway'      => 'offline',
        ], $extra)));

        $res = rest_do_request($req);
        $this->assertContains(
            $res->get_status(),
            [200, 201],
            (string) wp_json_encode($res->get_data())
        );
    }
}
