<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Dashboard\AttentionDismissals;
use Dono\Dashboard\DashboardMetricsService;
use Dono\Donations\Donation;
use Dono\Foundation\Plugin;
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
            $c->get(\Dono\Foundation\Time\Clock::class),
            $c->get(\Dono\Donations\DonationRepository::class),
            $c->get(\Dono\Recurring\RecurringPlanRepository::class),
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
            $d->reference         = 'DONO-F-' . bin2hex(random_bytes(4));
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

    /**
     * The whole point. Dismissing "3 donations failed" cannot also hide the
     * fifty that fail tomorrow.
     */
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

        $req = new WP_REST_Request('POST', '/dono/v1/admin/me/attention/dismiss');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode(['key' => 'failed-donations', 'signature' => $item['signature']]));
        $this->assertSame(200, rest_do_request($req)->get_status());
        $this->assertNull($this->itemFor('failed-donations'));

        $req = new WP_REST_Request('POST', '/dono/v1/admin/me/attention/restore');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode(['key' => 'failed-donations']));
        $this->assertSame(200, rest_do_request($req)->get_status());
        $this->assertNotNull($this->itemFor('failed-donations'));
    }
}
