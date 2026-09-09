<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Donations\Donation;
use Gratora\Donors\Donor;
use Gratora\Donors\DonorAvatarUploader;
use Gratora\Donors\DonorService;
use Gratora\Foundation\Plugin;
use WP_REST_Request;

/**
 * Queryable's save() has no dirty tracking: it rebuilds the UPDATE from every
 * property the row was loaded with. So a portal request that reads a row, does
 * its own small thing and saves writes its whole snapshot back over whatever
 * committed in between, and what commits in between is a gateway webhook: the
 * refund ledger, or the lifetime giving totals the syncer had just recomputed.
 *
 * Each test lets the concurrent write land in exactly that window, by hooking
 * the request's own UPDATE just before the database runs it.
 */
final class PortalLostUpdateTest extends IntegrationTestCase
{
    /**
     * Runs $concurrent immediately before the first UPDATE of $table that the
     * code under test issues: after it read its snapshot, before it writes.
     */
    private function raceOn(string $table, callable $concurrent): void
    {
        global $wpdb;
        $prefixed = $wpdb->prefix . $table;
        $fired    = false;

        add_filter('query', static function ($sql) use (&$fired, $prefixed, $concurrent) {
            if ($fired || stripos((string) $sql, 'UPDATE') !== 0 || ! str_contains((string) $sql, $prefixed)) {
                return $sql;
            }
            $fired = true;
            $concurrent();

            return $sql;
        });
    }

    private function paidDonation(int $cents = 5000): string
    {
        $create = new WP_REST_Request('POST', '/gratora/v1/donations');
        $create->set_header('content-type', 'application/json');
        $create->set_body((string) wp_json_encode([
            'email'        => 'lost-update@example.test',
            'amount_cents' => $cents,
            'currency'     => 'USD',
            'gateway'      => 'offline',
            'profile'      => ['first_name' => 'Ida', 'last_name' => 'Kerr'],
        ]));
        $reference = (string) rest_do_request($create)->get_data()['reference'];

        $confirm = new WP_REST_Request('POST', "/gratora/v1/donations/{$reference}/confirm");
        $confirm->set_header('content-type', 'application/json');
        $confirm->set_body('{}');
        rest_do_request($confirm);

        return $reference;
    }

    private function asDonor(int $donorId, WP_REST_Request $req): array
    {
        $_COOKIE['gratora_donor_session'] = $this->portalSession($donorId, 'tok');
        $req->set_header('X-Gratora-Csrf', 'tok');

        try {
            $res = rest_do_request($req);
            $this->assertSame(200, $res->get_status(), (string) wp_json_encode($res->get_data()));

            return (array) $res->get_data();
        } finally {
            unset($_COOKIE['gratora_donor_session']);
        }
    }


