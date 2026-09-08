<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Dashboard\AttentionDismissals;
use FundKit\Dashboard\DashboardMetricsService;
use FundKit\Donations\Donation;
use FundKit\Foundation\Plugin;
use WP_REST_Request;

/**
 * Waving off an attention item must not swallow the next, worse version of it:
 * a dismissal is tied to the state that produced it and lapses when that state
 * moves on. It is also per user, because one admin marking a note as read says
 * nothing about their colleague.
 */
final class AttentionDismissalTest extends IntegrationTestCase
{
    private function metrics(): DashboardMetricsService
    {
        $c = Plugin::instance()->container;

        return new DashboardMetricsService(
            $c->get(\FundKit\Foundation\Time\Clock::class),
            $c->get(\FundKit\Donations\DonationRepository::class),
            $c->get(\FundKit\Recurring\RecurringPlanRepository::class),
        );
    }

    /** @return array<string,mixed>|null */
    private function itemFor(string $key): ?array
    {
        foreach ($this->metrics()->attention() as $item) {
            if (($item['key'] ?? '') === $key) return $item;
        }
        return null;
    }

    private function failDonations(int $howMany): void
    {
        $now = gmdate('Y-m-d H:i:s');
        for ($i = 0; $i < $howMany; $i++) {
            $d = Donation::make();
            $d->reference         = 'FUNDKIT-F-' . bin2hex(random_bytes(4));
            $d->donor_id          = 1;
            $d->amount_cents      = 2500;
            $d->currency          = 'USD';
            $d->base_amount_cents = 2500;
            $d->gateway           = 'stripe';
            $d->status            = 'failed';
            $d->is_test           = false;
            $d->created_at        = $now;
            $d->updated_at        = $now;
            $d->save();
        }
    }

    private function beAdmin(): int
    {
        $id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($id);
        return $id;
    }

    public function test_an_item_disappears_once_dismissed(): void
    {
        $this->beAdmin();
        $this->failDonations(3);

        $item = $this->itemFor('failed-donations');
        $this->assertNotNull($item, 'the item is there to begin with');

        (new AttentionDismissals())->dismiss(get_current_user_id(), 'failed-donations', $item['signature']);

        $this->assertNull($this->itemFor('failed-donations'));
    }

    public function test_a_dismissal_lapses_when_the_situation_gets_worse(): void
    {
        $this->beAdmin();
        $this->failDonations(3);

        $item = $this->itemFor('failed-donations');
        (new AttentionDismissals())->dismiss(get_current_user_id(), 'failed-donations', $item['signature']);
        $this->assertNull($this->itemFor('failed-donations'), 'quiet at three');

        $this->failDonations(5);

        $back = $this->itemFor('failed-donations');
        $this->assertNotNull($back, 'eight is a different situation');
        $this->assertNotSame($item['signature'], $back['signature']);
    }

    public function test_one_admin_dismissing_does_not_hide_it_from_another(): void
    {
        $first = $this->beAdmin();
        $this->failDonations(2);
        $item = $this->itemFor('failed-donations');
        (new AttentionDismissals())->dismiss($first, 'failed-donations', $item['signature']);
        $this->assertNull($this->itemFor('failed-donations'));

        $this->beAdmin();
        $this->assertNotNull($this->itemFor('failed-donations'), 'their colleague still sees it');
    }

    public function test_restore_brings_it_back(): void
    {
        $this->beAdmin();
        $this->failDonations(2);
        $item = $this->itemFor('failed-donations');

        $store = new AttentionDismissals();
        $store->dismiss(get_current_user_id(), 'failed-donations', $item['signature']);
        $this->assertNull($this->itemFor('failed-donations'));

        $store->restore(get_current_user_id(), 'failed-donations');
        $this->assertNotNull($this->itemFor('failed-donations'));
    }

    public function test_the_routes_dismiss_and_restore_for_the_current_user(): void
    {
        $this->beAdmin();
        $this->failDonations(4);
        $item = $this->itemFor('failed-donations');

        $req = new WP_REST_Request('POST', '/fundkit/v1/admin/me/attention/dismiss');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode(['key' => 'failed-donations', 'signature' => $item['signature']]));
        $this->assertSame(200, rest_do_request($req)->get_status());
        $this->assertNull($this->itemFor('failed-donations'));

