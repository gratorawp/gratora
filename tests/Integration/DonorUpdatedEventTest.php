<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Donors\Donor;
use Gratora\Donors\DonorService;
use Gratora\Foundation\Plugin;
use WP_REST_Request;

/**
 * gratora.donor.updated is what a CRM or ESP syncs on. The admin dialog PATCHes
 * the whole form every time, so a correction to only the phone number leaves
 * the plain-column update empty; firing on that alone meant the one field the
 * admin actually changed was the one nothing downstream ever heard about.
 */
final class DonorUpdatedEventTest extends IntegrationTestCase
{
    /** @var list<int> */
    private array $fired = [];

    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $this->fired = [];
        add_action('gratora.donor.updated', function ($donor): void {
            $this->fired[] = (int) $donor->id;
        });
    }

    private function donor(): Donor
    {
        return Plugin::instance()->container->get(DonorService::class)->findOrCreate(
            'donor-updated-' . uniqid() . '@example.test',
            ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'country' => 'GB']
        );
    }

    /** What the dialog sends: the whole form, with one field different. */
    private function patch(Donor $donor, array $overrides): int
    {
        $body = [
            'first_name'    => $donor->first_name,
            'last_name'     => $donor->last_name,
            'company'       => $donor->company,
            'country'       => $donor->country,
            'donor_type'    => $donor->donor_type,
            'phone'         => Plugin::instance()->container->get(DonorService::class)->decryptPhone($donor) ?? '',
            'address'       => Plugin::instance()->container->get(DonorService::class)->decryptAddressStruct($donor),
        ];

        $req = new WP_REST_Request('PATCH', '/gratora/v1/admin/donors/' . (int) $donor->id);
        $req->set_param('id', (int) $donor->id);
        $req->set_header('content-type', 'application/json');
        $payload = array_merge($body, $overrides);
        // The dialog omits an empty optional rather than sending null.
        $payload = array_filter($payload, static fn ($v): bool => $v !== null);
        $req->set_body((string) wp_json_encode($payload));

        $res = rest_do_request($req);
        if ($res->get_status() !== 200) {
            $this->fail((string) wp_json_encode($res->get_data()));
        }

        return $res->get_status();
    }

    public function test_correcting_only_the_phone_number_announces_the_donor_changed(): void
    {
        $donor = $this->donor();

        $this->assertSame(200, $this->patch($donor, ['phone' => '+44 7700 900222']));

        $this->assertSame(
            [(int) $donor->id],
            $this->fired,
            'the CRM keeps the old number for good'
        );
    }

    public function test_correcting_only_the_address_announces_the_donor_changed(): void
    {
        $donor = $this->donor();

        $this->assertSame(200, $this->patch($donor, [
            'address' => ['line1' => '14 Baker Street', 'city' => 'London', 'postal' => 'NW1 6XE'],
        ]));

        $this->assertSame([(int) $donor->id], $this->fired);
    }

    public function test_a_name_change_still_announces_it_once(): void
    {
        $donor = $this->donor();

        $this->assertSame(200, $this->patch($donor, ['first_name' => 'Augusta']));

        $this->assertSame([(int) $donor->id], $this->fired);
    }

    /** Saving the dialog without touching anything is not a change. */
    public function test_a_save_that_changes_nothing_announces_nothing(): void
    {
        $donor = $this->donor();

        $this->assertSame(200, $this->patch($donor, []));

        $this->assertSame([], $this->fired);
    }

    /** An email change fires it through changeEmail, so it must not fire twice. */
    public function test_changing_the_email_announces_it_exactly_once(): void
    {
        $donor = $this->donor();

        $this->assertSame(200, $this->patch($donor, ['email' => 'moved-' . uniqid() . '@example.test']));

        $this->assertCount(1, $this->fired);
    }

    public function test_a_phone_and_a_name_together_announce_it_once(): void
    {
        $donor = $this->donor();

        $this->assertSame(200, $this->patch($donor, ['first_name' => 'Augusta', 'phone' => '+44 7700 900333']));

        $this->assertSame([(int) $donor->id], $this->fired);
    }
}
