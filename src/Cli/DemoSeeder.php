<?php

declare(strict_types=1);

namespace Dono\Cli;

use Closure;
use Dono\Campaigns\Campaign;
use Dono\Campaigns\CampaignService;
use Dono\Donations\AggregateSyncer;
use Dono\Donations\Donation;
use Dono\Donations\DonationIntent;
use Dono\Donations\DonationService;
use Dono\Donors\Donor;
use Dono\Donors\DonorService;
use Dono\Forms\Form;
use Dono\Foundation\Helpers\Money;
use Dono\Foundation\Time\Clock;
use Dono\Funds\Fund;
use Dono\Funds\FundService;
use Dono\Recurring\RecurringPlan;
use Dono\Recurring\RecurringPlanRepository;
use Dono\Vendor\Queryable\DB;

/**
 * Builds a year of plausible fundraising history so admin screenshots show an
 * organisation rather than an empty install.
 *
 * Everything it writes is live (`is_test = 0`). Test rows are excluded from
 * money reporting by design, so a dashboard seeded with them renders empty.
 * That also makes this destructive on a real org's books, which is why the
 * caller gates it.
 *
 * Deterministic for a given run date: one seed drives every amount, date and
 * donor pick, and all of them are drawn during the planning pass so a re-run
 * that skips existing rows still produces the same stream. Each donation
 * carries a stable `gateway_intent_id` that the second run finds and skips.
 *
 * @since 1.0.0
 */
final class DemoSeeder
{
    /** Prefix on `gateway_intent_id`: the idempotency key and the demo marker. */
    public const KEY_PREFIX = 'demo-';

    private const SEED = 0x0D0A0;

    private const DONORS       = 140;
    private const ONE_TIME     = 620;
    private const PLANS        = 44;
    private const DAYS_OF_DATA = 364;

    private int $rng = self::SEED;

    /** @var Closure(string):void */
    private Closure $log;

    /** @var array<int,array<string,mixed>>|null */
    private ?array $roster = null;

    /** @var array<string,array{days:array<int,int>,cum:array<int,int>}> */
    private array $dayWeights = [];

    /** @var array<string,int> */
    private array $counts = [
        'campaigns'       => 0,
        'funds'           => 0,
        'donors'          => 0,
        'donations'       => 0,
        'renewals'        => 0,
        'recurring_plans' => 0,
        'skipped'         => 0,
    ];

    /** @since 1.0.0 */
    public function __construct(
        private DonationService $donations,
        private DonorService $donorService,
        private CampaignService $campaignService,
        private FundService $fundService,
        private AggregateSyncer $aggregates,
        private RecurringPlanRepository $plans,
        private Clock $clock,
    ) {
    }

    /**
     * Live paid donations this seeder did not write. Any at all means the
     * install is somebody's real book of record.
     *
     * @since 1.0.0
     */
    public static function foreignLiveDonations(): int
    {
        return (int) Donation::query()
            ->where('is_test', 0)
            ->whereIn('status', ['paid', 'partial_refund', 'refunded'])
            ->where(static function ($q): void {
                $q->whereNull('gateway_intent_id')
                  ->orWhereNotLike('gateway_intent_id', self::KEY_PREFIX . '%');
            })
            ->count();
    }

    /**
     * @param Closure(string):void $log
     * @return array<string,int>
     *
     * @since 1.0.0
     */
    public function run(Closure $log): array
    {
        $this->log = $log;
        $this->rng = self::SEED;

        // Receipt issuance enqueues an Action Scheduler job per donation and
        // each one mails the donor. Neither belongs to a screenshot fixture,
        // and both outlive the process that queued them.
        $noMail    = static fn () => true;
        $noReceipt = static fn () => false;
        add_filter('pre_wp_mail', $noMail, 99);
        add_filter('dono.receipt.should_issue', $noReceipt, 99);

        try {
            $this->seedFunds();

            $planned  = $this->planDonations();
            $schedule = $this->planRecurring();

            $donors    = $this->seedDonors();
            $campaigns = $this->seedCampaigns($planned['goals']);

            $this->writeDonations($planned['donations'], $campaigns, $donors);
            $this->writePlans($schedule, $campaigns, $donors);
            $this->backdateEvents();
            $this->recompute();
        } finally {
            remove_filter('pre_wp_mail', $noMail, 99);
            remove_filter('dono.receipt.should_issue', $noReceipt, 99);
        }

        return $this->counts;
    }

    // ---------------------------------------------------------------- funds

    /** @since 1.0.0 */
    private function seedFunds(): void
    {
        $wanted = [
            ['code' => 'demo-unrestricted', 'name' => 'Where Most Needed', 'sort_order' => 1, 'description' => 'Undesignated support for whatever needs it most.'],
            ['code' => 'demo-water', 'name' => 'Water and Sanitation', 'sort_order' => 2, 'is_restricted' => true, 'goal_cents' => 12000000],
            ['code' => 'demo-education', 'name' => 'Education', 'sort_order' => 3, 'goal_cents' => 6000000],
            ['code' => 'demo-health', 'name' => 'Health Services', 'sort_order' => 4, 'goal_cents' => 8000000],
            ['code' => 'demo-emergency', 'name' => 'Emergency Response', 'sort_order' => 5, 'is_restricted' => true],
        ];

        foreach ($wanted as $spec) {
            if (Fund::query()->where('code', $spec['code'])->get()) {
                continue;
            }
            $this->fundService->create($spec + ['is_active' => true]);
            $this->counts['funds']++;
            $this->say("fund created: {$spec['code']}");
        }
    }