        $req = new WP_REST_Request('POST', '/fundkit/v1/admin/me/attention/restore');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode(['key' => 'failed-donations']));
        $this->assertSame(200, rest_do_request($req)->get_status());
        $this->assertNotNull($this->itemFor('failed-donations'));
    }

    /**
     * "3 donations failed in the last 24 hours" is a rolling window, so its
     * count falls on its own as the oldest failure ages out. Comparing the
     * stored signature for inequality reopens the item at 2 and again at 1: the
     * admin waves off the same three failures three times.
     */
    public function test_a_dismissed_failure_does_not_return_as_the_window_drains(): void
    {
        $userId = $this->beAdmin();
        $this->failDonations(3);

        $item = $this->itemFor('failed-donations');
        $this->assertNotNull($item);
        $this->assertSame(3, (int) $item['count']);

        (new AttentionDismissals())->dismiss($userId, 'failed-donations', AttentionDismissals::signatureFor($item));
        $this->assertNull($this->itemFor('failed-donations'), 'precondition: it is waved off at three');

        // The oldest failure leaves the window. By id, because the builder's
        // limit does not carry into an update.
        $ids = array_map(
            static fn (Donation $d): int => (int) $d->id,
            Donation::query()->where('status', 'failed')->orderBy('id', 'ASC')->getAll()
        );
        Donation::query()
            ->where('id', $ids[0])
            ->update(['updated_at' => gmdate('Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS)]);

        $this->assertNull(
            $this->itemFor('failed-donations'),
            'the same failures came back as news because one of them aged out'
        );

        // A colleague who waved off nothing proves the count really fell,
        // rather than the item having gone away for everyone.
        $this->beAdmin();
        $theirs = $this->itemFor('failed-donations');
        $this->assertNotNull($theirs);
        $this->assertSame(2, (int) $theirs['count']);
    }

    public function test_a_rising_count_reopens_the_item(): void
    {
        $userId = $this->beAdmin();
        $this->failDonations(3);

        (new AttentionDismissals())->dismiss(
            $userId,
            'failed-donations',
            AttentionDismissals::signatureFor($this->itemFor('failed-donations'))
        );
        $this->assertNull($this->itemFor('failed-donations'), 'precondition');

        $this->failDonations(2);

        $back = $this->itemFor('failed-donations');
        $this->assertNotNull($back, 'five is a worse situation than three');
        $this->assertSame(5, (int) $back['count']);
    }

    private function publishedCampaign(int $n): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $c   = \FundKit\Campaigns\Campaign::make();
        $c->title      = 'Queue ' . $n;
        $c->slug       = 'queue-' . $n . '-' . bin2hex(random_bytes(3));
        $c->status     = 'published';
        $c->currency   = 'USD';
        $c->created_at = $now;
        $c->updated_at = $now;
        $c->save();
    }

    /** @return list<string> */
    private function offeredKeys(string $prefix): array
    {
        $out = [];
        foreach ($this->metrics()->attention() as $item) {
            $key = (string) ($item['key'] ?? '');
            if (str_starts_with($key, $prefix)) $out[] = $key;
        }

        return $out;
    }

    /**
     * The per-campaign queues are cut to twenty in SQL and filtered for
     * dismissals afterwards, so waving off a full page took the page with it:
     * the campaigns behind it were never selected, so they were never offered
     * and the queue could not be worked through.
     */
    public function test_dismissing_the_first_page_does_not_hide_the_campaigns_behind_it(): void
    {
        $userId = $this->beAdmin();
        for ($i = 1; $i <= 25; $i++) {
            $this->publishedCampaign($i);
        }

        $first = $this->offeredKeys('no-form-');
        $this->assertCount(20, $first, 'the queue is a page at a time');

        $dismissals = new AttentionDismissals();
        foreach ($first as $key) {
            $dismissals->dismiss($userId, $key, 'x');
        }

        $second = $this->offeredKeys('no-form-');

        $this->assertNotSame([], $second, 'the rest of the queue is unreachable');
        $this->assertSame([], array_intersect($first, $second), 'and it is the rest, not the same page again');
    }
}
