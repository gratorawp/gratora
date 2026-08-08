<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donors\Donor;
use Dono\Donors\DonorRetention;
use Dono\Foundation\Plugin;
use Dono\Settings\SettingsService;

/**
 * Retention is the only thing in Dono that destroys data without being asked,
 * so the two things that stop it surprising anyone are pinned here: it does not
 * run the day it is installed, and it can be counted before it is let loose.
 */
final class DonorRetentionGraceTest extends IntegrationTestCase
{
    private function retention(): DonorRetention
    {
        return Plugin::instance()->container->get(DonorRetention::class);
    }

    private function years(int $n): void
    {
        Plugin::instance()->container->get(SettingsService::class)
            ->update('privacy', ['donor_retention_years' => $n]);
    }

    /** A donor whose last gift is far enough back to be past any window. */
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

    public function test_seven_years_is_the_shipped_window(): void
    {
        delete_option('dono_privacy');

        $this->assertSame(7, $this->retention()->retentionYears());
    }

    /**
     * The case this exists for: an org imports years of history, and the sweep
     * would otherwise take part of it that night, before anyone had seen the
     * setting.
     */
    public function test_it_does_not_run_before_the_start_date(): void
    {
        $this->years(1);
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
        $this->years(1);
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
        $this->years(1);
        $donor = $this->ancientDonor();

        $preview = $this->retention()->preview(30);

        $this->assertGreaterThanOrEqual(1, $preview['eligible_now']);
        $this->assertSame(1, $preview['years']);
        $this->assertNull(
            Donor::query()->where('id', (int) $donor->id)->get()->redacted_at,
            'counting is not doing'
        );
    }

    /** Zero means off, and off has nothing to preview. */
    public function test_a_zero_window_previews_nothing(): void
    {
        $this->years(0);
        $this->ancientDonor();

        $preview = $this->retention()->preview(30);

        $this->assertSame(0, $preview['years']);
        $this->assertSame(0, $preview['eligible_now']);
    }

    public function test_a_zero_window_erases_nobody(): void
    {
        $this->years(0);
        $donor = $this->ancientDonor();

        update_option(DonorRetention::STARTS_AT_OPTION, time() - 86400, false);
        $this->retention()->run();

        $this->assertNull(Donor::query()->where('id', (int) $donor->id)->get()->redacted_at);
    }
}
