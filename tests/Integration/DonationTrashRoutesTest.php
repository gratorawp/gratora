<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Donations\Donation;
use Gratora\Donations\DonationIntent;
use Gratora\Donations\DonationService;
use Gratora\Foundation\Auth\Capabilities;
use Gratora\Foundation\Plugin;
use WP_REST_Request;

/**
 * The three collection routes, and who may reach them.
 *
 * Run against a scoped role rather than an administrator on purpose: userCan
 * accepts manage_options first, so an administrator passes every one of these
 * checks and the capability assertions would prove nothing at all.
 */
final class DonationTrashRoutesTest extends IntegrationTestCase
{
    private const VIEW   = 'gratora_view_donations';
    private const CHARGE = 'gratora_refund_donations';
    private const DELETE = 'gratora_delete_donations';

    protected function setUp(): void
    {
        parent::setUp();
        delete_option('gratora_roles');
        Capabilities::applyMapping([]);
    }

    protected function tearDown(): void
    {
        Capabilities::applyMapping([]);
        parent::tearDown();
    }

    /** @param list<string> $caps */
    private function asRoleWith(array $caps): void
    {
        Capabilities::applyMapping(['editor' => $caps]);
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
    }

    /** @param array<string,mixed> $columns */
    private function attempt(array $columns = []): Donation
    {
        $donation = Plugin::instance()->container->get(DonationService::class)->createPending(new DonationIntent(
            email:        'routes-' . uniqid() . '@example.test',
            amount_cents: 2500,
            currency:     'USD',
            gateway:      'offline',
            frequency:    'one_time',
        ))['donation'];

        if ($columns !== []) {
            $donation->updateColumns($columns);
        }

        return $donation;
    }

    /** @param array<string,mixed> $body */
    private function post(string $route, array $body): \WP_REST_Response
    {
        $req = new WP_REST_Request('POST', '/gratora/v1/admin/donations/' . $route);
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode($body));

