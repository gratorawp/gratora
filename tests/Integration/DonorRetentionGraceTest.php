<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donors\Donor;
use Dono\Donors\DonorRetention;
use Dono\Foundation\Plugin;
use Dono\Settings\SettingsService;
use WP_REST_Request;

/**
 * Retention is the only thing in Dono that destroys data without being asked,
 * so the three things that stop it surprising anyone are pinned here: it does
 * nothing until an org switches it on, it does not run the day it is switched
 * on, and it can be counted before it is let loose.
 */
final class DonorRetentionGraceTest extends IntegrationTestCase
{
    private function retention(): DonorRetention
    {
        return Plugin::instance()->container->get(DonorRetention::class);
    }

    /** The two settings that decide whether anyone is swept, and after how long. */
    private function erasure(bool $on, int $years = 1): void
    {
        Plugin::instance()->container->get(SettingsService::class)
            ->update('privacy', [
                'erase_inactive_donors' => $on,
                'donor_retention_years' => $years,
            ]);
    }

    /** A donor whose last donation is far enough back to be past any window. */
    private function ancientDonor(): Donor
    {
        $d = Donor::make();
        $d->email_hash        = hash('sha256', uniqid('old', true));
        $d->email_encrypted   = 'x';
        $d->first_name        = 'Ancient';
        $d->last_name         = 'Donor';
        $d->last_donation_at  = gmdate('Y-m-d H:i:s', time() - (20 * 365 * 86400));
        $d->created_at        = $d->last_donation_at;
        $d->updated_at        = $d->last_donation_at;
        $d->save();

        return $d;
    }

    protected function tearDown(): void
    {
        delete_option('dono_privacy');
        delete_option(DonorRetention::STARTS_AT_OPTION);
        parent::tearDown();
    }

    /** Nothing is swept out of the box, and seven years is the window offered. */
    public function test_a_fresh_site_erases_nobody_and_offers_a_seven_year_window(): void
    {
        delete_option('dono_privacy');

        $privacy = Plugin::instance()->container->get(SettingsService::class)->get('privacy');

        $this->assertFalse($privacy['erase_inactive_donors'], 'automatic erasure is opt-in');
        $this->assertSame(7, $privacy['donor_retention_years'], 'and seven years is what it offers once opted into');
        $this->assertSame(0, $this->retention()->retentionYears(), 'so no window is in force');
    }

    public function test_nothing_is_erased_until_an_org_switches_erasure_on(): void
    {
        $this->erasure(false, 7);
        $donor = $this->ancientDonor();

        update_option(DonorRetention::STARTS_AT_OPTION, time() - 86400, false);
        $this->retention()->run();

        $this->assertNull(
            Donor::query()->where('id', (int) $donor->id)->get()->redacted_at,
            'a donor two decades past any window is still nobody the sweep may touch'
        );
    }

    /**
     * The filter exists so an add-on with a legal floor of its own can widen the
     * window. Widening a window that is not in force must not open one.
     */
    public function test_an_addon_legal_floor_cannot_switch_the_sweep_back_on(): void
    {
        $this->erasure(false, 7);
        $donor = $this->ancientDonor();
        update_option(DonorRetention::STARTS_AT_OPTION, time() - 86400, false);

        // Gift Aid: HMRC can ask about the donor's name and address for six
        // years after the tax year, and redaction takes exactly those.
        $floor = static fn (): int => 6;
        add_filter('dono.donor.retention_years', $floor);

        try {
            $this->retention()->run();
            $preview = $this->retention()->preview(30);
        } finally {
            remove_filter('dono.donor.retention_years', $floor);
        }

        $this->assertNull(
            Donor::query()->where('id', (int) $donor->id)->get()->redacted_at,
            'an add-on may raise a floor, never start the sweep'
        );
        $this->assertSame(0, $preview['years'], 'and the panel is told the same thing');
    }

    /** The gate must not have made the filter useless where it does apply. */
    public function test_an_addon_legal_floor_still_holds_a_donor_back_once_erasure_is_on(): void
    {
        $this->erasure(true, 1);
        $donor = $this->ancientDonor();
        update_option(DonorRetention::STARTS_AT_OPTION, time() - 86400, false);

        $floor = static fn (): int => 30;
        add_filter('dono.donor.retention_years', $floor);

        try {
            $this->retention()->run();
        } finally {
            remove_filter('dono.donor.retention_years', $floor);
        }

        $this->assertNull(
            Donor::query()->where('id', (int) $donor->id)->get()->redacted_at,
            'a donor inside the widened window is kept'
        );
    }