    /** @since 1.0.0 */
    private function fundIdFor(string $code): ?int
    {
        $fund = Fund::query()->where('code', $code)->get();

        return $fund ? (int) $fund->id : null;
    }

    // ------------------------------------------------------------ campaigns

    /**
     * Windows are days-ago pairs so a campaign's dates and the donations
     * attributed to it describe the same period.
     *
     * @return array<int,array<string,mixed>>
     *
     * @since 1.0.0
     */
    private function campaignSpecs(): array
    {
        return [
            [
                'slug' => 'demo-annual-fund', 'title' => 'Annual Fund',
                'status' => 'published', 'fund' => 'demo-unrestricted',
                'share' => 30, 'progress' => 0.71, 'window' => [364, 0],
                'description' => 'Unrestricted giving that keeps every programme running through the year.',
            ],
            [
                'slug' => 'demo-clean-water', 'title' => 'Clean Water for Every Village',
                'status' => 'published', 'fund' => 'demo-water',
                'share' => 21, 'progress' => 0.86, 'window' => [330, 0],
                'description' => 'Boreholes, hand pumps, and the training that keeps them working.',
            ],
            [
                'slug' => 'demo-school-meals', 'title' => 'School Meals Programme',
                'status' => 'published', 'fund' => 'demo-education',
                'share' => 14, 'progress' => 0.47, 'window' => [300, 0],
                'description' => 'A hot meal every school day for 1,200 children.',
            ],
            [
                'slug' => 'demo-winter-appeal', 'title' => 'Winter Emergency Appeal',
                'status' => 'published', 'fund' => 'demo-emergency',
                'share' => 13, 'progress' => 1.14, 'window' => [250, 90],
                'description' => 'Blankets, fuel, and shelter repairs before the cold sets in.',
            ],
            [
                'slug' => 'demo-monthly-giving', 'title' => 'Monthly Giving Circle',
                'status' => 'published', 'fund' => 'demo-unrestricted',
                'share' => 12, 'progress' => null, 'window' => [364, 0],
                'goal_type' => 'donors', 'goal_count' => 250,
                'description' => 'Regular supporters who make next year plannable.',
            ],
            [
                'slug' => 'demo-spring-gala', 'title' => 'Spring Gala',
                'status' => 'archived', 'fund' => 'demo-health',
                'share' => 9, 'progress' => 0.97, 'window' => [200, 130],
                'description' => 'One evening, one room, one year of clinic running costs.',
            ],
            [
                'slug' => 'demo-riverside-clinic', 'title' => 'Build the Riverside Clinic',
                'status' => 'draft', 'fund' => 'demo-health',
                'share' => 1, 'progress' => 0.03, 'window' => [40, 0],
                'description' => 'A permanent maternal health clinic on the east bank.',
            ],
        ];
    }

    /**
     * @param array<int,int> $goals planned raised cents per campaign index
     * @return array<int,Campaign>
     *
     * @since 1.0.0
     */
    private function seedCampaigns(array $goals): array
    {
        $now = $this->clock->now();
        $out = [];

        foreach ($this->campaignSpecs() as $i => $spec) {
            $attrs = [
                'title'           => $spec['title'],
                'status'          => $spec['status'],
                'description'     => $spec['description'],
                'goal_type'       => $spec['goal_type'] ?? 'amount',
                'starts_at'       => $now->modify('-' . $spec['window'][0] . ' days')->format('Y-m-d 00:00:00'),
                'default_fund_id' => $this->fundIdFor((string) $spec['fund']),
            ];

            if (($spec['goal_type'] ?? 'amount') === 'amount') {
                $attrs['goal_cents'] = $this->roundGoal((int) ($goals[$i] ?? 0), (float) $spec['progress']);
            } else {
                $attrs['goal_count'] = (int) $spec['goal_count'];
            }

            // Only a campaign that closed gets an end date; an open one with a
            // past end reads as ended everywhere it is shown.
            $attrs['ends_at'] = $spec['window'][1] > 0
                ? $now->modify('-' . $spec['window'][1] . ' days')->format('Y-m-d 23:59:59')
                : null;

            $campaign = Campaign::query()->where('slug', $spec['slug'])->get();
            if (! $campaign) {
                $campaign = $this->campaignService->create($attrs + ['slug' => $spec['slug']]);
                $this->counts['campaigns']++;
                $this->say("campaign created: {$spec['slug']} id={$campaign->id}");
            } else {
                $campaign = $this->campaignService->update($campaign, $attrs);
                $this->say("campaign updated: {$spec['slug']} id={$campaign->id}");
            }

            $out[$i] = $campaign;
        }

        return $out;
    }

