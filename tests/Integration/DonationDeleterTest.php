<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Analytics\Event;
use Gratora\Donations\Donation;
use Gratora\Donations\DonationDeleter;
use Gratora\Donations\DonationIntent;
use Gratora\Donations\DonationRepository;
use Gratora\Donations\DonationService;
use Gratora\Donations\DonationTrasher;
use Gratora\Donors\Consent;
use Gratora\Donors\Donor;
use Gratora\Foundation\Plugin;
use Gratora\Gateways\GatewayManager;
use Gratora\Gateways\Stripe\StripeAccount;
use Gratora\Gateways\Stripe\StripeGateway;
use InvalidArgumentException;

/**
 * Removing an attempt that never took money, and everything describing it.
 *
 * The invariant the whole design rests on is that no eligible row was ever in a
 * total, so nothing here recalculates anything. That is worth pinning rather
 * than assuming: the day the eligibility set admits a counted row, every stored
 * figure on the site starts drifting silently.
 */
final class DonationDeleterTest extends IntegrationTestCase
{
    /** @var list<string> Stripe paths the stub was asked for. */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        update_option('gratora_gateway_config', [
            'test_mode' => true,
            'offline'   => ['instructions' => 'Transfer the amount quoting your reference.'],
            'stripe'    => ['webhook_secret_test' => 'whsec_del'],
        ]);

        $c    = Plugin::instance()->container;
        $acct = $c->get(StripeAccount::class);
        $acct->saveKeys(true, 'sk_test_del', 'pk_test_seed');
        $acct->refresh(['id' => 'acct_del', 'charges_enabled' => true]);

        $manager = $c->get(GatewayManager::class);
        if (! $manager->get('stripe')) {
            $manager->register(new StripeGateway(
                $c->get(\Gratora\Gateways\Stripe\StripeApi::class),
                $c->get(DonationRepository::class),
                $c->get(DonationService::class),
                $acct,
                $c->get(\Gratora\Donors\DonorRepository::class),
                $c->get(\Gratora\Donors\DonorService::class),
                $c->get(\Gratora\Foundation\Time\Clock::class),
                $c->get(\Gratora\Recurring\RecurringPlanRepository::class),
            ));
        }

