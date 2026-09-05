<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Currency\FxRates;
use FundKit\Donations\Donation;
use WP_REST_Request;

/**
 * Two quiet losses: an export that stops without saying so, and a hand-set
 * exchange rate deleted by an unrelated save.
 */
final class ExportAndFxLossTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    // --- the export that stops -------------------------------------------

    private function seedDonations(int $count): void
    {
        $now = gmdate('Y-m-d H:i:s');
        for ($i = 0; $i < $count; $i++) {
            $d = Donation::make();
            $d->reference         = 'FUNDKIT-EXP-' . $i . '-' . uniqid();
            $d->donor_id          = 1;
            $d->amount_cents      = 1000;
            $d->net_cents         = 1000;
            $d->currency          = 'USD';
            $d->base_amount_cents = 1000;
            $d->base_currency     = 'USD';
            $d->fx_rate           = '1.00000000';
            $d->gateway           = 'offline';
            $d->status            = 'paid';
            $d->is_test           = false;
            $d->paid_at           = $now;
            $d->created_at        = $now;
            $d->updated_at        = $now;
            $d->save();
        }
    }

    private function exportCsv(): void
    {
        $req = new WP_REST_Request('GET', '/fundkit/v1/admin/donations/export.csv');

        ob_start();
        $server = rest_get_server();
        $result = $server->dispatch($req);
        apply_filters('rest_pre_serve_request', false, $result, $req, $server);
        ob_end_clean();
    }

    private function truncationLogged(): bool
    {
        return \FundKit\Analytics\Event::query()->where('type', 'error.export.donations')->get() !== null;
    }

    public function test_an_export_that_stops_short_says_so(): void
    {
        $this->seedDonations(3);
        add_filter('fundkit.export.max_rows', static fn (): int => 2);

        $this->exportCsv();

        $this->assertTrue(
            $this->truncationLogged(),
            'a truncated export downloads exactly like a complete one, and it is what a bookkeeper reconciles against'
        );
    }

    public function test_an_export_that_fits_says_nothing(): void
    {
        $this->seedDonations(3);
        add_filter('fundkit.export.max_rows', static fn (): int => 50);

        $this->exportCsv();

        $this->assertFalse($this->truncationLogged());
    }

    /** The file still holds the rows it could fit. */
    public function test_the_capped_export_still_carries_its_rows(): void
    {
        $this->seedDonations(3);
        add_filter('fundkit.export.max_rows', static fn (): int => 2);

        $req = new WP_REST_Request('GET', '/fundkit/v1/admin/donations/export.csv');
        ob_start();
        $server = rest_get_server();
        apply_filters('rest_pre_serve_request', false, $server->dispatch($req), $req, $server);
        $body = (string) ob_get_clean();

        $this->assertSame(3, substr_count(trim($body), "\n") + 1, 'a header row and the two rows the cap allows');
    }

    // --- the rate that disappears ------------------------------------------

    private function fxState(): array
    {
        return (array) rest_do_request(new WP_REST_Request('GET', '/fundkit/v1/admin/currency/fx'))->get_data();
    }

    private function saveFx(array $manual): void
    {
        $req = new WP_REST_Request('POST', '/fundkit/v1/admin/currency/fx');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode(['auto' => true, 'manual' => $manual, 'frame' => 'USD']));

        $this->assertSame(200, rest_do_request($req)->get_status());
    }

    public function test_a_hand_set_rate_for_an_unsupported_currency_stays_on_the_screen(): void
    {
        update_option('fundkit_currency_locale', [
            'default_currency'     => 'USD',
            'supported_currencies' => ['USD', 'EUR'],
        ]);
        update_option(FxRates::OPTION, [
            'base'   => 'USD',
            'date'   => gmdate('Y-m-d'),
            'rates'  => ['USD' => 1.0, 'EUR' => 0.9],
            'auto'   => true,
            'manual' => ['CAD' => 1.35],
        ], false);

        $codes = array_column((array) $this->fxState()['rows'], 'code');

        $this->assertContains('CAD', $codes, 'the override is unreadable through the API, so the next save deletes it');
    }

    public function test_an_unrelated_save_no_longer_deletes_it(): void
    {
        update_option('fundkit_currency_locale', [
            'default_currency'     => 'USD',
            'supported_currencies' => ['USD', 'EUR'],
        ]);
        update_option(FxRates::OPTION, [
            'base'   => 'USD',
            'date'   => gmdate('Y-m-d'),
            'rates'  => ['USD' => 1.0, 'EUR' => 0.9],
            'auto'   => true,
            'manual' => ['CAD' => 1.35],
        ], false);

        // What the panel posts back: every row it was shown.
        $manual = [];
        foreach ((array) $this->fxState()['rows'] as $row) {
            if (! empty($row['is_manual'])) {
                $manual[(string) $row['code']] = (float) $row['rate'];
            }
        }
        $manual['EUR'] = 0.95;

        $this->saveFx($manual);

        $stored = (array) get_option(FxRates::OPTION);
        $this->assertSame(1.35, (float) ($stored['manual']['CAD'] ?? 0), 'the CAD override was deleted by an edit to the EUR rate');
    }

    /** Clearing a rate the operator can see still clears it. */
    public function test_blanking_a_rate_still_clears_it(): void
    {
        update_option('fundkit_currency_locale', [
            'default_currency'     => 'USD',
            'supported_currencies' => ['USD', 'EUR'],
        ]);
        update_option(FxRates::OPTION, [
            'base'   => 'USD',
            'date'   => gmdate('Y-m-d'),
            'rates'  => ['USD' => 1.0, 'EUR' => 0.9],
            'auto'   => true,
            'manual' => ['CAD' => 1.35, 'EUR' => 0.91],
        ], false);

        $this->saveFx(['EUR' => 0.91]);

        $stored = (array) get_option(FxRates::OPTION);
        $this->assertArrayNotHasKey('CAD', (array) ($stored['manual'] ?? []));
    }
}