    /**
     * A goal is a number somebody chose, so derive it from the money the
     * campaign will hold and round it to something a person would pick.
     *
     * @since 1.0.0
     */
    private function roundGoal(int $raisedCents, float $progress): int
    {
        if ($raisedCents <= 0 || $progress <= 0) {
            return 1000000;
        }

        $goal = (int) round($raisedCents / $progress);
        $step = $goal >= 10000000 ? 2500000 : ($goal >= 2000000 ? 500000 : 100000);

        return max($step, (int) round($goal / $step) * $step);
    }

    // --------------------------------------------------------------- donors

    /**
     * @return array<int,Donor>
     *
     * @since 1.0.0
     */
    private function seedDonors(): array
    {
        $out = [];
        foreach ($this->donorRoster() as $i => $person) {
            if (! $this->donorService->findByEmail((string) $person['email'])) {
                $this->counts['donors']++;
            }

            $out[$i] = $this->donorService->findOrCreate((string) $person['email'], [
                'first_name' => $person['first_name'],
                'last_name'  => $person['last_name'],
                'country'    => $person['country'],
                'locale'     => $person['locale'],
                'company'    => $person['company'],
                'donor_type' => $person['donor_type'],
                'phone'      => $person['phone'],
                'address'    => $person['address'],
            ]);
        }

        $this->say('donors on the roster: ' . count($out) . ' (' . $this->counts['donors'] . ' new)');

        return $out;
    }

    /**
     * Names are invented and every address is example.org / example.com, which
     * IANA reserves for exactly this. The two list lengths are coprime, so no
     * first/last pair repeats across the roster and every email is unique.
     *
     * @return array<int,array<string,mixed>>
     *
     * @since 1.0.0
     */
    private function donorRoster(): array
    {
        if ($this->roster !== null) {
            return $this->roster;
        }

        $first = [
            'Emma', 'Lucas', 'Sofia', 'Noah', 'Aisha', 'Mateo', 'Hannah', 'Ravi',
            'Freya', 'Omar', 'Clara', 'Jonas', 'Nadia', 'Felix', 'Leila', 'Tomas',
            'Ingrid', 'Samuel', 'Priya', 'Anders', 'Marta', 'Yusuf', 'Elise', 'Kwame',
            'Johanna', 'Diego', 'Saoirse', 'Henrik', 'Amara', 'Pieter', 'Rosa', 'Ilias',
            'Maja', 'Benedikt', 'Chiara', 'Idris', 'Astrid', 'Rafael', 'Nora', 'Stefan',
        ];
        $last = [
            'Visser', 'Lindqvist', 'Okafor', 'Bauer', 'Moreau', 'Novak', 'Haddad',
            'Bergstrom', 'Delgado', 'Kowalski', 'Fitzgerald', 'Almeida', 'Vandenberg',
            'Rasmussen', 'Ferrari', 'Osei', 'Halvorsen', 'Marchetti', 'Dubois',
            'Karlsson', 'Bekele', 'Janssen', 'Petrov', 'Costa', 'Ahmadi', 'Lindgren',
            'Mbeki', 'Schneider', 'Oconnell', 'Rivas', 'Toivonen', 'Baumgartner',
            'Nasser', 'Lombardi', 'Sorensen', 'Adeyemi', 'Kaminski', 'Peeters',
            'Varga', 'Eriksen', 'Salvatore',
        ];

        $countries = ['NL', 'DE', 'GB', 'FR', 'BE', 'IE', 'SE', 'ES', 'US', 'CA'];
        $locales   = ['nl_NL', 'de_DE', 'en_GB', 'fr_FR', 'nl_BE', 'en_IE', 'sv_SE', 'es_ES', 'en_US', 'en_CA'];
        $cities    = ['Utrecht', 'Leipzig', 'Bristol', 'Nantes', 'Ghent', 'Galway', 'Uppsala', 'Girona', 'Portland', 'Halifax'];
        $streets   = ['Kanaalweg', 'Lindenstrasse', 'Fern Hill Road', 'Rue des Peupliers', 'Molenstraat', 'Quay Lane', 'Storgatan', 'Carrer Nou', 'Alder Street', 'Bayview Road'];
        $orgs      = [
            'Meridian Legal LLP', 'Northbank Consulting', 'Vellum Print Works',
            'Sable and Croft', 'Harbourline Logistics', 'Quiet Fields Foundation',
            'Ashgrove Dental Practice', 'Tideway Architects',
        ];

        $roster = [];
        for ($i = 0; $i < self::DONORS; $i++) {
            $f = $first[$i % count($first)];
            $l = $last[$i % count($last)];
            $c = $i % count($countries);

            $isOrg = ($i % 17) === 5;

            $roster[] = [
                'first_name' => $f,
                'last_name'  => $l,
                'email'      => strtolower($f . '.' . $l) . '@' . (($i % 3) === 0 ? 'example.com' : 'example.org'),
                'country'    => $countries[$c],
                'locale'     => $locales[$c],
                'company'    => $isOrg ? $orgs[intdiv($i, 17) % count($orgs)] : null,
                'donor_type' => $isOrg ? 'organization' : (($i % 23) === 7 ? 'household' : 'individual'),
                'phone'      => ($i % 4) === 0 ? '+31 20 555 0' . str_pad((string) (100 + $i), 3, '0', STR_PAD_LEFT) : null,
                'address'    => ($i % 3) === 0 ? [
                    'line1'       => $streets[$c] . ' ' . (3 + ($i % 90)),
                    'city'        => $cities[$c],
                    'postal_code' => str_pad((string) (1000 + (($i * 7) % 8999)), 4, '0', STR_PAD_LEFT),
                    'country'     => $countries[$c],
                ] : null,
            ];
        }

        $this->roster = $roster;

        return $roster;
    }

