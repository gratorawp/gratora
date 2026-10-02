<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Donations\ChannelClassifier;
use Gratora\Donations\Donation;
use Gratora\Donations\DonationQueries;
use Gratora\Donors\DonorService;
use Gratora\Foundation\Plugin;
use Gratora\Foundation\Transfer\CsvImporter;
use WP_REST_Request;

/**
 * Which donations a donor gave on the site, as against money an admin recorded
 * and history an import brought over. No column says so, and every donation
 * given on a form carries the page it was given on and usually no medium.
 */
final class DonationsTakenByThisSiteTest extends IntegrationTestCase
{
    private int $seq = 0;

    /** @return array<string, array{0: ?array<string, string>}> */
    public function attributions(): array
    {
        return [
            'none'                           => [null],
            'only the page it was given on'  => [['landing' => 'https://example.org/donate/']],
            'a campaign link'                => [['utm_source' => 'newsletter', 'utm_medium' => 'email', 'landing' => 'https://example.org/donate/']],
            'a source with no medium'        => [['utm_source' => 'google']],
            'recorded by an admin'           => [['utm_source' => 'admin', 'utm_medium' => 'manual']],
            'the medium in another spelling' => [['utm_medium' => ' Manual ']],
        ];
    }

    /**
     * @dataProvider attributions
     * @param ?array<string, string> $attribution
     */
    public function test_the_query_and_the_classifier_agree_on_what_was_recorded_by_hand(?array $attribution): void
    {
        $this->row($attribution);
        $byHand = ChannelClassifier::classify((array) $attribution) === ChannelClassifier::MANUAL;

        $this->assertSame($byHand ? 0 : 1, $this->taken());
    }

    public function test_a_donation_given_on_a_form_is_one_the_site_took(): void
    {
        $this->giveOnAForm();

        $this->assertSame(1, $this->taken());
    }

    public function test_money_an_admin_recorded_is_not(): void
    {
        $request = new WP_REST_Request('POST', '/gratora/v1/admin/donations');
        $request->set_header('content-type', 'application/json');
        $request->set_body((string) wp_json_encode([
            'email'          => 'nadia@example.com',
            'first_name'     => 'Nadia',
            'amount_cents'   => 25000,
            'currency'       => 'USD',
            'payment_method' => 'cheque',
            'received_at'    => '2026-06-14',
        ]));
        $this->assertSame(201, rest_do_request($request)->get_status());

        $this->assertSame(1, (int) Donation::query()->count());
        $this->assertSame(0, $this->taken());
    }

    public function test_history_a_csv_import_brought_over_is_not(): void
    {
        $result = Plugin::instance()->container->get(CsvImporter::class)->import(
            "Email,Amount,Date\nada@example.test,25.00,2025-03-01\ngrace@example.test,40.00,2025-03-02\n",
            ['email' => 'Email', 'amount' => 'Amount', 'date' => 'Date'],
            false
        );
        $this->assertSame(2, $result['donations_imported'], (string) wp_json_encode($result));

        $this->assertSame(0, $this->taken());
    }

    private function taken(): int
    {
        return (int) DonationQueries::takenByThisSite(Donation::query())->count();
    }

    private function giveOnAForm(): void
    {
        $request = new WP_REST_Request('POST', '/gratora/v1/donations');
        $request->set_header('content-type', 'application/json');
        $request->set_body((string) wp_json_encode([
            'gateway'            => 'offline',
            'amount_cents'       => 2500,
            'currency'           => 'USD',
            'email'              => 'form-' . ++$this->seq . '@example.test',
            'profile'            => ['first_name' => 'Ada', 'last_name' => 'Byron'],
            'source_attribution' => ['landing' => 'https://example.org/donate/', 'referrer' => 'https://example.org/'],
        ]));
        $response = rest_do_request($request);

        $this->assertSame(201, $response->get_status(), (string) wp_json_encode($response->get_data()));
    }

    /** @param ?array<string, string> $attribution */
    private function row(?array $attribution): void
    {
        $donor = Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate('taken@example.test', ['first_name' => 'Ada']);

        $now = gmdate('Y-m-d H:i:s');
        $d   = Donation::make();
        $d->reference          = 'DN-TAKEN-' . ++$this->seq;
        $d->donor_id           = (int) $donor->id;
        $d->amount_cents       = 2500;
        $d->net_cents          = 2500;
        $d->currency           = 'USD';
        $d->base_amount_cents  = 2500;
        $d->base_currency      = 'USD';
        $d->fx_rate            = '1.00000000';
        $d->gateway            = 'offline';
        $d->status             = 'paid';
        $d->source_attribution = $attribution;
        $d->paid_at            = $now;
        $d->created_at         = $now;
        $d->updated_at         = $now;
        $d->save();
    }
}