    /**
     * The panel warns in red that N donors will go on the next nightly run, and
     * it takes that number from here. Nothing is pending while erasure is off.
     */
    public function test_the_preview_route_has_nothing_pending_while_erasure_is_off(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->erasure(false, 7);
        $this->ancientDonor();
        update_option(DonorRetention::STARTS_AT_OPTION, time() - 86400, false);

        $data = (array) rest_do_request(
            new WP_REST_Request('GET', '/dono/v1/admin/settings/retention-preview')
        )->get_data();

        $this->assertSame(0, $data['years'], 'zero years is what makes the panel hide the warning');
        $this->assertSame(0, $data['eligible_now']);
        $this->assertSame(0, $data['within_days']);
    }

    /**
     * The grace period is measured from the moment erasure becomes possible,
     * and nothing else puts it there: activation stamps a date that is long
     * past on a site which has been running a year, so an org that ticks the
     * switch would otherwise have its first sweep take everyone that night.
     */
    public function test_switching_erasure_on_holds_the_first_sweep_back(): void
    {
        update_option(DonorRetention::STARTS_AT_OPTION, time() - (365 * 86400), false);
        $donor = $this->ancientDonor();

        $this->erasure(true, 1);
        $this->retention()->run();

        $this->assertNull(
            Donor::query()->where('id', (int) $donor->id)->get()->redacted_at,
            'the org that has just asked for this still has time to change its mind'
        );
        $this->assertGreaterThan(time(), DonorRetention::startsAt(), 'because the grace period starts again at the switch');
    }

    /**
     * A settings file carries the whole privacy option and is applied without
     * going through the settings screen, so it is the other way erasure gets
     * switched on. Restoring one onto a site that has been running a year has
     * to buy the same time to notice as ticking the switch does.
     */
    public function test_importing_settings_that_switch_erasure_on_holds_the_first_sweep_back(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->erasure(false, 1);
        update_option(DonorRetention::STARTS_AT_OPTION, time() - (365 * 86400), false);
        $donor = $this->ancientDonor();

        $request = new WP_REST_Request('POST', '/dono/v1/admin/tools/import');
        $request->set_header('content-type', 'application/json');
        $request->set_body((string) wp_json_encode(['settings' => [
            'dono_privacy' => ['erase_inactive_donors' => true, 'donor_retention_years' => 1],
        ]]));

        $this->assertSame(200, rest_do_request($request)->get_status());

        $this->assertSame(1, $this->retention()->retentionYears(), 'the imported file did switch erasure on');
        $this->assertGreaterThan(time(), DonorRetention::startsAt(), 'and the grace period starts again with it');

        $this->retention()->run();
        $this->assertNull(
            Donor::query()->where('id', (int) $donor->id)->get()->redacted_at,
            'so a restored backup does not erase the history it just arrived with'
        );
    }

    /** Only the transition re-arms it here too, not every file that has it on. */
    public function test_importing_settings_that_leave_erasure_on_does_not_push_the_sweep_away(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->erasure(true, 1);
        // An org that has been erasing for a while: the grace period is over.
        update_option(DonorRetention::STARTS_AT_OPTION, time() - 86400, false);

        $request = new WP_REST_Request('POST', '/dono/v1/admin/tools/import');
        $request->set_header('content-type', 'application/json');
        $request->set_body((string) wp_json_encode(['settings' => [
            'dono_privacy' => ['erase_inactive_donors' => true, 'donor_retention_years' => 1],
        ]]));

        $this->assertSame(200, rest_do_request($request)->get_status());

        $this->assertLessThan(time(), DonorRetention::startsAt());
    }

    /**
     * Only the switch re-arms it. An org that edits this screen every month
     * would otherwise push its own sweep away forever.
     */
    public function test_an_unrelated_privacy_save_does_not_push_the_first_sweep_away(): void
    {
        $this->erasure(true, 1);
        // An org that has been erasing for a while: the grace period is over.
        update_option(DonorRetention::STARTS_AT_OPTION, time() - 86400, false);

        Plugin::instance()->container->get(SettingsService::class)
            ->update('privacy', ['anonymize_ips' => false]);

        $this->assertLessThan(time(), DonorRetention::startsAt());
    }

    /**
     * The count is worth something to whoever is choosing the window while they
     * are choosing it, so it answers for one that has not been saved.
     */
    public function test_the_preview_answers_for_a_window_that_is_not_saved_yet(): void
    {
        $this->erasure(false, 7);
        $this->ancientDonor();

        $preview = $this->retention()->preview(30, 5);

        $this->assertSame(5, $preview['years']);
        $this->assertGreaterThanOrEqual(1, $preview['eligible_now']);
    }

