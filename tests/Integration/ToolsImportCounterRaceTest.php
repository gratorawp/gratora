<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Analytics\ErrorLog;
use Dono\Analytics\Event;
use Dono\Donations\Donation;
use Dono\Donors\Donor;
use Dono\Donors\DonorService;
use Dono\Foundation\Plugin;
use Dono\Foundation\References\ReferenceGenerator;
use Dono\Foundation\Transfer\DataExporter;
use Dono\Foundation\Transfer\DataImporter;
use Dono\Settings\SettingsService;
use Dono\Vendor\Queryable\DB;
use RuntimeException;
use WP_REST_Request;

/**
 * Raising the reference counters is the last thing a restore does and the only
 * step of it that can refuse. The site is live while it runs, so a donation
 * taken between the read and the raise moves the counter on its own, and the
 * generator will not walk one backwards. The rows are already in by then, so
 * losing that refusal upward costs the operator the report of what landed and
 * the erasure deferral that keeps the restored donors from being swept.
 */
final class ToolsImportCounterRaceTest extends IntegrationTestCase
{
    /** @var callable|null */
    private $race = null;

    private string $counterKey = '';

    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->wipeRecords();
        $this->settings()->update('numbering', ReferenceGenerator::DEFAULT_SETTINGS);
        $this->counterKey = 'dono_reference_counter_donation_' . $this->year();
    }

    protected function tearDown(): void
    {
        if ($this->race !== null) {
            remove_filter('pre_option_' . $this->counterKey, $this->race);
            $this->race = null;
        }

        parent::tearDown();
    }

    private function settings(): SettingsService
    {
        return Plugin::instance()->container->get(SettingsService::class);
    }

    private function references(): ReferenceGenerator
    {
        return Plugin::instance()->container->get(ReferenceGenerator::class);
    }

    private function year(): int
    {
        return (int) gmdate('Y');
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

    private function forgetCounters(): void
    {
        $prefix = DB::getPrefix();
        DB::raw("DELETE FROM {$prefix}options WHERE option_name LIKE 'dono_reference_counter%'");
        wp_cache_delete('alloptions', 'options');
    }

    private function seedDonor(string $email): Donor
    {
        return Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate($email, ['first_name' => 'Race', 'last_name' => 'Probe']);
    }

    private function seedDonation(int $donorId, string $reference): Donation
    {
        $now = gmdate('Y-m-d H:i:s');

        $d                    = Donation::make();
        $d->donor_id          = $donorId;
        $d->reference         = $reference;
        $d->amount_cents      = 4200;
        $d->net_cents         = 4200;
        $d->base_amount_cents = 4200;
        $d->base_currency     = 'USD';
        $d->currency          = 'USD';
        $d->gateway           = 'manual';
        $d->status            = 'paid';
        $d->paid_at           = $now;
        $d->created_at        = $now;
        $d->updated_at        = $now;
        $d->save();

        return $d;
    }

    /**
     * A file holding one donation numbered $counter, and nothing else the
     * restore has to reconcile.
     *
     * @return array<string,mixed>
     */
    private function fileWithDonationAt(int $counter): array
    {
        $this->forgetCounters();

        $donor = $this->seedDonor('counter-race@example.test');
        $this->seedDonation((int) $donor->id, $this->references()->format('donation', $this->year(), $counter));

        $out = fopen('php://temp', 'r+');
        Plugin::instance()->container->get(DataExporter::class)->writeJson($out);
        rewind($out);
        $decoded = json_decode((string) stream_get_contents($out), true);
        fclose($out);

        $this->assertIsArray($decoded);
        $this->assertNotEmpty($decoded['tables']['dono_donations'] ?? [], 'precondition: the file carries the numbered donation');

        $this->wipeRecords();
        $this->forgetCounters();

        return ['tables' => $decoded['tables']];
    }

    /**
     * Answers every read of the donation counter, handing back $before until the
     * raise reads it and $after from then on: a donation taken on the live site
     * in the window the importer leaves open.
     */
    private function raceOnTheCounter(int $before, int $after): void
    {
        $reads      = 0;
        $this->race = function () use ($before, $after, &$reads) {
            $reads++;

            return (string) ($reads === 1 ? $before : $after);
        };

        add_filter('pre_option_' . $this->counterKey, $this->race);
    }

    /** @param array<string,mixed> $body */
    private function post(array $body): \WP_REST_Response
    {
        $req = new WP_REST_Request('POST', '/dono/v1/admin/tools/import');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode($body));

        return rest_do_request($req);
    }

    private function donationsHere(): int
    {
        return (int) Donation::query()->count();
    }

    /** @return array<int,object> */
    private function counterErrors(): array
    {
        return Event::query()
            ->where('type', ErrorLog::PREFIX . 'transfer.import')
            ->orderBy('id', 'DESC')
            ->getAll();
    }

    /**
     * The window itself: the counter clears the file's high-water mark on its
     * own before the raise gets there, so there is nothing left to raise and the
     * restore still reports what it put in.
     */
    public function test_a_counter_that_moves_past_the_file_during_the_raise_does_not_lose_the_restore(): void
    {
        $body = $this->fileWithDonationAt(5);

        update_option($this->counterKey, '3', false);
        $this->assertSame(4, $this->references()->peekNext('donation'), 'precondition: this is the counter the raise reads');

        $this->raceOnTheCounter(3, 9);

        $res = $this->post($body);

        $this->assertSame(200, $res->get_status(), 'a restore whose rows are all in is not a 500');

        $data = $res->get_data();
        $this->assertTrue($data['imported'] ?? false);
        $this->assertNotEmpty($data['records']['created'] ?? [], 'the operator still gets the report of what landed');
        $this->assertSame(1, $this->donationsHere(), 'and the donation it names is in');

        remove_filter('pre_option_' . $this->counterKey, $this->race);
        $this->race = null;

        $this->assertSame('3', (string) get_option($this->counterKey), 'the raise wrote nothing over the counter');
        $this->assertSame([], $this->counterErrors(), 'a counter already past the file is nothing to report');
    }

    /**
     * A raise that fails for any other reason leaves the next reference able to
     * collide, which the operator has to hear about, but still not by way of
     * losing the restore.
     */
    public function test_a_raise_that_fails_outright_is_reported_and_the_restore_stands(): void
    {
        $body = $this->fileWithDonationAt(5);

        update_option($this->counterKey, '3', false);

        $reads      = 0;
        $this->race = function () use (&$reads) {
            $reads++;
            if ($reads > 1) {
                throw new RuntimeException('counter read failed');
            }

            return '3';
        };
        add_filter('pre_option_' . $this->counterKey, $this->race);

        $res = $this->post($body);

        remove_filter('pre_option_' . $this->counterKey, $this->race);
        $this->race = null;

        $this->assertSame(200, $res->get_status(), 'the rows are in, so the restore is not a 500');
        $this->assertNotEmpty($res->get_data()['records']['created'] ?? [], 'the records report still reaches the caller');
        $this->assertSame(1, $this->donationsHere(), 'and the donation it names is in');

        $errors = $this->counterErrors();
        $this->assertNotSame([], $errors, 'a counter that could not be raised is written where the operator reads failures');
        $this->assertStringContainsString('reference counter', (string) ($errors[0]->payload['message'] ?? ''));
    }

    /**
     * The read that decides whether to raise fails the same way the raise does,
     * on the same option and the same connection, so it needs the same cover.
     * Guarding only the raise leaves the earlier read able to take a restore
     * whose rows are all in, which costs the operator the records report and
     * the erasure deferral that runs behind it.
     */
    public function test_a_counter_read_that_fails_at_the_guard_does_not_lose_the_restore(): void
    {
        $body = $this->fileWithDonationAt(5);

        update_option($this->counterKey, '3', false);

        // From the first read, which is the guard's own.
        $this->race = static function (): string {
            throw new RuntimeException('counter read failed');
        };
        add_filter('pre_option_' . $this->counterKey, $this->race);

        $res = $this->post($body);

        remove_filter('pre_option_' . $this->counterKey, $this->race);
        $this->race = null;

        $this->assertSame(200, $res->get_status(), 'the rows are in, so the restore is not a 500');
        $this->assertNotEmpty($res->get_data()['records']['created'] ?? [], 'the records report still reaches the caller');
        $this->assertSame(1, $this->donationsHere(), 'and the donation it names is in');
        $this->assertNotSame([], $this->counterErrors(), 'and the operator is told the counter was not raised');
    }

    /**
     * The container hands out one importer, so a second file in the same
     * process is read through the first one's state unless the run clears it.
     * Source ids start at 1 in every export, so a stale id map resolves this
     * file's donations onto the previous file's donor: wrong attribution, with
     * nothing failing to say so.
     */
    public function test_a_second_restore_in_one_process_is_not_read_through_the_first(): void
    {
        $importer = Plugin::instance()->container->get(DataImporter::class);

        $first  = $importer->import($this->exportWithOneDonor('first@example.test'));
        $second = $importer->import($this->exportWithOneDonor('second@example.test'));

        $this->assertSame(
            $first['created'],
            $second['created'],
            'the second file reports what it brought, not what both files brought'
        );
    }

    /** @return array<string,mixed> a one-donor file whose source ids start at 1 */
    private function exportWithOneDonor(string $email): array
    {
        return [
            'site_url' => 'https://' . md5($email) . '.example',
            'tables'   => [
                'dono_donors' => [[
                    'id'         => 1,
                    'email'      => $email,
                    'created_at' => gmdate('Y-m-d H:i:s'),
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ]],
            ],
        ];
    }
}