    // ------------------------------------------------- planning (all random)

    /**
     * Every one-time donation, decided before anything is written: campaign
     * goals are derived from the money they are about to hold, and a re-run
     * that skips existing rows still draws the same stream.
     *
     * @return array{donations:array<int,array<string,mixed>>, goals:array<int,int>}
     *
     * @since 1.0.0
     */
    private function planDonations(): array
    {
        $specs  = $this->campaignSpecs();
        $picker = $this->cumulative(array_map(static fn (array $s): int => (int) $s['share'], $specs));

        $failures = [
            'Your card was declined.',
            'Insufficient funds.',
            'The card has expired.',
            'The payment was not authenticated.',
        ];
        $refundReasons = ['Donor requested a refund.', 'Duplicate donation.', 'Amount entered in error.'];
        $brands        = ['visa', 'mastercard', 'amex'];

        $donations = [];
        $goals     = array_fill(0, count($specs), 0);

        for ($n = 0; $n < self::ONE_TIME; $n++) {
            $ci     = $this->pick($picker);
            $spec   = $specs[$ci];
            $day    = $this->pickDay((int) $spec['window'][0], (int) $spec['window'][1]);
            $amount = $this->pickAmount();
            $status = $this->pickStatus($day);

            $donations[] = [
                'key'           => self::KEY_PREFIX . 'd' . str_pad((string) $n, 4, '0', STR_PAD_LEFT),
                'campaign'      => $ci,
                'fund'          => $spec['fund'],
                'donor'         => $this->pickDonor(),
                'amount'        => $amount,
                'day'           => $day,
                'status'        => $status,
                'gateway'       => $this->pickGateway(),
                'channel'       => $this->pickChannel(),
                'anonymous'     => $this->chance(9),
                'cover_fees'    => $this->chance(28),
                'note'          => $this->pickNote(),
                'brand'         => $brands[$this->next(count($brands))],
                'last4'         => str_pad((string) $this->next(10000), 4, '0', STR_PAD_LEFT),
                'failure'       => $failures[$this->next(count($failures))],
                'refund_reason' => $refundReasons[$this->next(count($refundReasons))],
                'refund_delay'  => 2 + $this->next(9),
            ];

            if ($status === 'paid') {
                $goals[$ci] += $amount;
            } elseif ($status === 'partial_refund') {
                $goals[$ci] += $amount - (int) round($amount / 2);
            }
        }

        return ['donations' => $donations, 'goals' => $goals];
    }

    /**
     * @return array<int,array<string,mixed>>
     *
     * @since 1.0.0
     */
    private function planRecurring(): array
    {
        // The recurring-heavy campaign plus the two evergreen ones. A monthly
        // plan against a closed appeal would have nothing left to renew into.
        $hosts    = [4, 0, 1];
        $amounts  = [500, 1000, 1500, 2000, 2500, 3000, 5000, 10000];
        $gateways = ['stripe', 'stripe', 'stripe', 'paypal'];
        $reasons  = ['Cancelled by the donor.', 'Cancelled in the donor portal.', 'Card retired by the issuer.'];

        $out = [];
        for ($p = 0; $p < self::PLANS; $p++) {
            $gateway        = $gateways[$this->next(count($gateways))];
            $amount         = $amounts[$this->next(count($amounts))];
            [$unit, $count] = $this->pickInterval();
            $startedDaysAgo = 30 + $this->next(self::DAYS_OF_DATA - 40);
            $status         = $this->pickPlanStatus();
            $stepDays       = $this->intervalDays($unit, $count);

            // A cancelled plan stopped collecting somewhere in its life; the
            // rest keep paying up to the present.
            $stopAfter = $status === 'cancelled'
                ? (int) round($startedDaysAgo * (0.30 + ($this->next(45) / 100)))
                : 0;

            $payments = [];
            for ($k = 0; ; $k++) {
                $dayAgo = $startedDaysAgo - ($k * $stepDays);
                if ($dayAgo < $stopAfter || $dayAgo < 0) {
                    break;
                }
                // A past_due plan is one whose newest attempt failed, so it
                // stops one cycle short of today.
                if ($status === 'past_due' && $dayAgo < $stepDays) {
                    break;
                }
                $payments[] = ['index' => $k, 'when' => $this->stamp($dayAgo)];
            }

            $out[] = [
                'subscription_id' => self::KEY_PREFIX . 'sub' . str_pad((string) $p, 3, '0', STR_PAD_LEFT),
                'key_prefix'      => self::KEY_PREFIX . 'r' . str_pad((string) $p, 3, '0', STR_PAD_LEFT),
                'campaign'        => $hosts[$p % count($hosts)],
                'donor'           => $p % self::DONORS,
                'gateway'         => $gateway,
                'amount'          => $amount,
                'interval_unit'   => $unit,
                'interval_count'  => $count,
                'step_days'       => $stepDays,
                'status'          => $status,
                'started_at'      => $this->stamp($startedDaysAgo),
                'payments'        => $payments,
                'failed_count'    => 1 + $this->next(3),
                'resume_in'       => 20 + $this->next(70),
                'retry_in'        => 1 + $this->next(6),
                'cancel_reason'   => $reasons[$this->next(count($reasons))],
                'last4'           => str_pad((string) $this->next(10000), 4, '0', STR_PAD_LEFT),
            ];
        }

        return $out;
    }

