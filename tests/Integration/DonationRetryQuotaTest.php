<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\Donation;
use Dono\Donations\DonationRepository;
use Dono\Donations\DonationService;
use Dono\Foundation\Plugin;
use Dono\Foundation\Time\Clock;
use Dono\Forms\Form;
use Dono\Gateways\GatewayConfirmResult;
use Dono\Gateways\GatewayIntentResult;
use Dono\Gateways\GatewayManager;
use Dono\Gateways\GatewayReconciler;
use Dono\Gateways\PayPal\PayPalAccount;
use Dono\Gateways\PayPal\PayPalApi;
use Dono\Gateways\PayPal\PayPalGateway;
use Dono\Gateways\PayPal\PayPalPlanRecorder;
use Dono\Gateways\PayPal\PayPalPlans;
use Dono\Gateways\PaymentGateway;
use Dono\Gateways\RefundResult;
use Dono\Gateways\SettlesOutOfBand;
use Dono\Gateways\WebhookOutcome;
use Dono\Recurring\RecurringPlanRepository;
use WP_REST_Request;

/**
 * The email quota is charged per attempt tree, not per HTTP submission.
 *
 * A donor who backs out of PayPal, picks Stripe, backs out again and tries once
 * more is making one donation, and three submissions of it must not spend the
 * whole hourly allowance for their address. A submission carrying the status
 * token of a named, never-funded pending donation continues that donation's
 * attempt tree and spends the tree's own small budget instead.
 *
 * The relief hangs off a server-minted per-donation secret. It can never be
 * bought with a property of the email address, so an attacker cannot open one
 * pending row and stop being counted.
 */
final class DonationRetryQuotaTest extends IntegrationTestCase
{
    private const RETRY_TTL = 1800;

    private const OUT_OF_BAND_ADDON = 'acme-transfer';

    protected function setUp(): void
    {
        parent::setUp();

        // Every quota short-circuits under the org-wide test switch, so the
        // caps this file is about only exist with it off.
        delete_option('dono_gateway_config');
        $_SERVER['REMOTE_ADDR'] = '203.0.113.10';
    }

    // ---------------------------------------------------------------- helpers

    /** @param array<string,mixed> $body */
    private function post(array $body): \WP_REST_Response
    {
        $req = new WP_REST_Request('POST', '/dono/v1/donations');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode($body));

