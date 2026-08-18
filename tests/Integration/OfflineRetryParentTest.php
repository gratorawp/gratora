<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\DonationRepository;
use Dono\Foundation\Plugin;
use Dono\Forms\Form;
use WP_REST_Request;

/**
 * A pending offline donation is money the org is still expecting, quoted
 * against its own reference on the bank details the donor was shown. It is not
 * an abandoned checkout, so a later submission must not adopt it as a retry
 * parent: the adoption stamps retried_by, and every pending row carrying
 * retried_by drops out of the admin list, the CSV export, the KPI counts and
 * the donor's own donation list, which is exactly the queue the transfer has to
 * be reconciled against when it lands.
 */
final class OfflineRetryParentTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Every quota short-circuits under the org-wide test switch, and the
        // admin list is live-only, so both halves of this file need it off.
        delete_option('dono_gateway_config');
        $_SERVER['REMOTE_ADDR'] = '203.0.113.44';
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
     * @param array{reference:string,status_token:string}|null $retry
     * @param array<string,mixed>                              $overrides
     */
    private function submit(string $email, ?array $retry = null, array $overrides = []): \WP_REST_Response
    {
        return $this->post(array_merge([
            'email'        => $email,
            'amount_cents' => 25000,
            'currency'     => 'USD',
            'gateway'      => 'offline',
            'profile'      => ['first_name' => 'Wire', 'last_name' => 'Sender'],
        ], $overrides, $retry === null ? [] : ['_retry' => $retry]));
    }

    /** @return array{reference:string,status_token:string} */
    private function claimOf(\WP_REST_Response $res): array
    {
        $data = $res->get_data();
        $this->assertSame(201, $res->get_status(), (string) wp_json_encode($data));

        return [
            'reference'    => (string) $data['reference'],
            'status_token' => (string) $data['status_token'],
        ];
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

    private function setGateway(string $reference, string $gateway): void
    {
        self::$wpdb->query(self::$wpdb->prepare(
            'UPDATE ' . self::$prefix . 'dono_donations SET gateway = %s WHERE reference = %s',
            $gateway,
            $reference
        ));
    }

    private function emailCounter(): int
    {
        return (int) self::$wpdb->get_var(
            'SELECT option_value FROM ' . self::$wpdb->options . "
             WHERE option_name LIKE '_transient_dono_donate_email_%'
             ORDER BY option_id DESC LIMIT 1"
        );
    }

    /**
     * References an admin sees on a default browse of the donations list, which
     * is the same filter set the CSV export and the KPI counts run.
     *
     * @param array<string,mixed> $args
     * @return array<string>
     */
    private function adminList(array $args = []): array
    {
        $page = Plugin::instance()->container->get(DonationRepository::class)->listAdmin($args);

        return array_map(static fn ($row) => (string) $row->reference, $page['items']);
    }

    /** @param array<string,mixed> $args */
    private function adminTotal(array $args = []): int
    {
        return (int) Plugin::instance()->container
            ->get(DonationRepository::class)
            ->listAdmin($args)['total'];
    }

    private function publishedForm(): Form
    {
        $f = Form::make();
        $f->title      = 'Bank transfer form';
        $f->slug       = 'offline-retry-' . uniqid();
        $f->status     = 'published';
        $f->blocks     = '<!-- wp:dono/donation-amount /--><!-- wp:dono/email /--><!-- wp:dono/submit-button /-->';
        $f->created_at = gmdate('Y-m-d H:i:s');
        $f->updated_at = $f->created_at;
        $f->save();

        return $f;
    }

    // ------------------------------------------------------- the reported bug

    /**
     * The donor submits $250 by bank transfer, reloads the page and submits
     * again. The browser still holds the first reference and its status token
     * in sessionStorage, so the second POST carries them as a retry claim.
     */
    public function test_a_pending_offline_donation_is_never_adopted_as_a_retry_parent(): void
    {
        $form  = $this->publishedForm();
        $email = 'wire@example.test';
        $scope = ['form_id' => (int) $form->id];

        $parent = $this->claimOf($this->submit($email, null, $scope));
        $second = $this->claimOf($this->submit($email, $parent, $scope));

        $this->assertArrayNotHasKey(
            'retried_by',
            $this->flagsOf($parent['reference']),
            'an offline donation awaiting a transfer must not be marked replaced'
        );
        $this->assertSame(
            $second['reference'],
            $this->groupOf($second['reference']),
            'the refused claim opens its own attempt tree'
        );
        $this->assertSame(2, $this->emailCounter(), 'the second submission pays the ordinary email quota');
    }

    /**
     * The consequence the admin actually meets: the row has to be in the
     * pending queue the incoming transfer is reconciled against. Asserted on a
     * default browse, on the pending filter and on the count the KPI tiles
     * read, because all three share applyAdminFilters.
     */
    public function test_the_awaited_transfer_stays_in_the_admin_donation_reads(): void
    {
        $form  = $this->publishedForm();
        $email = 'reconcile@example.test';
        $scope = ['form_id' => (int) $form->id];

        $parent = $this->claimOf($this->submit($email, null, $scope));
        $second = $this->claimOf($this->submit($email, $parent, $scope));

        $this->assertContains($parent['reference'], $this->adminList(), 'default browse');
        $this->assertContains(
            $parent['reference'],
            $this->adminList(['status' => 'pending']),
            'the pending queue is where a bank transfer is matched'
        );
        $this->assertSame(2, $this->adminTotal(), 'both rows are counted');

        $this->assertContains($second['reference'], $this->adminList(), 'the later attempt is listed too');
    }

    // ------------------------------------------------------- the other side

    /**
     * The relief itself is intact. A parent on a gateway that had a checkout to
     * abandon still hands its tree on, so a donor who backs out of one card
     * flow and tries another is not charged the quota twice. Without this the
     * refusal above could be a blanket one and read the same.
     */
    public function test_a_parent_whose_checkout_could_be_abandoned_is_still_claimable(): void
    {
        $form  = $this->publishedForm();
        $email = 'switcher@example.test';
        $scope = ['form_id' => (int) $form->id];

        $parent = $this->claimOf($this->submit($email, null, $scope));
        $this->setGateway($parent['reference'], 'stripe');

        $second = $this->claimOf($this->submit($email, $parent, $scope));

        $this->assertSame(
            $parent['reference'],
            $this->groupOf($second['reference']),
            'the later attempt continues the parent tree'
        );
        $this->assertSame(1, $this->emailCounter(), 'the address is charged once for the tree');
    }
}