    /** Asking what a window would take must not put one in force. */
    public function test_asking_about_a_window_does_not_open_one(): void
    {
        $this->erasure(false, 7);
        $donor = $this->ancientDonor();
        update_option(DonorRetention::STARTS_AT_OPTION, time() - 86400, false);

        $this->retention()->preview(30, 5);
        $this->retention()->run();

        $this->assertSame(0, $this->retention()->retentionYears());
        $this->assertNull(Donor::query()->where('id', (int) $donor->id)->get()->redacted_at);
    }

    /**
     * An add-on floor decides the window that would really be in force, so a
     * count for the one being typed goes through it too. Otherwise the panel
     * promises an erasure the filter holds back.
     */
    public function test_a_previewed_window_answers_for_an_addon_floor(): void
    {
        $this->erasure(true, 1);
        $this->ancientDonor();

        $floor = static fn (): int => 30;
        add_filter('dono.donor.retention_years', $floor);

        try {
            $preview = $this->retention()->preview(30, 2);
        } finally {
            remove_filter('dono.donor.retention_years', $floor);
        }

        $this->assertSame(30, $preview['years'], 'the floor is the window, not the number typed');
        $this->assertSame(0, $preview['eligible_now'], 'and two decades back is inside it');
    }

    /** The panel asks about the window it is showing, not the one on record. */
    public function test_the_preview_route_takes_the_window_being_chosen(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->erasure(false, 7);
        $this->ancientDonor();

        $request = new WP_REST_Request('GET', '/dono/v1/admin/settings/retention-preview');
        $request->set_param('years', 5);

        $data = (array) rest_do_request($request)->get_data();

        $this->assertSame(5, $data['years']);
        $this->assertGreaterThanOrEqual(1, $data['eligible_now']);
    }

    /**
     * The case this exists for: an org imports years of history, and the sweep
     * would otherwise take part of it that night, before anyone had seen the
     * setting.
     */
    public function test_it_does_not_run_before_the_start_date(): void
    {
        $this->erasure(true, 1);
        $donor = $this->ancientDonor();

        update_option(DonorRetention::STARTS_AT_OPTION, time() + 86400, false);
        $this->retention()->run();

        $this->assertNull(
            Donor::query()->where('id', (int) $donor->id)->get()->redacted_at,
            'the sweep must wait until the grace period is over'
        );
    }

    public function test_it_runs_once_the_start_date_has_passed(): void
    {
        $this->erasure(true, 1);
        $donor = $this->ancientDonor();

        update_option(DonorRetention::STARTS_AT_OPTION, time() - 86400, false);
        $this->retention()->run();

        $this->assertNotNull(
            Donor::query()->where('id', (int) $donor->id)->get()->redacted_at,
            'past the grace period the sweep does its job'
        );
    }

    public function test_deferring_moves_the_start_date_out(): void
    {
        update_option(DonorRetention::STARTS_AT_OPTION, time() + 86400, false);
        $before = DonorRetention::startsAt();

        DonorRetention::deferBy(30);

        $this->assertGreaterThan($before, DonorRetention::startsAt());
    }

    /** Deferring never pulls the date closer, or an import could shorten it. */
    public function test_deferring_never_brings_the_start_date_forward(): void
    {
        $far = time() + (365 * 86400);
        update_option(DonorRetention::STARTS_AT_OPTION, $far, false);

        DonorRetention::deferBy(1);

        $this->assertSame($far, DonorRetention::startsAt());
    }

    public function test_the_preview_counts_without_erasing_anything(): void
    {
        $this->erasure(true, 1);
        $donor = $this->ancientDonor();

        $preview = $this->retention()->preview(30);

        $this->assertGreaterThanOrEqual(1, $preview['eligible_now']);
        $this->assertSame(1, $preview['years']);
        $this->assertNull(
            Donor::query()->where('id', (int) $donor->id)->get()->redacted_at,
            'counting is not doing'
        );
    }

    /**
     * The panel cannot express a zero window, but an import or a REST client
     * can. A window of nothing takes nobody, rather than everybody.
     */
    public function test_a_zero_window_previews_nothing(): void
    {
        $this->erasure(true, 0);
        $this->ancientDonor();

        $preview = $this->retention()->preview(30);

        $this->assertSame(0, $preview['years']);
        $this->assertSame(0, $preview['eligible_now']);
    }

    public function test_a_zero_window_erases_nobody(): void
    {
        $this->erasure(true, 0);
        $donor = $this->ancientDonor();

        update_option(DonorRetention::STARTS_AT_OPTION, time() - 86400, false);
        $this->retention()->run();

        $this->assertNull(Donor::query()->where('id', (int) $donor->id)->get()->redacted_at);
    }
}