        return rest_do_request($req);
    }

    /**
     * One submission of the same donation, optionally continuing an earlier
     * attempt.
     *
     * @param array{reference:string,status_token:string}|null $retry
     * @param array<string,mixed>                              $overrides
     */
    private function submit(string $email, ?array $retry = null, array $overrides = []): \WP_REST_Response
    {
        return $this->post(array_merge([
            'email'        => $email,
            'amount_cents' => 2500,
            'currency'     => 'USD',
            'gateway'      => 'offline',
            'profile'      => ['first_name' => 'Rea', 'last_name' => 'Try'],
        ], $overrides, $retry === null ? [] : ['_retry' => $retry]));
    }

    /**
     * The claim the browser holds, on a row wearing a gateway with a checkout.
     *
     * These tests are about a checkout the donor could back out of, and offline
     * is the only gateway registered with the org-wide test switch off, which
     * every quota here needs. So the rows are posted through offline and then
     * wear a card gateway, the way the scenarios read. A pending offline row is
     * a transfer the org is waiting for and is never claimable, which
     * OfflineRetryParentTest covers.
     *
     * The rewrite is unconditional, so a caller naming a gateway of its own
     * would be describing a row this leaves behind on a different one. A test
     * that means the gateway it posted to calls claimAsPosted.
     *
     * @return array{reference:string,status_token:string}
     */
    private function claimOf(\WP_REST_Response $res): array
    {
        $claim = $this->claimAsPosted($res);

        self::$wpdb->query(self::$wpdb->prepare(
            'UPDATE ' . self::$prefix . 'dono_donations SET gateway = %s WHERE reference = %s',
            'stripe',
            $claim['reference']
        ));

        return $claim;
    }

    /**
     * The same claim with the row left on the gateway it was posted to.
     *
     * @return array{reference:string,status_token:string}
     */
    private function claimAsPosted(\WP_REST_Response $res): array
    {
        $data = $res->get_data();
        $this->assertSame(201, $res->get_status(), (string) wp_json_encode($data));

        return [
            'reference'    => (string) $data['reference'],
            'status_token' => (string) $data['status_token'],
        ];
    }

    /**
     * Burn the whole hourly email allowance so the next refusal is visible, and
     * hand back the first root, whose tree the retries then continue.
     *
     * @return array{reference:string,status_token:string}
     */
    private function exhaustEmailQuota(string $email): array
    {
        $first = $this->claimOf($this->submit($email));
        for ($i = 2; $i <= 3; $i++) {
            $this->assertSame(201, $this->submit($email)->get_status(), "root {$i} should pass");
        }

        return $first;
    }

    /** @return array<string,mixed> */
    private function flagsOf(string $reference): array
    {
        $raw = self::$wpdb->get_var(self::$wpdb->prepare(
            'SELECT flags FROM ' . self::$prefix . 'dono_donations WHERE reference = %s',
            $reference
        ));

        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function groupOf(string $reference): string
    {
        return (string) ($this->flagsOf($reference)['retry']['group'] ?? '');
    }

    private function bornOf(string $reference): int
    {
        return (int) ($this->flagsOf($reference)['retry']['born'] ?? 0);
    }

    /** Rewrite the tree descriptor a row carries, leaving created_at alone. */
    private function setRetryFlag(string $reference, ?array $retry): void
    {
        $flags = $this->flagsOf($reference);
        if ($retry === null) {
            unset($flags['retry']);
        } else {
            $flags['retry'] = $retry;
        }

        self::$wpdb->query(self::$wpdb->prepare(
            'UPDATE ' . self::$prefix . 'dono_donations SET flags = %s WHERE reference = %s',
            $flags === [] ? null : (string) wp_json_encode($flags),
            $reference
        ));
    }

    private function setColumn(string $reference, string $column, mixed $value): void
    {
        // $column is a fixed test-supplied identifier, never user input.
        self::$wpdb->query(self::$wpdb->prepare(
            'UPDATE ' . self::$prefix . "dono_donations SET {$column} = %s WHERE reference = %s",
            $value,
            $reference
        ));
    }

    private function emailCounter(): int
    {
        return (int) self::$wpdb->get_var(
            "SELECT option_value FROM " . self::$wpdb->options . "
             WHERE option_name LIKE '_transient_dono_donate_email_%'
             ORDER BY option_id DESC LIMIT 1"
        );
    }

    private function assertRateLimited(\WP_REST_Response $res, string $because): void
    {
        $this->assertSame(429, $res->get_status(), $because . ': ' . (string) wp_json_encode($res->get_data()));
        $this->assertSame('dono_rate_limited', $res->get_data()['code'] ?? null, $because);
    }

    // ------------------------------------------------------- the reported bug

    /**
     * PayPal, cancel, Stripe, cancel, PayPal. Three submissions of one
     * donation, and the address is charged once.
     */
    public function test_a_donor_who_backs_out_and_tries_another_gateway_is_not_locked_out(): void
    {
        $email = 'switcher@example.test';

        $root  = $this->claimOf($this->submit($email));
        $again = $this->claimOf($this->submit($email, $root));
        $third = $this->claimOf($this->submit($email, $again));

        $this->assertNotSame($root['reference'], $again['reference']);
        $this->assertNotSame($again['reference'], $third['reference']);

        $rows = (int) self::$wpdb->get_var(
            'SELECT COUNT(*) FROM ' . self::$prefix . 'dono_donations'
        );
        $this->assertSame(3, $rows, 'each attempt is still its own row');

        $this->assertSame(1, $this->emailCounter(), 'the address is charged once for the whole tree');
    }

    // ------------------------------------------------------------ the bounds

    /**
     * Each hop names the one before it, which is the shape design 3 was written
     * for. The counter is the tree's, so depth buys nothing.
     */
    public function test_the_tree_budget_bounds_a_chain(): void
    {
        $email = 'chain@example.test';
        $root  = $this->exhaustEmailQuota($email);

        $one = $this->claimOf($this->submit($email, $root));
        $two = $this->claimOf($this->submit($email, $one));

        $this->assertRateLimited($this->submit($email, $two), 'the third retry is past the tree budget');
    }

    /**
     * The descriptor has to survive an extension point. DonationIntent is
     * readonly and FormTypeHandler is told to return a new instance, so a
     * handler or filter that rebuilds one without the retry field would give
     * every hop a fresh root with a full budget, and one paid submission would
     * buy an unbounded chain.
     */
    public function test_the_tree_budget_survives_a_rebuilt_intent(): void
    {
        $email = 'rebuilt@example.test';

        $rebuild = static function ($intent) {
            $args = [];
            foreach ((new \ReflectionClass($intent))->getConstructor()->getParameters() as $param) {
                $name = $param->getName();
                if ($name === 'retry') continue;
                $args[$name] = $intent->$name;
            }
            return new \Dono\Donations\DonationIntent(...$args);
        };

        add_filter('dono.donation.intent_creating', $rebuild, 10, 1);

        try {
            $root = $this->exhaustEmailQuota($email);
            $one  = $this->claimOf($this->submit($email, $root));
            $two  = $this->claimOf($this->submit($email, $one));

            $this->assertRateLimited(
                $this->submit($email, $two),
                'a rebuilt intent must not mint a fresh tree budget'
            );
        } finally {
            remove_filter('dono.donation.intent_creating', $rebuild, 10);
        }
    }

    /**
     * Three retries naming the same root directly. A budget kept per row, or
     * per hop depth, passes the chain test and fails this one.
     */
    public function test_the_tree_budget_bounds_a_branch(): void
    {
        $email = 'branch@example.test';
        $root  = $this->exhaustEmailQuota($email);

        $this->assertSame(201, $this->submit($email, $root)->get_status(), 'branch 1');
        $this->assertSame(201, $this->submit($email, $root)->get_status(), 'branch 2');

        $this->assertRateLimited($this->submit($email, $root), 'the third branch is past the tree budget');
    }

    /**
     * Age is measured from the root's birth, which every descendant inherits
     * verbatim. Measuring from the immediate parent lets a chain of
     * individually recent hops walk a tree forward with no end: this row was
     * created seconds ago and only its inherited birth is old.
     */
    public function test_the_tree_ages_from_its_root_not_from_the_row_being_claimed(): void
    {
        $email = 'aged@example.test';
        $root  = $this->exhaustEmailQuota($email);
        $hop   = $this->claimOf($this->submit($email, $root));

        // One of the two tree slots is still unspent, so a refusal here is the
        // age and nothing else.
        $this->setRetryFlag($hop['reference'], [
            'group' => $this->groupOf($hop['reference']),
            'born'  => time() - self::RETRY_TTL - 60,
        ]);

        $fresh = (string) self::$wpdb->get_var(self::$wpdb->prepare(
            'SELECT created_at FROM ' . self::$prefix . 'dono_donations WHERE reference = %s',
            $hop['reference']
        ));
        $this->assertGreaterThan(
            time() - 300,
            strtotime($fresh . ' UTC'),
            'the row being claimed is itself recent, so only the inherited birth can refuse it'
        );

        $this->assertRateLimited($this->submit($email, $hop), 'a tree older than its TTL is closed');
    }

    /**
     * The counter's address is the root's birth, so no wall-clock boundary can
     * move it. Crossing one is observationally the same as the current bucket
     * not being the bucket the earlier attempts wrote, which is what discarding
     * every wall-clock-addressed counter reproduces.
     */
    public function test_the_tree_counter_does_not_reset_on_a_wall_clock_boundary(): void
    {
        $email = 'boundary@example.test';
        $root  = $this->exhaustEmailQuota($email);

        $this->assertSame(201, $this->submit($email, $root)->get_status(), 'retry 1');
        $this->assertSame(201, $this->submit($email, $root)->get_status(), 'retry 2');

        $born = $this->bornOf($root['reference']);
        self::$wpdb->query(self::$wpdb->prepare(
            'DELETE FROM ' . self::$wpdb->options . '
             WHERE option_name LIKE %s AND option_name NOT LIKE %s',
            self::$wpdb->esc_like('_transient_dono_donate_retry_') . '%',
            '%' . self::$wpdb->esc_like('_' . $born)
        ));

        $this->assertRateLimited($this->submit($email, $root), 'the spent budget survives the boundary');
    }

    // ------------------------------------------------------------ the refusals

    /**
     * A wrong, empty or absent token buys nothing, and says nothing about which
     * references exist: the answer is the rate limit, never a 404.
     */
    public function test_a_token_that_is_not_the_browsers_gets_no_relief_and_leaks_nothing(): void
    {
        $email = 'notoken@example.test';
        $root  = $this->exhaustEmailQuota($email);

        $this->assertRateLimited(
            $this->submit($email, ['reference' => $root['reference'], 'status_token' => 'not-the-one']),
            'a wrong token'
        );
        $this->assertRateLimited(
            $this->submit($email, ['reference' => $root['reference'], 'status_token' => '']),
            'an empty token'
        );
        $this->assertRateLimited($this->submit($email), 'no claim at all');
    }

    /** One root must not buy free rows for every address an attacker types. */
    public function test_relief_cannot_be_laundered_onto_another_address(): void
    {
        $mine  = 'owner@example.test';
        $yours = 'stranger@example.test';

        $root = $this->claimOf($this->submit($mine));

        $first = $this->claimOf($this->submit($yours, $root));
        $this->assertSame(
            $first['reference'],
            $this->groupOf($first['reference']),
            'a refused claim opens the new address its own tree'
        );

        $this->assertSame(201, $this->submit($yours, $root)->get_status(), 'second on the new address');
        $this->assertSame(201, $this->submit($yours, $root)->get_status(), 'third on the new address');
        $this->assertRateLimited($this->submit($yours, $root), 'the new address pays its own quota');
    }

    /** Mirrors the form scoping already folded into the signed form token. */
    public function test_a_retry_naming_a_different_form_is_refused(): void
    {
        $form  = $this->publishedForm();
        $email = 'formscope@example.test';

        $root = $this->claimOf($this->submit($email, null, ['form_id' => (int) $form->id]));

        // Same donor, same tree named, but a submission the form did not scope.
        $loose = $this->claimOf($this->submit($email, $root));
        $this->assertSame(
            $loose['reference'],
            $this->groupOf($loose['reference']),
            'a claim across a form boundary opens a new tree instead'
        );
    }

    /**
     * No relief is ever spent off a row that has seen money. paid is the case
     * that matters; the rest are rows whose flow already reported an end.
     *
     * @dataProvider unclaimableRows
     */
    public function test_a_parent_that_is_not_an_open_attempt_is_never_claimable(
        string $label,
        array $overrides
    ): void {
        $email = 'unclaimable-' . $label . '@example.test';
        // A fresh source per case, so the per-IP cap does not decide the run.
        $_SERVER['REMOTE_ADDR'] = '198.51.100.' . abs(crc32($label) % 200);

        $root = $this->claimOf($this->submit($email));
        foreach ($overrides as $column => $value) {
            $this->setColumn($root['reference'], $column, $value);
        }

        $child = $this->claimOf($this->submit($email, $root));

        $this->assertSame(
            $child['reference'],
            $this->groupOf($child['reference']),
            "a {$label} parent must not hand its tree on"
        );
        $this->assertSame(2, $this->emailCounter(), "a {$label} parent is charged the ordinary quota");
    }

    /** @return array<string,array{0:string,1:array<string,mixed>}> */
    public static function unclaimableRows(): array
    {
        return [
            'paid'           => ['paid',           ['status' => 'paid', 'paid_at' => '2026-08-17 10:00:00']],
            'processing'     => ['processing',     ['status' => 'processing']],
            'failed'         => ['failed',         ['status' => 'failed']],
            'refunded'       => ['refunded',       ['status' => 'refunded']],
            'partial_refund' => ['partialrefund',  ['status' => 'partial_refund']],
            'pending_paid_at' => ['pendingpaidat', ['paid_at' => '2026-08-17 10:00:00']],
            'pending_refunded' => ['pendingrefunded', ['refunded_cents' => 100]],
        ];
    }

    /**
     * A row with no tree descriptor is unclaimable, which covers admin-recorded
     * donations, every non-form path, and anything already in the table.
     */
    public function test_a_parent_carrying_no_tree_descriptor_is_refused(): void
    {
        $email = 'nodescriptor@example.test';

        $root = $this->claimOf($this->submit($email));
        $this->setRetryFlag($root['reference'], null);

        $child = $this->claimOf($this->submit($email, $root));

        $this->assertSame($child['reference'], $this->groupOf($child['reference']));
        $this->assertSame(2, $this->emailCounter(), 'the ordinary quota is charged');
    }

    /**
     * A bank transfer taken by a gateway shipped outside core is the same
     * awaited transfer as one taken by the core offline gateway, and its
     * pending row is the same queue entry. The gateway says so by implementing
     * SettlesOutOfBand: a list of ids inside the guard could only ever name the
     * gateways core happens to ship.
     */
    public function test_an_add_on_gateway_that_settles_out_of_band_is_never_adopted(): void
    {
        $this->registerOutOfBandGateway();

        $email = 'addonwire@example.test';
        $on    = ['gateway' => self::OUT_OF_BAND_ADDON];

        $parent = $this->claimAsPosted($this->submit($email, null, $on));
        $second = $this->claimAsPosted($this->submit($email, $parent, $on));

        $this->assertArrayNotHasKey(
            'retried_by',
            $this->flagsOf($parent['reference']),
            'an awaited transfer must not be marked replaced'
        );
        $this->assertSame(
            $second['reference'],
            $this->groupOf($second['reference']),
            'the refused claim opens its own attempt tree'
        );
        $this->assertSame(2, $this->emailCounter(), 'the second submission pays the ordinary email quota');
    }

    /**
     * The cap that actually bounds a single-source attacker is untouched: every
     * submission is charged, retries included.
     */
    public function test_the_ip_quota_is_still_charged_on_every_retry(): void
    {
        $sent = 0;
        // Three trees of three, so six of the nine are token-proved retries.
        foreach (['a', 'b', 'c'] as $who) {
            $email = "ipcap-{$who}@example.test";
            $root  = $this->claimOf($this->submit($email));
            $sent++;
            $one = $this->claimOf($this->submit($email, $root));
            $sent++;
            $this->claimOf($this->submit($email, $one));
            $sent++;
        }

        $this->assertSame(9, $sent);
        $this->assertSame(201, $this->submit('ipcap-d@example.test')->get_status(), 'the tenth still passes');
        $this->assertRateLimited($this->submit('ipcap-e@example.test'), 'the eleventh is over the per-IP cap');
    }

    // ---------------------------------------------------------- the breadcrumbs

    /**
     * Every row names the root, and the abandoned parent names its replacement
     * as a decoded array. Handing an unencoded array to the query builder
     * writes the literal string "Array" and destroys the column, so the shape
     * of the value is the assertion.
     */
    /**
     * The relief is for one donation tried a second way, so a submission that
     * describes a different donation is not that. An accepted claim stamps
     * retried_by on the parent, and the parent's detail page renders it as the
     * donation that replaced this one, so a $1000 attempt could otherwise be
     * reported as replaced by a EUR 1.00 one: a false account of one donor's
     * decision, and what lets an unrelated donation hide an earlier one.
     */
    public function test_a_submission_for_a_different_donation_does_not_replace_the_parent(): void
    {
        $email = 'drifted@example.test';

        $parent = $this->claimOf($this->submit($email, null, ['amount_cents' => 100000, 'currency' => 'USD']));

        $res = $this->submit($email, $parent, ['amount_cents' => 100, 'currency' => 'EUR']);
        $this->assertSame(201, $res->get_status(), 'the donation itself is still taken');

        $flags = $this->flagsOf($parent['reference']);
        $this->assertArrayNotHasKey(
            'retried_by',
            $flags,
            'the $1000 attempt is not reported as replaced by a EUR 1.00 one'
        );
        $this->assertSame(
            $res->get_data()['reference'],
            $this->groupOf((string) $res->get_data()['reference']),
            'and it opens a tree of its own rather than spending the parent\'s'
        );
    }

    /** A donor who changes only the gateway is still the same donation. */
    public function test_the_same_donation_by_another_gateway_still_claims_the_tree(): void
    {
        $email = 'samedonation@example.test';

        $parent = $this->claimOf($this->submit($email, null, ['amount_cents' => 100000, 'currency' => 'USD']));
        $child  = $this->submit($email, $parent, ['amount_cents' => 100000, 'currency' => 'USD']);

        $this->assertSame(201, $child->get_status());
        $this->assertSame(
            $child->get_data()['reference'],
            $this->flagsOf($parent['reference'])['retried_by'] ?? null
        );
    }

    public function test_every_row_names_its_root_and_the_parent_names_its_replacement(): void
    {
        $email = 'breadcrumb@example.test';

        $root = $this->claimOf($this->submit($email));
        $one  = $this->claimOf($this->submit($email, $root));
        $two  = $this->claimOf($this->submit($email, $one));

        $born = $this->bornOf($root['reference']);
        $this->assertGreaterThan(0, $born);

        foreach ([$root, $one, $two] as $row) {
            $this->assertSame($root['reference'], $this->groupOf($row['reference']), 'group is the root');
            $this->assertSame($born, $this->bornOf($row['reference']), 'born is the root\'s');
        }

        $rootFlags = $this->flagsOf($root['reference']);
        $oneFlags  = $this->flagsOf($one['reference']);
        $this->assertSame($one['reference'], $rootFlags['retried_by'] ?? null);
        $this->assertSame($two['reference'], $oneFlags['retried_by'] ?? null);
        $this->assertIsArray($rootFlags['retry'] ?? null, 'the breadcrumb write must not destroy the column');

        $after = self::$wpdb->get_row(self::$wpdb->prepare(
            'SELECT status, gateway_intent_id FROM ' . self::$prefix . 'dono_donations WHERE reference = %s',
            $root['reference']
        ));
        $this->assertSame('pending', $after->status, 'no status is moved');
        // The literal, not a snapshot: reading the column after the breadcrumb
        // write and comparing it to a later read of the same row compares a
        // value to itself and holds however badly the write behaved.
        $this->assertSame(
            'offline_' . $root['reference'],
            (string) $after->gateway_intent_id,
            'the reconciler handle is untouched'
        );
    }

    /**
     * Why no status is moved: the parent stays in the sweep that exists to find
     * money PayPal took with no local record of it.
     */
    public function test_a_retried_parent_is_still_settled_by_the_sweep(): void
    {
        $this->withPayPal();

        $donation = $this->paypalPending();

        Plugin::instance()->container->get(DonationService::class)
            ->recordRetriedBy($donation, 'DONO-2026-99999');

        Plugin::instance()->container->get(GatewayReconciler::class)->run();

        $after = Plugin::instance()->container->get(DonationRepository::class)
            ->findByReference($donation->reference);

        $this->assertSame('paid', $after->status, 'a retried parent is still reachable by the sweep');
        $this->assertSame('CAPTURE-RETRY-1', $after->gateway_txn_id);
        $this->assertSame('DONO-2026-99999', (string) ($after->flags['retried_by'] ?? ''));
    }

    // ------------------------------------------------------------- fixtures

    /** A gateway of the shape an add-on registers: cheques, banked by hand. */
    private function registerOutOfBandGateway(): void
    {
        // The registry is process-wide and has no unregister. Naming the id
        // here first is what snapshots it, so tearDown puts the registry back
        // without this gateway and no later test in the process sees it.
        $this->deregisterGateway(self::OUT_OF_BAND_ADDON);

        Plugin::instance()->container->get(GatewayManager::class)->register(
            // The id rides the constructor: an anonymous class is its own
            // class and cannot read a private constant of this one.
            new class (self::OUT_OF_BAND_ADDON) implements PaymentGateway, SettlesOutOfBand {
                public function __construct(private string $gatewayId)
                {
                }
                public function id(): string { return $this->gatewayId; }
                public function label(): string { return 'Acme Transfer'; }
                public function description(): string { return ''; }
                public function frequencies(): array { return ['one_time']; }
                public function paymentMethods(): array { return ['bank_transfer']; }
                public function countries(): array { return ['*']; }
                public function currencies(): array { return ['*']; }
                public function canCharge(): bool { return true; }
                public function createIntent(Donation $d): GatewayIntentResult
                {
                    return new GatewayIntentResult(intent_id: 'acme_' . $d->reference);
                }
                public function confirm(Donation $d, array $p = []): GatewayConfirmResult
                {
                    return new GatewayConfirmResult(success: true);
                }
                public function handleWebhook(WP_REST_Request $r): WebhookOutcome
                {
                    return WebhookOutcome::notSupported($this->gatewayId);
                }
                public function refund(Donation $d, int $amountCents, ?string $reason = null): RefundResult
                {
                    return RefundResult::failure('not supported');
                }
            }
        );
    }

    private function publishedForm(): Form
    {
        $f = Form::make();
        $f->title      = 'Retry form';
        $f->slug       = 'retry-' . uniqid();
        $f->status     = 'published';
        $f->blocks     = '<!-- wp:dono/donation-amount /--><!-- wp:dono/email /--><!-- wp:dono/submit-button /-->';
        $f->created_at = gmdate('Y-m-d H:i:s');
        $f->updated_at = $f->created_at;
        $f->save();

        return $f;
    }

    private function withPayPal(): void
    {
        update_option('dono_gateway_config', ['test_mode' => true]);
        update_option('dono_currency_locale', [
            'default_currency'     => 'USD',
            'supported_currencies' => ['USD'],
        ]);
        delete_option('dono_gateway_reconcile_cursor');

        $account = Plugin::instance()->container->get(PayPalAccount::class);
        $account->forget();
        $account->saveKeys(true, 'client-retry', 'secret-retry');
        $account->saveWebhookId(true, 'WH-RETRY-1');

        add_filter('pre_http_request', function ($pre, $args, $url) {
            if (! is_string($url) || ! str_contains($url, 'paypal.com')) return $pre;

            $body = str_contains($url, '/v1/oauth2/token')
                ? ['access_token' => 'A21AAF_test', 'expires_in' => 32400]
                : [
                    'id'             => 'ORDER-RETRY-1',
                    'status'         => 'COMPLETED',
                    'purchase_units' => [[
                        'payments' => ['captures' => [[
                            'id'     => 'CAPTURE-RETRY-1',
                            'status' => 'COMPLETED',
                            'amount' => ['currency_code' => 'USD', 'value' => '40.00'],
                        ]]],
                    ]],
                ];

            return [
                'headers'  => [],
                'body'     => (string) wp_json_encode($body),
                'response' => ['code' => 200, 'message' => 'OK'],
                'cookies'  => [], 'filename' => null,
            ];
        }, 10, 3);

        $c       = Plugin::instance()->container;
        $manager = $c->get(GatewayManager::class);
        if (! $manager->get('paypal')) {
            $manager->register(new PayPalGateway(
                $c->get(PayPalApi::class),
                $account,
                $c->get(DonationRepository::class),
                $c->get(DonationService::class),
                $c->get(PayPalPlans::class),
                $c->get(RecurringPlanRepository::class),
                $c->get(Clock::class),
                $c->get(PayPalPlanRecorder::class),
            ));
        }
    }

    /** A pending one-time PayPal donation old enough for the stranded sweep. */
    private function paypalPending(): Donation
    {
        $data = $this->post([
            'email'        => 'sweep@example.test',
            'amount_cents' => 4000,
            'currency'     => 'USD',
            'gateway'      => 'paypal',
            'frequency'    => 'one_time',
            'profile'      => ['first_name' => 'Sw', 'last_name' => 'Eep'],
        ])->get_data();

        $this->assertArrayHasKey('reference', $data, (string) wp_json_encode($data));

        $repo     = Plugin::instance()->container->get(DonationRepository::class);
        $donation = $repo->findByReference((string) $data['reference']);

        $donation->status            = 'pending';
        $donation->gateway_intent_id = 'ORDER-RETRY-1';
        $donation->created_at        = gmdate('Y-m-d H:i:s', time() - 7200);
        $donation->save();

        return $repo->findByReference($donation->reference);
    }
}