    public function test_toggling_anonymity_does_not_un_refund_a_donation_refunded_mid_request(): void
    {
        global $wpdb;
        $reference = $this->paidDonation();
        $donation  = Donation::query()->find('reference', $reference);

        // The refund webhook that lands while the donor is on the screen.
        $this->raceOn('gratora_donations', static function () use ($wpdb, $donation): void {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}gratora_donations SET status = 'partial_refund', refunded_cents = 1000 WHERE id = %d",
                (int) $donation->id
            ));
        });

        $req = new WP_REST_Request('POST', "/gratora/v1/portal/donations/{$reference}/anonymity");
        $req->set_header('content-type', 'application/json');
        $req->set_body('{"is_anonymous":true}');
        $this->asDonor((int) $donation->donor_id, $req);

        $after = Donation::query()->find('reference', $reference);
        $this->assertSame(1000, (int) $after->refunded_cents, 'the refund was written back to zero');
        $this->assertSame('partial_refund', (string) $after->status, 'and the donation went back to paid');
    }

    public function test_the_anonymity_toggle_still_does_its_own_job(): void
    {
        $reference = $this->paidDonation();
        $donation  = Donation::query()->find('reference', $reference);

        $req = new WP_REST_Request('POST', "/gratora/v1/portal/donations/{$reference}/anonymity");
        $req->set_header('content-type', 'application/json');
        $req->set_body('{"is_anonymous":true}');
        $this->asDonor((int) $donation->donor_id, $req);

        $this->assertTrue((bool) Donation::query()->find('reference', $reference)->is_anonymous);
    }


    private function donorWithTotals(): Donor
    {
        $reference = $this->paidDonation();

        return Donor::query()->find('id', (int) Donation::query()->find('reference', $reference)->donor_id);
    }

    /** The renewal webhook that recomputes the lifetime totals. */
    private function raceOnDonor(int $donorId): void
    {
        global $wpdb;
        $this->raceOn('gratora_donors', static function () use ($wpdb, $donorId): void {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}gratora_donors SET total_donated_cents = 26500, donations_count = 4 WHERE id = %d",
                $donorId
            ));
        });
    }

    private function assertTotalsSurvived(int $donorId, string $what): void
    {
        $after = Donor::query()->find('id', $donorId);
        $this->assertSame(26500, (int) $after->total_donated_cents, "{$what} put the lifetime total back");
        $this->assertSame(4, (int) $after->donations_count, "{$what} put the donation count back");
    }

    public function test_saving_preferences_does_not_revert_the_lifetime_totals(): void
    {
        $donor = $this->donorWithTotals();
        $this->raceOnDonor((int) $donor->id);

        $req = new WP_REST_Request('POST', '/gratora/v1/portal/preferences');
        $req->set_header('content-type', 'application/json');
        $req->set_body('{"always_anonymous":true}');
        $this->asDonor((int) $donor->id, $req);

        $this->assertTotalsSurvived((int) $donor->id, 'a preferences save');
    }

    public function test_saving_preferences_still_stores_them(): void
    {
        $donor = $this->donorWithTotals();

        $req = new WP_REST_Request('POST', '/gratora/v1/portal/preferences');
        $req->set_header('content-type', 'application/json');
        $req->set_body('{"always_anonymous":true}');
        $this->asDonor((int) $donor->id, $req);

        $flags = Donor::query()->find('id', (int) $donor->id)->flags;
        $this->assertIsArray($flags);
        $this->assertTrue((bool) ($flags['prefs']['always_anonymous'] ?? false));
    }

    public function test_editing_a_profile_does_not_revert_the_lifetime_totals(): void
    {
        $donor = $this->donorWithTotals();
        $this->raceOnDonor((int) $donor->id);

        Plugin::instance()->container->get(DonorService::class)->editProfile($donor, ['first_name' => 'Renamed']);

        $this->assertTotalsSurvived((int) $donor->id, 'a profile edit');
        $this->assertSame('Renamed', (string) Donor::query()->find('id', (int) $donor->id)->first_name);
    }

    public function test_a_profile_edit_that_changes_nothing_writes_nothing(): void
    {
        $donor = $this->donorWithTotals();
        $fired = 0;
        add_action('gratora.donor.updated', static function () use (&$fired): void {
            $fired++;
        });

        Plugin::instance()->container->get(DonorService::class)
            ->editProfile($donor, ['first_name' => $donor->first_name]);

        $this->assertSame(0, $fired);
    }

    public function test_clearing_an_avatar_does_not_revert_the_lifetime_totals(): void
    {
        $donor = $this->donorWithTotals();
        $donor->updateColumns(['avatar_attachment_id' => 4242]);
        $this->raceOnDonor((int) $donor->id);

        Plugin::instance()->container->get(DonorAvatarUploader::class)->remove($donor);

        $this->assertTotalsSurvived((int) $donor->id, 'an avatar removal');
        $this->assertNull(Donor::query()->find('id', (int) $donor->id)->avatar_attachment_id);
    }
}