    // -------------------------------------------------- writing (no random)

    /**
     * @param array<int,array<string,mixed>> $specs
     * @param array<int,Campaign>            $campaigns
     * @param array<int,Donor>               $donors
     *
     * @since 1.0.0
     */
    private function writeDonations(array $specs, array $campaigns, array $donors): void
    {
        $currency = strtoupper(Money::defaultCurrency());
        $written  = 0;

        foreach ($specs as $spec) {
            if ($this->alreadySeeded((string) $spec['key'])) {
                $this->counts['skipped']++;
                continue;
            }

            $campaign = $campaigns[$spec['campaign']];
            $donor    = $donors[$spec['donor']];
            $when     = $this->stampAt((int) $spec['day'], (string) $spec['key']);

            $intent = new DonationIntent(
                email:              (string) $this->donorRoster()[$spec['donor']]['email'],
                amount_cents:       (int) $spec['amount'],
                currency:           $currency,
                gateway:            (string) $spec['gateway'],
                frequency:          'one_time',
                form_id:            ((int) ($campaign->default_form_id ?? 0)) ?: null,
                campaign_id:        (int) $campaign->id,
                fund_id:            $this->fundIdFor((string) $spec['fund']),
                profile:            ['first_name' => $donor->first_name, 'last_name' => $donor->last_name],
                payment_method:     $spec['gateway'] === 'offline' ? 'bank_transfer' : 'card',
                source_attribution: (array) $spec['channel'],
                locale:             $donor->locale,
                note_to_org:        $spec['note'],
                note_public:        $spec['note'] !== null && ! $spec['anonymous'],
                is_anonymous:       (bool) $spec['anonymous'],
                country:            $donor->country,
                fee_covered_cents:  $spec['cover_fees'] ? $this->coveredFee((int) $spec['amount']) : 0,
                // Live, not test: test rows are excluded from every money
                // figure, which is the whole point of seeding for screenshots.
                is_test:            false,
            );

            $donation = $this->donations->createPending($intent)['donation'];
            $this->donations->setGatewayIntent($donation, (string) $spec['key']);

            $this->settle($donation, $spec, $when);
            $this->stampRow($donation, $when);

            $this->counts['donations']++;
            $written++;
        }

        $this->say("one-time donations written: {$written} (skipped {$this->counts['skipped']})");
    }

    /**
     * Walks a donation to its final state through the same service the
     * gateways use, so refunds get Refund rows and reversals get their kind.
     *
     * @param array<string,mixed> $spec
     *
     * @since 1.0.0
     */
    private function settle(Donation $donation, array $spec, string $when): void
    {
        $status = (string) $spec['status'];

        if ($status === 'pending') {
            return;
        }

        if ($status === 'failed') {
            $this->donations->markFailed($donation, (string) $spec['failure']);
            return;
        }

        $isCard = $donation->gateway !== 'offline';
        $this->donations->confirm($donation, [
            'paid_at'              => $when,
            'gateway_txn_id'       => str_replace(self::KEY_PREFIX, 'txn_demo_', (string) $donation->gateway_intent_id),
            'payment_method'       => $donation->payment_method,
            'payment_method_brand' => $isCard ? $spec['brand'] : null,
            'payment_method_last4' => $isCard ? $spec['last4'] : null,
            'fee_cents'            => $this->feeFor((int) $donation->amount_cents, (string) $donation->gateway),
        ]);

        if ($status === 'refunded' || $status === 'partial_refund') {
            $amount = $status === 'refunded'
                ? (int) $donation->amount_cents
                : (int) round($donation->amount_cents / 2);

            $refund = $this->donations->recordExternalRefund(
                $donation,
                $amount,
                str_replace(self::KEY_PREFIX, 're_demo_', (string) $donation->gateway_intent_id),
                (string) $spec['refund_reason'],
                'admin'
            );

            $refundedAt          = $this->shiftDays($when, (int) $spec['refund_delay']);
            $refund->occurred_at = $refundedAt;
            $refund->save();
            $donation->refunded_at = $refundedAt;
            $donation->save();

            return;
        }

        if ($status === 'disputed') {
            $this->donations->markReversed($donation, 'chargeback', 'The account holder disputed the payment.');
        }
    }

