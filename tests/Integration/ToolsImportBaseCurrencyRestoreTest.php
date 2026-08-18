<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Campaigns\Campaign;
use Dono\Donations\Donation;
use Dono\Donors\Donor;
use Dono\Donors\DonorService;
use Dono\Foundation\Plugin;
use Dono\Foundation\Transfer\DataExporter;
use Dono\Settings\SettingsService;
use Dono\Vendor\Queryable\DB;
use WP_REST_Request;

/**
 * The base currency is the unit every restored base_amount_cents is already
 * denominated in, so a full restore has to land the file's base alongside the
 * file's money. The lock that refuses a re-denomination reads the money
 * recorded on this site, and the file's own donations are not that money.
 */
final class ToolsImportBaseCurrencyRestoreTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->wipeRecords();
    }

    private function wipeRecords(): void
    {
        $prefix = DB::getPrefix();
        foreach ([
            'dono_receipts',
            'dono_refunds',
            'dono_consents',
            'dono_donation_notes',
            'dono_donor_notes',
            'dono_donations',
            'dono_donors',
            'dono_form_donation_stats',
            'dono_forms',
            'dono_campaigns',
            'dono_funds',
        ] as $table) {
            DB::raw("DELETE FROM {$prefix}{$table}");
        }
    }

    private function settings(): SettingsService
    {
        return Plugin::instance()->container->get(SettingsService::class);
    }

    private function seedDonor(string $email): Donor
    {
        return Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate($email, ['first_name' => 'Base', 'last_name' => 'Restore']);
    }

    private function seedDonation(int $donorId, string $reference, string $currency): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $d                    = Donation::make();
        $d->donor_id          = $donorId;
        $d->reference         = $reference;
        $d->amount_cents      = 5000;
        $d->net_cents         = 5000;
        $d->base_amount_cents = 5000;
        $d->base_currency     = $currency;
        $d->currency          = $currency;
        $d->gateway           = 'manual';
        $d->kind              = 'donation';
        $d->status            = 'paid';
        $d->is_test           = false;
        $d->paid_at           = $now;
        $d->created_at        = $now;
        $d->updated_at        = $now;
        $d->save();
    }

    private string $campaignSlug = '';

    /** A GBP org: its base, a campaign priced in it, and one donation. */
    private function seedForeignOrg(string $email, string $reference): void
    {
        $this->settings()->update('currency-locale', [
            'default_currency'     => 'GBP',
            'supported_currencies' => ['GBP'],
        ]);

        $this->campaignSlug = 'winter-appeal-' . uniqid();

        $c           = Campaign::make();
        $c->title    = 'Winter appeal';
        $c->slug     = $this->campaignSlug;
        $c->status   = 'active';
        $c->currency = 'GBP';
        $c->save();

        $this->seedDonation((int) $this->seedDonor($email)->id, $reference, 'GBP');
    }

    private function freshInstall(): void
    {
        $this->wipeRecords();
        update_option('dono_currency_locale', [
            'default_currency'     => 'USD',
            'supported_currencies' => ['USD'],
        ], false);
    }

    /** @return array<string,mixed> */
    private function export(): array
    {
        $out = fopen('php://temp', 'r+');
        Plugin::instance()->container->get(DataExporter::class)->writeJson($out);
        rewind($out);
        $decoded = json_decode((string) stream_get_contents($out), true);
        fclose($out);

        $this->assertIsArray($decoded);

        return $decoded;
    }

    /** @param array<string,mixed> $body */
    private function post(array $body): \WP_REST_Response
    {
        $req = new WP_REST_Request('POST', '/dono/v1/admin/tools/import');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode($body));

        return rest_do_request($req);
    }

    private function base(): string
    {
        $opt = get_option('dono_currency_locale');

        return (string) (is_array($opt) ? ($opt['default_currency'] ?? '') : '');
    }

    /**
     * Most of the world: an org outside the US restoring its own backup onto a
     * site that has taken nothing. Refused, the money lands while the base
     * falls back to the USD default and every restored base_amount_cents is
     * reread as dollars.
     */
    public function test_a_full_restore_lands_the_file_own_base_on_a_site_holding_no_money(): void
    {
        $this->seedForeignOrg('gbp-restore@example.test', 'GBP-REF-1');

        $export = $this->export();
        $this->assertSame(
            'GBP',
            (string) ($export['settings']['dono_currency_locale']['default_currency'] ?? ''),
            'precondition: the file carries the org base'
        );
        $this->assertNotEmpty(
            $export['tables']['dono_donations'] ?? [],
            'precondition: the file carries the money that base denominates'
        );

        $this->freshInstall();
        $this->assertSame(0, (int) Donation::query()->count(), 'precondition: this site has recorded nothing');

        $res = $this->post($export);

        $this->assertSame(200, $res->get_status(), 'the restore is not refused against its own money');
        $this->assertSame('GBP', $this->base(), 'the base is the unit the restored amounts are in');
        $this->assertSame(1, (int) Donation::query()->count(), 'and the donation is restored');
        $this->assertSame(
            'GBP',
            (string) Campaign::query()->where('slug', $this->campaignSlug)->get()?->currency,
            'the restored campaign is priced in the restored base'
        );
    }

    /**
     * The direction the lock exists for. A site that has taken its own money
     * keeps its base, and the file's foreign-denominated money stays out rather
     * than banking under a base the site refused.
     */
    public function test_a_full_restore_onto_a_site_holding_money_is_refused_and_nothing_lands(): void
    {
        $this->seedForeignOrg('gbp-restore-2@example.test', 'GBP-REF-2');
        $export = $this->export();

        $this->freshInstall();
        $this->seedDonation((int) $this->seedDonor('usd-incumbent@example.test')->id, 'USD-REF-2', 'USD');

        $res = $this->post($export);

        $this->assertSame(409, $res->get_status());
        $this->assertSame('dono_base_currency_locked', $res->as_error()->get_error_code());
        $this->assertSame('USD', $this->base(), 'the stored base is untouched');
        $this->assertFalse((bool) ($res->as_error()->get_error_data()['imported'] ?? true));
        $this->assertSame(
            1,
            (int) Donation::query()->count(),
            'only the money this site took is here; the file\'s is not banked under a refused base'
        );
    }
}
