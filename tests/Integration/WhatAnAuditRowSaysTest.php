<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Analytics\Event;
use WP_REST_Request;

/**
 * The audit half of Tools > Logs said who and never what.
 *
 * Every row read "Recorded by Dana Whitfield." under a column headed What it
 * says, and the deed lived only in the Source cell, which is a database key.
 * A row with no actor read "No detail recorded." while the detail sat in the
 * same response.
 *
 * Core defined the gratora.audit.message filter so an add-on could phrase its
 * own types, and never phrased its own.
 */
final class WhatAnAuditRowSaysTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    /** @param array<string,mixed> $payload */
    private function messageFor(string $type, array $payload): string
    {
        $e = Event::make();
        $e->type        = $type;
        $e->payload     = $payload;
        $e->occurred_at = gmdate('Y-m-d H:i:s');
        $e->save();

        $req = new WP_REST_Request('GET', '/gratora/v1/admin/tools/log');
        $req->set_param('source', $type);
        $res = rest_do_request($req);

        $this->assertSame(200, $res->get_status(), (string) wp_json_encode($res->get_data()));

        foreach ((array) ((array) $res->get_data())['items'] as $item) {
            if ((int) $item['id'] === (int) $e->id) {
                return (string) $item['message'];
            }
        }

        $this->fail($type . ' is not in the log');
    }

    /**
     * Every core audit type, with a payload shaped like its emitter's, and one
     * word the sentence has to contain for it to be about the deed at all.
     *
     * @return array<string, array{0:string, 1:array<string,mixed>, 2:string}>
     */
    public static function coreAuditTypes(): array
    {
        return [
            'trashed'    => ['donation.trashed', ['reference' => 'DON-2026-000123', 'actor_name' => 'Dana'], 'trash'],
            'restored'   => ['donation.restored', ['reference' => 'DON-2026-000123', 'actor_name' => 'Dana'], 'restored'],
            'deleted'    => ['donation.deleted', ['reference' => 'DON-2026-000123', 'actor_name' => 'Dana'], 'deleted'],
            'settled'    => ['donation.untrashed_by_settlement', ['reference' => 'DON-2026-000123'], 'settled'],
            'donor gone' => ['donor.deleted', ['actor_name' => 'Dana', 'was_redacted' => false, 'donations_deleted' => 19, 'plans_stopped' => 0], 'deleted'],
            'erased'     => ['donor.redacted', ['actor_name' => 'Dana', 'donations_retained' => 3], 'erased'],
            'link'       => ['donor.portal_link_issued', ['actor_name' => 'Dana', 'expires_at' => '2026-10-05 21:43:19'], 'link'],
            'orphans'    => ['donor.orphans_cleared', ['removed' => ['p2p_rows' => 1, 'p2p_layout_pages' => 1]], 'cleared'],
        ];
    }

    /**
     * @param array<string,mixed> $payload
     *
     * @dataProvider coreAuditTypes
     */
    public function test_a_core_audit_row_says_what_was_done(string $type, array $payload, string $word): void
    {
        $message = $this->messageFor($type, $payload);

        $this->assertNotSame('No detail recorded.', $message, $type . ' carries its detail in the same response');
        $this->assertStringContainsString(
            $word,
            strtolower($message),
            $type . ': the sentence has to be about the act, not only about who performed it'
        );
    }

    /**
     * @param array<string,mixed> $payload
     *
     * @dataProvider coreAuditTypes
     */
    public function test_the_type_key_is_never_the_only_thing_carrying_the_deed(string $type, array $payload): void
    {
        $message = $this->messageFor($type, $payload);

        // The whole defect in one assertion: naming the actor is not saying
        // what happened, and it was every core row's entire message.
        $this->assertFalse(
            (bool) preg_match('/^Recorded by [^.]+\.$/', $message),
            $type . ' read as "Recorded by <name>." and nothing else'
        );
    }

    /** Who did it is worth keeping. It just is not the whole sentence. */
    public function test_the_actor_is_still_named_when_one_is_recorded(): void
    {
        $message = $this->messageFor('donation.trashed', [
            'reference'  => 'DON-2026-000123',
            'actor_name' => 'Dana Whitfield',
        ]);

        $this->assertStringContainsString('DON-2026-000123', $message);
        $this->assertStringContainsString('Dana Whitfield', $message);
    }

    /**
     * Settlement is something the gateway did. Naming a staff member for it
     * would be worse than saying nothing.
     */
    public function test_an_act_with_no_actor_says_what_happened_and_names_nobody(): void
    {
        $message = $this->messageFor('donation.untrashed_by_settlement', [
            'reference'  => 'DON-2026-000123',
            'trashed_by' => 1,
        ]);

        $this->assertStringContainsString('DON-2026-000123', $message);
        $this->assertStringNotContainsString('Recorded by', $message);
    }

    /** The counts are the point of the row, so they reach the sentence. */
    public function test_a_donor_delete_says_what_went_with_the_donor(): void
    {
        $message = $this->messageFor('donor.deleted', [
            'actor_name'        => 'Dana',
            'was_redacted'      => false,
            'donations_deleted' => 19,
            'plans_stopped'     => 2,
        ]);

        $this->assertStringContainsString('19', $message);
        $this->assertStringContainsString('2', $message);
    }

    /** Each count pluralises on its own, or one of them reads "1 subscriptions". */
    public function test_one_of_each_reads_as_one_of_each(): void
    {
        $message = $this->messageFor('donor.deleted', [
            'donations_deleted' => 20,
            'plans_stopped'     => 1,
        ]);

        $this->assertStringContainsString('20 donations', $message);
        $this->assertStringContainsString('1 subscription', $message);
        $this->assertStringNotContainsString('1 subscriptions', $message);
    }

    /** A type core has no sentence for keeps the behaviour it had. */
    public function test_a_type_core_does_not_know_still_falls_back(): void
    {
        $this->assertSame('No detail recorded.', $this->messageFor('donor.undescribed', []));
    }

    /** And an add-on can still say what its own type means. */
    public function test_an_add_on_still_overrides_the_sentence(): void
    {
        $describe = static fn ($message, string $type, array $payload) => $type === 'donor.thing_destroyed'
            ? sprintf('Destroyed %d things.', (int) ($payload['count'] ?? 0))
            : $message;

        add_filter('gratora.audit.message', $describe, 10, 3);

        try {
            $message = $this->messageFor('donor.thing_destroyed', ['count' => 3]);
        } finally {
            remove_filter('gratora.audit.message', $describe, 10);
        }

        $this->assertSame('Destroyed 3 things.', $message);
    }
}