    /**
     * @param array<int,array<string,mixed>> $schedule
     * @param array<int,Campaign>            $campaigns
     * @param array<int,Donor>               $donors
     *
     * @since 1.0.0
     */
    private function writePlans(array $schedule, array $campaigns, array $donors): void
    {
        $currency = strtoupper(Money::defaultCurrency());
        $now      = $this->clock->now()->format('Y-m-d H:i:s');

        foreach ($schedule as $spec) {
            if ($this->plans->findBySubscriptionId((string) $spec['gateway'], (string) $spec['subscription_id'])) {
                $this->counts['skipped']++;
                continue;
            }

            $campaign = $campaigns[$spec['campaign']];
            $donor    = $donors[$spec['donor']];

            $plan = RecurringPlan::make();
            $plan->donor_id                = (int) $donor->id;
            $plan->form_id                 = ((int) ($campaign->default_form_id ?? 0)) ?: null;
            $plan->campaign_id             = (int) $campaign->id;
            $plan->fund_id                 = ((int) ($campaign->default_fund_id ?? 0)) ?: null;
            $plan->gateway                 = (string) $spec['gateway'];
            $plan->gateway_subscription_id = (string) $spec['subscription_id'];
            $plan->gateway_customer_id     = str_replace(self::KEY_PREFIX, 'cus_demo_', (string) $spec['subscription_id']);
            $plan->amount_cents            = (int) $spec['amount'];
            $plan->currency                = $currency;
            $plan->base_amount_cents       = (int) $spec['amount'];
            $plan->fx_rate                 = sprintf('%.8F', 1);
            $plan->interval_unit           = (string) $spec['interval_unit'];
            $plan->interval_count          = (int) $spec['interval_count'];
            $plan->status                  = (string) $spec['status'];
            $plan->started_at              = (string) $spec['started_at'];
            $plan->is_test                 = false;
            $plan->created_at              = (string) $spec['started_at'];
            $plan->updated_at              = (string) $spec['started_at'];
            $plan->save();

            $this->counts['recurring_plans']++;

            $last = null;
            foreach ($spec['payments'] as $payment) {
                $result = $this->donations->createRenewal(
                    $plan,
                    (int) $spec['amount'],
                    $currency,
                    (string) $spec['gateway'],
                    $spec['key_prefix'] . '-' . str_pad((string) $payment['index'], 2, '0', STR_PAD_LEFT),
                    [
                        'paid_at'              => $payment['when'],
                        'payment_method'       => 'card',
                        'payment_method_brand' => 'visa',
                        'payment_method_last4' => $spec['last4'],
                        'fee_cents'            => $this->feeFor((int) $spec['amount'], (string) $spec['gateway']),
                    ]
                );

                if (! $result['created']) {
                    continue;
                }

                $renewal = $result['donation'];
                $renewal->donor_first_name = $donor->first_name;
                $renewal->donor_last_name  = $donor->last_name;
                $renewal->country          = $donor->country;
                $renewal->locale           = $donor->locale;
                $this->stampRow($renewal, (string) $payment['when']);

                $this->plans->recordPayment($plan, (int) $spec['amount'], (string) $payment['when']);

                $last = (string) $payment['when'];
                $this->counts['renewals']++;
                $this->counts['donations']++;
            }

            $this->closePlan($plan, $spec, $last, $now);
        }

        $this->say("recurring plans written: {$this->counts['recurring_plans']} ({$this->counts['renewals']} renewal donations)");
    }

    /**
     * @param array<string,mixed> $spec
     *
     * @since 1.0.0
     */
    private function closePlan(RecurringPlan $plan, array $spec, ?string $last, string $now): void
    {
        $step  = (int) $spec['step_days'];
        $patch = ['updated_at' => $now];

        if ($last !== null) {
            $patch['last_payment_at']      = $last;
            $patch['current_period_start'] = $last;
            $patch['current_period_end']   = $this->shiftDays($last, $step);
        }

        switch ((string) $spec['status']) {
            case 'active':
                $patch['next_payment_at'] = $this->shiftDays($last ?? $now, $step);
                break;
            case 'paused':
                $patch['next_payment_at'] = null;
                $patch['resume_at']       = $this->shiftDays($now, (int) $spec['resume_in']);
                break;
            case 'past_due':
                $patch['next_payment_at']       = $this->shiftDays($now, (int) $spec['retry_in']);
                $patch['failed_renewals_count'] = (int) $spec['failed_count'];
                break;
            case 'cancelled':
                $patch['next_payment_at']     = null;
                $patch['cancelled_at']        = $this->shiftDays($last ?? $now, $step);
                $patch['cancellation_reason'] = (string) $spec['cancel_reason'];
                break;
        }

        DB::table('dono_recurring_plans')->where('id', $plan->id)->update($patch);
    }

    // ----------------------------------------------------------- after-care

    /**
     * Every donation event was recorded at the moment the seeder ran, which
     * stacks a year of history onto one day in the donor timelines that read
     * the firehose.
     *
     * @since 1.0.0
     */
    private function backdateEvents(): void
    {
        $prefix = DB::getPrefix();
        $result = DB::raw(
            "UPDATE {$prefix}dono_events e
             JOIN {$prefix}dono_donations d ON d.id = e.donation_id
             SET e.occurred_at = COALESCE(d.paid_at, d.created_at)
             WHERE d.gateway_intent_id LIKE %s",
            [self::KEY_PREFIX . '%']
        );

        $rows = is_object($result) ? (int) $result->affectedRows : 0;
        $this->say("events backdated: {$rows}");
    }

