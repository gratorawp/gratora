<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Analytics\ErrorLog;
use Gratora\Analytics\Event;
use WP_REST_Request;

/**
 * Clear log deletes diagnostics and refuses the audit families, which is right.
 * What it must also do is say so before the admin asks.
 *
 * The screen can filter to donation.deleted or donor.redacted, because those
 * rows are meant to be read here. Filtered to one, Clear log promised to delete
 * every entry under it, deleted nothing, and reported that as done.
 *
 * So the verdict travels with the list, from the same helper the delete refuses
 * with: what the screen shows and what the route does cannot come apart.
 */
final class ClearLogSaysWhatItWillNotClearTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    private function seed(string $type): Event
    {
        $e = Event::make();
        $e->type        = $type;
        $e->payload     = ['reference' => 'DON-2026-000001'];
        $e->occurred_at = gmdate('Y-m-d H:i:s');
        $e->save();

        return $e;
    }

    /** @return array<string,mixed> */
    private function list(string $source = ''): array
    {
        $req = new WP_REST_Request('GET', '/gratora/v1/admin/tools/log');
        if ($source !== '') {
            $req->set_param('source', $source);
        }

        $res = rest_do_request($req);
        $this->assertSame(200, $res->get_status(), (string) wp_json_encode($res->get_data()));

        return (array) $res->get_data();
    }

    private function clear(string $source = ''): \WP_REST_Response
    {
        $req = new WP_REST_Request('DELETE', '/gratora/v1/admin/tools/log');
        if ($source !== '') {
            $req->set_param('source', $source);
        }

        return rest_do_request($req);
    }

    private function exists(Event $e): bool
    {
        return Event::query()->find('id', (int) $e->id) !== null;
    }

    /**
     * @return array<array{0:string}>
     */
    public static function auditSources(): array
    {
        return [
            ['donation.trashed'],
            ['donation.deleted'],
            ['donor.deleted'],
            ['donor.redacted'],
        ];
    }

    /**
     * @dataProvider auditSources
     */
    public function test_the_list_says_an_audit_source_cannot_be_cleared(string $source): void
    {
        $this->seed($source);

        $body = $this->list($source);

        $this->assertFalse($body['clearable'], $source . ' is kept, and the screen has to know before it offers');
        $this->assertNotSame('', trim((string) $body['clear_blocked']), 'and a reason a person can read');
    }

    public function test_a_diagnostic_source_carries_no_refusal(): void
    {
        ErrorLog::record('gateway.intent', 'Something broke.');

        $body = $this->list('error.gateway.intent');

        $this->assertTrue($body['clearable']);
        $this->assertNull($body['clear_blocked']);
    }

    /** The unfiltered list clears the whole diagnostic log, so it is offered. */
    public function test_the_whole_log_carries_no_refusal(): void
    {
        ErrorLog::record('gateway.intent', 'Something broke.');
        $this->seed('donor.deleted');

        $body = $this->list();

        $this->assertTrue($body['clearable']);
        $this->assertNull($body['clear_blocked']);
    }

    /**
     * @dataProvider auditSources
     */
    public function test_clearing_an_audit_source_is_refused_rather_than_answered_with_nothing(string $source): void
    {
        $audit = $this->seed($source);

        $res  = $this->clear($source);
        $body = (array) $res->get_data();

        $this->assertSame(409, $res->get_status(), 'a 200 here reads on the screen as a job done');
        $this->assertSame('gratora_log_not_clearable', $body['code'] ?? '');
        $this->assertNotSame('', trim((string) ($body['message'] ?? '')), 'and the refusal says why');
        $this->assertTrue($this->exists($audit), 'the record of what was done still stands');
    }

    /** The sentence the screen was shown is the sentence the route refuses with. */
    public function test_the_reason_shown_is_the_reason_given(): void
    {
        $this->seed('donor.deleted');

        $shown = (string) $this->list('donor.deleted')['clear_blocked'];
        $given = (string) ((array) $this->clear('donor.deleted')->get_data())['message'];

        $this->assertSame($shown, $given);
    }

    public function test_a_diagnostic_source_still_clears(): void
    {
        ErrorLog::record('gateway.intent', 'Something broke.');
        $kept = $this->seed('donor.deleted');

        $res = $this->clear('error.gateway.intent');

        $this->assertSame(200, $res->get_status(), (string) wp_json_encode($res->get_data()));
        $this->assertSame(1, (int) ((array) $res->get_data())['deleted']);
        $this->assertSame(0, Event::query()->whereLike('type', ErrorLog::PREFIX . '%')->count());
        $this->assertTrue($this->exists($kept), 'and it reached only what was asked for');
    }

    public function test_the_whole_log_still_clears_the_diagnostics(): void
    {
        ErrorLog::record('gateway.intent', 'Something broke.');
        $kept = $this->seed('donor.redacted');

        $res = $this->clear();

        $this->assertSame(200, $res->get_status(), (string) wp_json_encode($res->get_data()));
        $this->assertSame(0, Event::query()->whereLike('type', ErrorLog::PREFIX . '%')->count());
        $this->assertTrue($this->exists($kept));
    }
}
