<?php

declare(strict_types=1);

namespace Gratora\Dashboard;

use Gratora\Campaigns\Campaign;
use Gratora\Campaigns\LiveCampaigns;
use Gratora\Donations\Donation;
use Gratora\Donations\DonationQueries;
use Gratora\Donors\Donor;
use Gratora\Foundation\Auth\Capabilities;
use Gratora\Gateways\TestMode;
use Gratora\Settings\ReadinessService;

/**
 * What stands between a new site and its first real donation.
 *
 * Derived on each request and not stored: every fact here is one another
 * screen already decides, and a stored copy would drift from it. The one
 * thing kept is that a test donation came in, because the donation itself can
 * be removed and nothing else would remember it.
 *
 * @since 1.2.0
 */
final class FirstRun
{
    private const HIDDEN_META = 'gratora_first_run_hidden';

    /** @since 1.2.0 */
    public const TEST_DONATION_AT = 'gratora_first_test_donation_at';

    /** @since 1.2.0 */
    public function __construct(
        private LiveCampaigns $live,
        private ReadinessService $readiness,
    ) {
    }

    /**
     * @return array{
     *   page: 'none'|'closed'|'live',
     *   page_title: ?string,
     *   page_url: ?string,
     *   test_mode: bool,
     *   test_donation: ?array{amount_cents:int, currency:string, donor:?string, url:?string},
     *   test_donation_made: bool,
     *   payments: bool,
     *   payment_methods: list<string>,
     * }
     *
     * @since 1.2.0
     */
    public function facts(): array
    {
        $page    = $this->live->firstOpen();
        $methods = $this->readiness->realMethods();
        $given   = $this->testDonation();

        // One that came in before anything was remembered counts from the
        // first time it is seen here.
        if ($given !== null) {
            self::rememberTestDonation();
        }

        return [
            'page'               => $page ? 'live' : (Campaign::query()->count() > 0 ? 'closed' : 'none'),
            'page_title'         => $page ? (string) $page->title : null,
            'page_url'           => $page ? (string) get_permalink((int) $page->page_id) : null,
            'test_mode'          => TestMode::siteWide(),
            'test_donation'      => $given,
            'test_donation_made' => get_option(self::TEST_DONATION_AT, false) !== false,
            'payments'           => $methods !== [],
            'payment_methods'    => $methods,
        ];
    }

    /**
     * The steps towards a first donation the site has taken.
     *
     * @since 1.2.0
     *
     * @return array{page:bool,test_donation:bool,payments:bool,donation:bool}
     */
    public function progress(): array
    {
        $facts = $this->facts();

        return [
            'page'          => $facts['page'] !== 'none',
            'test_donation' => $facts['test_donation_made'],
            'payments'      => $facts['payments'],
            'donation'      => $this->aDonorHasGiven(),
        ];
    }

    /**
     * The step a test donation finishes stays finished once the donation has
     * been deleted, binned or refunded.
     *
     * @since 1.2.0
     */
    public static function noteCompleted(Donation $donation): void
    {
        if (! empty($donation->is_test) && (string) $donation->kind === 'donation') {
            self::rememberTestDonation();
        }
    }

    private static function rememberTestDonation(): void
    {
        if (get_option(self::TEST_DONATION_AT, false) === false) {
            add_option(self::TEST_DONATION_AT, gmdate('Y-m-d H:i:s'), '', false);
        }
    }

    /**
     * The facts while the dashboard should show them to this person, null once
     * it should not: they hid the card, a donor has given, or the site is live.
     *
     * @return ?array<string,mixed>
     *
     * @since 1.2.0
     */
    public function card(): ?array
    {
        // The steps make a campaign and change payment settings, so the card
        // is for someone who may do both.
        if (! Capabilities::userCan('gratora_manage_settings') || ! Capabilities::userCan('gratora_manage_campaigns')) {
            return null;
        }
        if (get_user_meta(get_current_user_id(), self::HIDDEN_META, true)) {
            return null;
        }
        if ($this->aDonorHasGiven()) {
            return null;
        }

        $facts = $this->facts();
        $live  = $facts['page'] === 'live' && $facts['payments'] && ! $facts['test_mode'];

        return $live ? null : $facts;
    }

    /** @since 1.2.0 */
    public static function hide(int $userId): void
    {
        update_user_meta($userId, self::HIDDEN_META, '1');
    }

    /** Money an admin recorded and history an import brought over are not the page working. */
    private function aDonorHasGiven(): bool
    {
        return DonationQueries::takenByThisSite(DonationQueries::donationsOnly(Donation::query()))
            ->whereIn('status', ['paid', 'partial_refund'])
            ->limit(1)
            ->pluck('id') !== [];
    }

    /** @return ?array{amount_cents:int, currency:string, donor:?string, url:?string} */
    private function testDonation(): ?array
    {
        $donation = DonationQueries::notTrashed(Donation::query())
            ->where('is_test', 1)
            ->where('kind', 'donation')
            ->whereIn('status', ['paid', 'partial_refund'])
            ->orderBy('id', 'DESC')
            ->limit(1)
            ->get();
        if (! $donation) {
            return null;
        }

        $donor = $donation->donor_id && ! $donation->is_anonymous
            ? Donor::query()->find('id', (int) $donation->donor_id)
            : null;
        $name = $donor ? trim(($donor->first_name ?? '') . ' ' . ($donor->last_name ?? '')) : '';

        return [
            'amount_cents' => (int) $donation->amount_cents,
            'currency'     => (string) $donation->currency,
            'donor'        => $name !== '' ? $name : null,
            'url'          => Capabilities::userCan('gratora_view_donations')
                ? admin_url('admin.php?page=gratora-donations&include_test=1')
                : null,
        ];
    }
}