    /**
     * Backdated rows land behind the listeners that keep the derived columns
     * current, so the rollups are rebuilt from the donations once everything
     * is in place.
     *
     * @since 1.0.0
     */
    private function recompute(): void
    {
        foreach (Donor::query()->getAll() as $donor) {
            $this->aggregates->syncDonor((int) $donor->id);
        }
        foreach (Fund::query()->getAll() as $fund) {
            $this->aggregates->syncFund((int) $fund->id);
        }
        foreach (Campaign::query()->getAll() as $campaign) {
            $this->aggregates->syncCampaign((int) $campaign->id);
        }
        foreach (Form::query()->getAll() as $form) {
            $this->aggregates->syncForm((int) $form->id);
        }

        $this->say('aggregates recomputed');
    }

    // ---------------------------------------------------------------- utils

    /** @since 1.0.0 */
    private function alreadySeeded(string $key): bool
    {
        return Donation::query()->where('gateway_intent_id', $key)->get() !== null;
    }

    /** @since 1.0.0 */
    private function stampRow(Donation $donation, string $when): void
    {
        $donation->created_at = $this->shiftMinutes($when, -3);
        $donation->updated_at = $when;
        $donation->save();
    }

    /**
     * Time of day is derived from the donation key rather than drawn, so the
     * write pass stays free of the PRNG.
     *
     * @since 1.0.0
     */
    private function stampAt(int $daysAgo, string $key): string
    {
        $h = crc32($key);

        return $this->clock->now()
            ->modify("-{$daysAgo} days")
            ->setTime(7 + ($h % 15), ($h >> 4) % 60, ($h >> 10) % 60)
            ->format('Y-m-d H:i:s');
    }

    /** @since 1.0.0 */
    private function stamp(int $daysAgo): string
    {
        return $this->clock->now()
            ->modify("-{$daysAgo} days")
            ->setTime(7 + $this->next(15), $this->next(60), $this->next(60))
            ->format('Y-m-d H:i:s');
    }

    /** @since 1.0.0 */
    private function shiftDays(string $stamp, int $days): string
    {
        return gmdate('Y-m-d H:i:s', (int) strtotime($stamp) + ($days * 86400));
    }

    /** @since 1.0.0 */
    private function shiftMinutes(string $stamp, int $minutes): string
    {
        return gmdate('Y-m-d H:i:s', (int) strtotime($stamp) + ($minutes * 60));
    }

    /** @since 1.0.0 */
    private function feeFor(int $amountCents, string $gateway): int
    {
        return $gateway === 'offline' ? 0 : (int) round($amountCents * 0.014) + 25;
    }

    /** @since 1.0.0 */
    private function coveredFee(int $amountCents): int
    {
        return (int) round($amountCents * 0.015) + 30;
    }

    /**
     * Day weights: a growing org, quiet summers, a December peak, a lighter
     * weekend. Sampling from this is what gives the revenue chart a shape
     * instead of noise.
     *
     * @since 1.0.0
     */
    private function pickDay(int $fromDaysAgo, int $toDaysAgo): int
    {
        $key = $fromDaysAgo . ':' . $toDaysAgo;

        if (! isset($this->dayWeights[$key])) {
            $monthly = [1 => 0.80, 0.70, 0.85, 0.95, 0.85, 0.70, 0.55, 0.55, 0.95, 1.05, 1.35, 2.30];
            $days    = [];
            $weights = [];

            for ($d = $fromDaysAgo; $d >= $toDaysAgo; $d--) {
                $date   = $this->clock->now()->modify("-{$d} days");
                $factor = $monthly[(int) $date->format('n')];
                $factor *= in_array($date->format('N'), ['6', '7'], true) ? 0.65 : 1.0;
                $factor *= 0.75 + (0.5 * (1 - ($d / max(1, self::DAYS_OF_DATA))));

                // The first Tuesday of December is the sector's biggest giving
                // day, and a year chart without it looks synthetic.
                if ((int) $date->format('n') === 12 && (int) $date->format('j') <= 7 && $date->format('N') === '2') {
                    $factor *= 5.0;
                }

                $days[]    = $d;
                $weights[] = max(1, (int) round($factor * 100));
            }

            $this->dayWeights[$key] = ['days' => $days, 'cum' => $this->cumulative($weights)];
        }

        return (int) $this->dayWeights[$key]['days'][$this->pick($this->dayWeights[$key]['cum'])];
    }

    /** @since 1.0.0 */
    private function pickAmount(): int
    {
        $table = [
            1000 => 10, 1500 => 8, 2000 => 12, 2500 => 15, 3000 => 9, 3500 => 5,
            5000 => 15, 7500 => 7, 10000 => 11, 15000 => 5, 20000 => 4, 25000 => 4,
            50000 => 3, 100000 => 2, 250000 => 1, 500000 => 1,
        ];

        return (int) array_keys($table)[$this->pick($this->cumulative(array_values($table)))];
    }

    /**
     * A donation that never settled sits in pending for hours, not months, so
     * only the newest days carry any.
     *
     * @since 1.0.0
     */
    private function pickStatus(int $daysAgo): string
    {
        $roll = $this->next(1000);

        if ($daysAgo <= 6 && $roll < 320) return 'pending';
        if ($roll < 90)  return 'failed';
        if ($roll < 118) return 'refunded';
        if ($roll < 132) return 'partial_refund';
        if ($roll < 140) return 'disputed';

        return 'paid';
    }