        return rest_do_request($req);
    }

    private function exists(Donation $donation): bool
    {
        return Donation::query()->where('id', (int) $donation->id)->get() !== null;
    }

    private function fresh(Donation $donation): ?Donation
    {
        return Donation::query()->where('id', (int) $donation->id)->get();
    }

    public function test_a_notes_only_role_cannot_stop_a_payment(): void
    {
        $donation = $this->attempt();
        $this->asRoleWith([self::VIEW, 'gratora_edit_donations']);

        $res = $this->post('trash', ['references' => [(string) $donation->reference]]);

        $this->assertSame(403, $res->get_status(), 'editing notes is not permission to stop a charge');
        $this->assertNull($this->fresh($donation)->trashed_at);
    }

    public function test_the_charge_capability_trashes_and_restores_but_cannot_delete(): void
    {
        $donation = $this->attempt();
        $this->asRoleWith([self::VIEW, self::CHARGE]);

        $trash = $this->post('trash', ['references' => [(string) $donation->reference]]);
        $this->assertSame(200, $trash->get_status());
        $this->assertCount(1, (array) $trash->get_data()['done']);
        $this->assertNotNull($this->fresh($donation)->trashed_at);

        $restore = $this->post('restore', ['references' => [(string) $donation->reference]]);
        $this->assertSame(200, $restore->get_status());
        $this->assertNull($this->fresh($donation)->trashed_at);

        $delete = $this->post('delete', [
            'references'   => [(string) $donation->reference],
            'confirmation' => 'DELETE',
        ]);
        $this->assertSame(403, $delete->get_status(), 'tidying a list is not permission to destroy a record');
        $this->assertTrue($this->exists($donation));
    }

    /** The capability is only real once a role that holds it can do the thing. */
    public function test_a_role_granted_the_delete_capability_can_empty_the_trash(): void
    {
        $donation = $this->attempt();
        $this->asRoleWith([self::VIEW, self::CHARGE, self::DELETE]);

        $this->post('trash', ['references' => [(string) $donation->reference]]);

        $res = $this->post('delete', [
            'references'   => [(string) $donation->reference],
            'confirmation' => 'delete',
        ]);

        $this->assertSame(200, $res->get_status(), (string) wp_json_encode($res->get_data()));
        $this->assertCount(1, (array) $res->get_data()['done']);
        $this->assertFalse($this->exists($donation));
    }

    public function test_the_confirmation_word_is_required_and_nothing_goes_without_it(): void
    {
        $donation = $this->attempt();
        $this->asRoleWith([self::VIEW, self::CHARGE, self::DELETE]);
        $this->post('trash', ['references' => [(string) $donation->reference]]);

        $res = $this->post('delete', [
            'references'   => [(string) $donation->reference],
            'confirmation' => 'yes',
        ]);

        $this->assertSame(400, $res->get_status());
        $this->assertTrue($this->exists($donation), 'a mistyped confirmation removes nothing');
    }

    /**
     * A mixed selection is the normal case: an admin sweeps a page and some of
     * it is not theirs to sweep.
     */
    public function test_a_mixed_batch_answers_per_row_and_leaves_refusals_alone(): void
    {
        $spam     = $this->attempt();
        // Still refused: the outcome is unknown and the money may yet arrive.
        $settling = $this->attempt(['status' => 'processing']);

        $this->asRoleWith([self::VIEW, self::CHARGE]);

        $res  = $this->post('trash', ['references' => [(string) $spam->reference, (string) $settling->reference]]);
        $data = (array) $res->get_data();

        $this->assertSame(200, $res->get_status());
        $this->assertSame([(string) $spam->reference], array_column((array) $data['done'], 'reference'));

        $refused = (array) $data['refused'];
        $this->assertCount(1, $refused);
        $this->assertSame((string) $settling->reference, $refused[0]['reference']);
        $this->assertNotSame('', (string) $refused[0]['reason'], 'a refusal says why');

        $this->assertNull($this->fresh($settling)->trashed_at, 'and the row it refused is untouched');
    }

    public function test_an_unbounded_selection_is_rejected(): void
    {
        $this->asRoleWith([self::VIEW, self::CHARGE]);

        $res = $this->post('trash', ['references' => array_fill(0, 51, 'GRA-NOPE')]);

        $this->assertSame(400, $res->get_status(), 'a request that runs until it times out half-done is not offered');
    }

    /**
     * The contract the screens rest on: a button offered where the server
     * refuses is worse than no button.
     */
    public function test_the_row_flags_agree_with_what_the_call_does(): void
    {
        $spam     = $this->attempt();
        $settling = $this->attempt(['status' => 'processing']);

        $this->asRoleWith([self::VIEW, self::CHARGE]);

        $rows = [];
        foreach ((array) rest_do_request(new WP_REST_Request('GET', '/gratora/v1/admin/donations'))->get_data() as $row) {
            $rows[(string) $row['reference']] = $row;
        }

        $this->assertTrue((bool) $rows[(string) $spam->reference]['trashable']);
        $this->assertFalse((bool) $rows[(string) $settling->reference]['trashable']);
        $this->assertNotNull($rows[(string) $settling->reference]['untrashable_reason']);

        // Neither is deletable yet: permanent delete is offered only from the
        // Trash view, so nothing on the live list carries it.
        $this->assertFalse((bool) $rows[(string) $spam->reference]['deletable']);

        $data = (array) $this->post('trash', [
            'references' => [(string) $spam->reference, (string) $settling->reference],
        ])->get_data();

        $this->assertSame([(string) $spam->reference], array_column((array) $data['done'], 'reference'));
        $this->assertSame([(string) $settling->reference], array_column((array) $data['refused'], 'reference'));
    }

    public function test_the_trash_is_its_own_list_and_carries_its_own_count(): void
    {
        $donation = $this->attempt();
        $this->asRoleWith([self::VIEW, self::CHARGE]);
        $this->post('trash', ['references' => [(string) $donation->reference]]);

        $live = rest_do_request(new WP_REST_Request('GET', '/gratora/v1/admin/donations'));
        $this->assertNotContains(
            (string) $donation->reference,
            array_column((array) $live->get_data(), 'reference'),
            'the working list does not show the bin'
        );
        $this->assertSame('1', (string) $live->get_headers()['X-Gratora-Trashed']);

        $binRequest = new WP_REST_Request('GET', '/gratora/v1/admin/donations');
        $binRequest->set_param('trashed', 'only');

        $this->assertSame(
            [(string) $donation->reference],
            array_column((array) rest_do_request($binRequest)->get_data(), 'reference')
        );
    }
}