        add_filter('pre_http_request', function ($pre, $args, $url) {
            if (! is_string($url) || ! str_starts_with($url, 'https://api.stripe.com/')) return $pre;

            $path          = (string) (parse_url($url)['path'] ?? '');
            $this->calls[] = $path;
            $status        = str_contains($path, '/cancel') ? 'canceled' : 'requires_payment_method';

            return [
                'headers'  => [],
                'body'     => (string) wp_json_encode(['id' => 'pi_del', 'status' => $status]),
                'response' => ['code' => 200, 'message' => 'OK'],
                'cookies'  => [], 'filename' => null,
            ];
        }, 10, 3);
    }

    private function deleter(): DonationDeleter
    {
        return Plugin::instance()->container->get(DonationDeleter::class);
    }

    private function trasher(): DonationTrasher
    {
        return Plugin::instance()->container->get(DonationTrasher::class);
    }

    /** @param array<string,mixed> $columns */
    private function attempt(string $gateway = 'offline', array $columns = []): Donation
    {
        $donation = Plugin::instance()->container->get(DonationService::class)->createPending(new DonationIntent(
            email:        'del-' . uniqid() . '@example.test',
            amount_cents: 2500,
            currency:     'USD',
            gateway:      $gateway,
            frequency:    'one_time',
        ))['donation'];

        if ($columns !== []) {
            $donation->updateColumns($columns);
        }

        return $donation;
    }

    private function exists(Donation $donation): bool
    {
        return Donation::query()->where('id', (int) $donation->id)->get() !== null;
    }

    public function test_a_trashed_row_is_deleted_without_asking_the_gateway_twice(): void
    {
        $donation = $this->attempt('stripe', ['gateway_intent_id' => 'pi_del', 'is_test' => true]);
        $this->trasher()->trash($donation);

        $afterTrash = count($this->calls);
        $this->assertGreaterThan(0, $afterTrash, 'the trash really did reach Stripe');

        $this->deleter()->delete($this->freshRow($donation));

        $this->assertFalse($this->exists($donation));

        // Re-closing a handle trash already closed draws exactly the refusal
        // this treats as fatal, which would make a successful trash a
        // permanent barrier to deleting the row.
        $this->assertCount($afterTrash, $this->calls, 'the gateway was not asked a second time');
    }

    private function freshRow(Donation $donation): Donation
    {
        return Plugin::instance()->container
            ->get(DonationRepository::class)
            ->findById((int) $donation->id);
    }

    public function test_an_untrashed_row_is_refused_by_default(): void
    {
        $donation = $this->attempt();

        $this->expectException(InvalidArgumentException::class);

        try {
            $this->deleter()->delete($donation);
        } finally {
            $this->assertTrue($this->exists($donation), 'permanent delete is offered only from the Trash view');
        }
    }

    /**
     * The donor cascade legitimately removes donations nobody trashed, and a
     * hard precondition would abort every ordinary donor delete silently.
     */
    public function test_the_cascade_may_delete_a_row_that_was_never_trashed(): void
    {
        $donation = $this->attempt();

        $this->deleter()->delete($donation, null, false);

        $this->assertFalse($this->exists($donation));
    }

    public function test_a_paid_row_is_refused_and_survives(): void
    {
        $donation = $this->attempt('offline', ['status' => 'paid', 'paid_at' => gmdate('Y-m-d H:i:s')]);

        $this->expectException(InvalidArgumentException::class);

        try {
            $this->deleter()->delete($donation, null, false);
        } finally {
            $this->assertTrue($this->exists($donation), 'money that moved is never removed');
        }
    }

    public function test_a_row_that_saw_money_is_refused(): void
    {
        $donation = $this->attempt('offline', ['gateway_txn_id' => 'ch_real']);

        $this->expectException(InvalidArgumentException::class);

        try {
            $this->deleter()->delete($donation, null, false);
        } finally {
            $this->assertTrue($this->exists($donation));
        }
    }

    /**
     * Asked before the transaction opens, so an add-on veto is a refusal rather
     * than a half-finished purge.
     */
    public function test_an_add_on_veto_refuses_the_delete(): void
    {
        $donation = $this->attempt();

        add_filter('gratora.donation.undeletable_reason', static fn () => 'An add-on still needs this.');

        try {
            $this->deleter()->delete($donation, null, false);
            $this->fail('the veto should have refused the delete');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('add-on', $e->getMessage());
        } finally {
            remove_all_filters('gratora.donation.undeletable_reason');
        }

        $this->assertTrue($this->exists($donation));
    }

    public function test_deleting_a_retry_child_returns_its_parent_to_the_list(): void
    {
        $parent = $this->attempt();
        $child  = $this->attempt();

        Plugin::instance()->container->get(DonationService::class)
            ->recordRetriedBy($parent, (string) $child->reference);

        $this->deleter()->delete($child, null, false);

        $flags = (array) ($this->freshRow($parent)->flags ?? []);
        $this->assertArrayNotHasKey('retried_by', $flags, 'a live payable row must exist in some screen');
    }

    /**
     * A spam attempt that ticked a consent box would otherwise stand as the
     * donor's current answer and decide their real CRM sends.
     */
    public function test_a_spam_consent_stops_standing_as_the_donors_answer(): void
    {
        $donation = $this->attempt();
        $donorId  = (int) $donation->donor_id;

        $withdrawal = $this->consent($donorId, false, null, '-2 hours');
        $spam       = $this->consent($donorId, true, (int) $donation->id, '-1 hour');

        $this->deleter()->delete($donation, null, false);

        $this->assertNull(
            Consent::query()->where('id', (int) $spam->id)->get(),
            'the attempt\'s consent goes with it'
        );
        $this->assertNotNull(
            Consent::query()->where('id', (int) $withdrawal->id)->get(),
            'and the donor\'s own earlier answer is current again'
        );
    }

    private function consent(int $donorId, bool $granted, ?int $donationId, string $ago): Consent
    {
        $c                     = Consent::make();
        $c->donor_id           = $donorId;
        $c->purpose            = 'marketing';
        $c->granted            = $granted;
        $c->source             = 'donation_form';
        $c->source_donation_id = $donationId;
        $c->occurred_at        = gmdate('Y-m-d H:i:s', strtotime($ago) ?: time());
        $c->save();

        return $c;
    }

    public function test_the_record_of_the_delete_outlives_the_donation(): void
    {
        $donation  = $this->attempt();
        $reference = (string) $donation->reference;

        $this->deleter()->delete($donation, 'card testing', false);

        $rows = Event::query()->where('type', 'donation.deleted')->getAll();
        $this->assertCount(1, $rows);

        $payload = (array) $rows[0]->payload;
        $this->assertSame($reference, $payload['reference'], 'the reference explains the gap in the numbering');
        $this->assertSame('card testing', $payload['note']);
        $this->assertNull($rows[0]->donor_id, 'an admin action is not donor activity');
        $this->assertFalse($this->exists($donation));
    }

    /**
     * A listener that throws aborts the transaction, which is safe here and
     * only here: the maintenance callers of this same hook run no transaction.
     */
    public function test_a_listener_that_refuses_leaves_everything_in_place(): void
    {
        $donation = $this->attempt();

        add_action('gratora.test_data.purge_donations', static function (): void {
            throw new \RuntimeException('an add-on refused');
        });

        try {
            $this->deleter()->delete($donation, null, false);
        } catch (\Throwable $e) {
            // expected
        } finally {
            remove_all_actions('gratora.test_data.purge_donations');
        }

        $this->assertTrue($this->exists($donation), 'a partial delete is worse than none');
        $this->assertSame([], Event::query()->where('type', 'donation.deleted')->getAll());
    }

    /**
     * The invariant everything rests on: an eligible row was never in a total,
     * so removing it moves nothing.
     */
    public function test_no_stored_total_moves(): void
    {
        $donation = $this->attempt();
        $donorId  = (int) $donation->donor_id;

        $before = Donor::query()->where('id', $donorId)->get();
        $total  = (int) $before->total_donated_cents;
        $count  = (int) $before->donations_count;

        $this->deleter()->delete($donation, null, false);

        $after = Donor::query()->where('id', $donorId)->get();
        $this->assertSame($total, (int) $after->total_donated_cents);
        $this->assertSame($count, (int) $after->donations_count);
    }
}