    /** @since 1.0.0 */
    private function pickGateway(): string
    {
        $roll = $this->next(100);
        if ($roll < 66) return 'stripe';
        if ($roll < 92) return 'paypal';

        return 'offline';
    }

    /**
     * @return array<string,string>
     *
     * @since 1.0.0
     */
    private function pickChannel(): array
    {
        $channels = [
            [],
            ['utm_source' => 'newsletter', 'utm_medium' => 'email', 'utm_campaign' => 'monthly-update'],
            ['utm_source' => 'newsletter', 'utm_medium' => 'email', 'utm_campaign' => 'appeal'],
            ['utm_source' => 'facebook', 'utm_medium' => 'social', 'utm_campaign' => 'stories'],
            ['utm_source' => 'instagram', 'utm_medium' => 'social', 'utm_campaign' => 'reels'],
            ['utm_source' => 'google', 'utm_medium' => 'organic'],
            ['utm_source' => 'google', 'utm_medium' => 'cpc', 'utm_campaign' => 'grants'],
            ['utm_source' => 'partner-site', 'utm_medium' => 'referral'],
            ['utm_source' => 'poster', 'utm_medium' => 'qr', 'utm_campaign' => 'shopfront'],
            ['utm_source' => 'back-office', 'utm_medium' => 'manual'],
        ];

        $roll = $this->next(100);

        return $channels[match (true) {
            $roll < 33 => 0,
            $roll < 46 => 1,
            $roll < 54 => 2,
            $roll < 63 => 3,
            $roll < 69 => 4,
            $roll < 77 => 5,
            $roll < 84 => 6,
            $roll < 89 => 7,
            $roll < 95 => 8,
            default    => 9,
        }];
    }

    /** @since 1.0.0 */
    private function pickNote(): ?string
    {
        $notes = [
            'In memory of my grandmother, who never let anyone leave hungry.',
            'Keep up the good work. I wish I could give more.',
            'Please put this towards the well in the eastern district.',
            'From all of us at the Tuesday walking group.',
            'Happy birthday, Mum. This one is for you.',
            'The update newsletter made the decision easy. Thank you.',
            'For the school kitchen. My children eat every day; theirs should too.',
            'Small amount, given gladly. See you at the next open day.',
        ];

        $note = $notes[$this->next(count($notes))];

        return $this->chance(16) ? $note : null;
    }

    /**
     * @return array{0:string,1:int}
     *
     * @since 1.0.0
     */
    private function pickInterval(): array
    {
        $roll = $this->next(100);
        if ($roll < 74) return ['month', 1];
        if ($roll < 84) return ['month', 3];
        if ($roll < 95) return ['year', 1];

        return ['week', 1];
    }

    /** @since 1.0.0 */
    private function pickPlanStatus(): string
    {
        $roll = $this->next(100);
        if ($roll < 62) return 'active';
        if ($roll < 72) return 'paused';
        if ($roll < 82) return 'past_due';

        return 'cancelled';
    }

    /** @since 1.0.0 */
    private function intervalDays(string $unit, int $count): int
    {
        return match ($unit) {
            'week'  => 7 * $count,
            'year'  => 365 * $count,
            default => 30 * $count,
        };
    }

    /**
     * Front-loaded pick over the roster, so a small group gives often and the
     * long tail gives once. Retention and repeat-donor views need both.
     *
     * @since 1.0.0
     */
    private function pickDonor(): int
    {
        $r = $this->next(10000) / 10000;

        return (int) min(self::DONORS - 1, floor(($r ** 2.1) * self::DONORS));
    }

    /**
     * @param  array<int,int> $weights
     * @return array<int,int>
     *
     * @since 1.0.0
     */
    private function cumulative(array $weights): array
    {
        $out   = [];
        $total = 0;
        foreach ($weights as $w) {
            $total += max(0, $w);
            $out[]  = $total;
        }

        return $out;
    }

    /**
     * @param array<int,int> $cumulative
     *
     * @since 1.0.0
     */
    private function pick(array $cumulative): int
    {
        $total = (int) end($cumulative);
        if ($total <= 0) {
            return 0;
        }

        $roll = $this->next($total);
        foreach ($cumulative as $i => $edge) {
            if ($roll < $edge) {
                return (int) $i;
            }
        }

        return count($cumulative) - 1;
    }

    /** @since 1.0.0 */
    private function chance(int $percent): bool
    {
        return $this->next(100) < $percent;
    }

    /**
     * Seeded LCG rather than mt_rand: the same seed has to produce the same
     * site on every machine, and mt_srand would reseed global state the rest
     * of the request shares.
     *
     * @since 1.0.0
     */
    private function next(int $bound): int
    {
        $this->rng = ($this->rng * 1103515245 + 12345) & 0x7FFFFFFF;

        return $bound > 0 ? intdiv($this->rng, 65536) % $bound : 0;
    }

    /** @since 1.0.0 */
    private function say(string $line): void
    {
        ($this->log)($line);
    }
}
