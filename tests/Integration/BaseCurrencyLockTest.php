<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\Donation;
use Dono\Donors\DonorService;
use Dono\Foundation\Plugin;
use WP_REST_Request;

/**
 * base_amount_cents is stored in the org's base currency and nothing restates
 * it. Changing the base after money has come in rereads every historical total,
 * report and year-end statement at the new currency's face value.
 */
final class BaseCurrencyLockTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        update_option('dono_currency_locale', ['default_currency' => 'EUR', 'supported_currencies' => ['EUR']], false);
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    private function donation(bool $isTest): void
    {
        $d = Donation::make();
        $d->reference         = 'REF-' . uniqid();
        $d->status            = 'paid';
        $d->gateway           = 'offline';
        $d->kind              = 'donation';
        $d->amount_cents      = 5000;
        $d->base_amount_cents = 5000;
        $d->currency          = 'EUR';
        $d->is_test           = $isTest;
        $d->donor_id          = (int) Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate('base-' . uniqid() . '@example.test')->id;
        $d->created_at        = gmdate('Y-m-d H:i:s');
        $d->paid_at           = gmdate('Y-m-d H:i:s');
        $d->save();
    }

    private function save(array $body)
    {
        $req = new WP_REST_Request('POST', '/dono/v1/admin/settings/currency-locale');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode($body));

        return rest_do_request($req);
    }

    private function stored(): string
    {
        $opt = get_option('dono_currency_locale');

        return (string) ($opt['default_currency'] ?? '');
    }

    public function test_the_base_can_be_set_before_any_donation(): void
    {
        $this->assertSame(200, $this->save(['default_currency' => 'GBP'])->get_status());
        $this->assertSame('GBP', $this->stored());
    }

    public function test_test_mode_donations_do_not_lock_it(): void
    {
        $this->donation(true);

        $this->assertSame(200, $this->save(['default_currency' => 'GBP'])->get_status());
        $this->assertSame('GBP', $this->stored());
    }

    public function test_a_live_donation_locks_it(): void
    {
        $this->donation(false);

        $res = $this->save(['default_currency' => 'GBP']);
        $this->assertSame(409, $res->get_status());
        $this->assertSame('EUR', $this->stored(), 'the stored base is untouched');
    }

    public function test_the_rest_of_the_group_still_saves(): void
    {
        $this->donation(false);

        $res = $this->save(['default_currency' => 'EUR', 'supported_currencies' => ['EUR', 'USD']]);
        $this->assertSame(200, $res->get_status(), 'resending the same base is not a change');
        $this->assertSame(['EUR', 'USD'], get_option('dono_currency_locale')['supported_currencies']);
    }

    public function test_the_screen_is_told_it_is_locked(): void
    {
        $this->donation(false);

        $res = rest_do_request(new WP_REST_Request('GET', '/dono/v1/admin/settings/currency-locale'));
        $this->assertTrue($res->get_data()['base_currency_locked']);
    }
}
