<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Analytics\ErrorLog;
use Dono\Analytics\Event;
use Dono\Analytics\EventRecorder;
use Dono\Core\Commands\CoreCommandProvider;
use Dono\Currency\BaseCurrencyLocked;
use Dono\Donations\Donation;
use Dono\Donors\DonorService;
use Dono\Foundation\Commands\CommandContext;
use Dono\Foundation\Commands\CommandRegistry;
use Dono\Foundation\Plugin;
use Dono\Settings\SettingsService;
use WP_REST_Request;

/**
 * The base currency lock has to hold at the write, not at one door.
 *
 * The settings REST route is not the only writer of dono_currency_locale: the
 * settings.update command reaches the same option, and the CLI and any add-on
 * can call SettingsService directly. A guard that lives in one controller lets
 * the others reread every stored base_amount_cents as a different currency
 * without restating a single row.
 */
final class BaseCurrencyLockWritersTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        update_option('dono_currency_locale', ['default_currency' => 'EUR', 'supported_currencies' => ['EUR']], false);
    }

    private function registry(): CommandRegistry
    {
        $c = Plugin::instance()->container;
        $r = new CommandRegistry($c->get(EventRecorder::class));
        (new CoreCommandProvider())->register($r, $c);
        return $r;
    }

    private function adminCtx(): CommandContext
    {
        $admin = self::factory()->user->create(['role' => 'administrator']);
        get_role('administrator')->add_cap('dono_manage_settings');
        wp_set_current_user($admin);
        return new CommandContext($admin, 'rest', 'req-' . uniqid());
    }

    private function settings(): SettingsService
    {
        return Plugin::instance()->container->get(SettingsService::class);
    }

    private function liveDonation(): void
    {
        $d = Donation::make();
        $d->reference         = 'REF-' . uniqid();
        $d->status            = 'paid';
        $d->gateway           = 'offline';
        $d->kind              = 'donation';
        $d->amount_cents      = 5000;
        $d->base_amount_cents = 5000;
        $d->currency          = 'EUR';
        $d->is_test           = false;
        $d->donor_id          = (int) Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate('lock-' . uniqid() . '@example.test')->id;
        $d->created_at        = gmdate('Y-m-d H:i:s');
        $d->paid_at           = gmdate('Y-m-d H:i:s');
        $d->save();
    }

    private function stored(): string
    {
        $opt = get_option('dono_currency_locale');

        return (string) ($opt['default_currency'] ?? '');
    }

    public function test_the_service_refuses_the_change_itself(): void
    {
        $this->liveDonation();

        try {
            $this->settings()->update('currency-locale', ['default_currency' => 'JPY']);
            $this->fail('SettingsService::update must refuse a base-currency change once live money exists');
        } catch (BaseCurrencyLocked $e) {
            $this->assertSame('EUR', $e->current);
            $this->assertSame('JPY', $e->attempted);
            $this->assertSame(1, $e->donations);
        }

        $this->assertSame('EUR', $this->stored(), 'the option is untouched');
    }

    public function test_the_settings_update_command_cannot_walk_past_it(): void
    {
        $ctx = $this->adminCtx();
        $this->liveDonation();

        $res = $this->registry()->dispatch('settings.update', [
            'group'  => 'currency-locale',
            'values' => ['default_currency' => 'JPY'],
        ], $ctx);

        $this->assertFalse($res->ok, 'the command must fail rather than quietly re-denominate the ledger');
        $this->assertStringContainsString('base currency stays EUR', (string) $res->error);
        $this->assertSame('EUR', $this->stored());

        // The registry answers a CommandError and a stray Throwable with the
        // same result, so the refusal reads as correct either way. What
        // separates them is the fault row the Throwable arm writes: an operator
        // reading the error log would be sent looking for a broken plugin
        // instead of the answer they just got.
        $faults = Event::query()
            ->whereLike('type', ErrorLog::PREFIX . 'command')
            ->getAll();

        $this->assertCount(0, $faults, 'a refusal the caller can act on is not a fault to log');
    }

    public function test_the_command_still_saves_the_rest_of_the_group(): void
    {
        $ctx = $this->adminCtx();
        $this->liveDonation();

        $res = $this->registry()->dispatch('settings.update', [
            'group'  => 'currency-locale',
            'values' => ['supported_currencies' => ['EUR', 'USD']],
        ], $ctx);

        $this->assertTrue($res->ok, $res->error ?? '');
        $this->assertSame(['EUR', 'USD'], $this->settings()->get('currency-locale')['supported_currencies']);
    }

    public function test_the_command_sets_the_base_before_any_live_donation(): void
    {
        $ctx = $this->adminCtx();

        $res = $this->registry()->dispatch('settings.update', [
            'group'  => 'currency-locale',
            'values' => ['default_currency' => 'GBP'],
        ], $ctx);

        $this->assertTrue($res->ok, $res->error ?? '');
        $this->assertSame('GBP', $this->stored());
    }

    public function test_a_test_mode_donation_does_not_lock_the_command_path(): void
    {
        $ctx = $this->adminCtx();

        $d = Donation::make();
        $d->reference         = 'REF-' . uniqid();
        $d->status            = 'paid';
        $d->gateway           = 'offline';
        $d->kind              = 'donation';
        $d->amount_cents      = 5000;
        $d->base_amount_cents = 5000;
        $d->currency          = 'EUR';
        $d->is_test           = true;
        $d->created_at        = gmdate('Y-m-d H:i:s');
        $d->save();

        $res = $this->registry()->dispatch('settings.update', [
            'group'  => 'currency-locale',
            'values' => ['default_currency' => 'GBP'],
        ], $ctx);

        $this->assertTrue($res->ok, $res->error ?? '');
        $this->assertSame('GBP', $this->stored());
    }

    public function test_a_non_currency_payload_is_left_to_the_type_check(): void
    {
        $this->liveDonation();

        // SettingsService drops a non-scalar against a string default, so it
        // never reaches the store. Casting one here to compare it would warn and
        // then compare the word "Array": a refusal raised over a value nobody
        // could have saved in the first place.
        $saved = $this->settings()->update('currency-locale', ['default_currency' => ['EUR', 'JPY']]);

        $this->assertSame('EUR', $saved['default_currency']);
        $this->assertSame('EUR', $this->stored(), 'the base is untouched either way');
    }

    public function test_the_refusal_is_escaped_where_it_is_raised(): void
    {
        // The message goes out in a REST body and onto an admin screen, and
        // both codes in it come from outside: the incoming one straight off the
        // request, the stored one from whatever wrote the option last. WordPress
        // wants an exception message escaped at the throw for exactly that
        // reason, and Plugin Check fails the submission over it.
        update_option('dono_currency_locale', ['default_currency' => '<b>eur</b>'], false);
        $this->liveDonation();

        try {
            $this->settings()->update('currency-locale', ['default_currency' => '<img src=x onerror=alert(1)>']);
            $this->fail('the lock must still refuse the change');
        } catch (BaseCurrencyLocked $e) {
            $this->assertStringNotContainsString('<', $e->getMessage());
            $this->assertStringContainsString('&lt;B&gt;EUR&lt;/B&gt;', $e->getMessage());
            $this->assertStringNotContainsString('<', $e->current);
            $this->assertStringNotContainsString('<', $e->attempted);
        }
    }

    public function test_the_rest_route_still_answers_409(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->liveDonation();

        $req = new WP_REST_Request('POST', '/dono/v1/admin/settings/currency-locale');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode(['default_currency' => 'JPY']));

        $res = rest_do_request($req);

        $this->assertSame(409, $res->get_status());
        $this->assertSame('dono_base_currency_locked', $res->as_error()->get_error_code());
        $this->assertSame('EUR', $this->stored());
    }
}
