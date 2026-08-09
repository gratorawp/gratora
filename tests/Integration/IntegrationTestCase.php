<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Vendor\Queryable\DB;
use ReflectionProperty;
use WP_UnitTestCase;
use wpdb;

/**
 * Base for tests that exercise the full WP + DB + plugin stack.
 *
 * Inherits from WP's `WP_UnitTestCase`, which wraps each test in a database
 * transaction that's rolled back on tearDown. That keeps the test DB empty
 * between tests without truncation - provided every write rides that one
 * transaction.
 *
 * Queryable's `DB::transaction()` runs raw `START TRANSACTION`/`COMMIT` on the
 * same `global $wpdb` handle WP_UnitTestCase uses. The first product
 * transaction in a test therefore implicitly commits WP's wrapping
 * transaction and then commits its own writes, so the tearDown ROLLBACK
 * discards nothing: dono_* rows AND WP transients (the AntiSpamGuard rate-limit
 * counters) leak across the whole suite. Pinning Queryable's nesting depth to
 * 1 for the duration of each test makes every `DB::transaction()` run as a
 * (real) nested call - it executes its callback but issues no START/COMMIT/
 * ROLLBACK - so all writes stay inside WP's transaction and roll back per test.
 *
 * Plugin migrations run once at bootstrap (`tests/integration-bootstrap.php`),
 * so the dono_* tables exist for every test.
 */
abstract class IntegrationTestCase extends WP_UnitTestCase
{
    protected static wpdb $wpdb;
    protected static string $prefix;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        global $wpdb;
        self::$wpdb   = $wpdb;
        self::$prefix = $wpdb->prefix;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->setQueryableTransactionDepth(1);
        wp_set_current_user(1);
        // A realistic multi-currency org: base USD, accepting USD/EUR/GBP. Keeps
        // Money::defaultCurrency() at the 'USD' fallback (no base shift) while
        // letting the suite's EUR/GBP donations pass the create-path
        // supported-currency gate. Tests needing a different set override this.
        update_option('dono_currency_locale', [
            'default_currency'     => 'USD',
            'supported_currencies' => ['USD', 'EUR', 'GBP'],
        ]);
        // A 1:1 FX snapshot so the suite's foreign (EUR/GBP) donations convert
        // to a base amount and are reportable - mirroring a configured org.
        // Without a rate, base_amount_cents stays NULL and the donation is
        // correctly excluded from base totals (tests assert face value).
        update_option('dono_fx_rates', [
            'base'       => 'USD',
            'date'       => gmdate('Y-m-d'),
            'fetched_at' => gmdate('c'),
            'rates'      => ['USD' => 1.0, 'EUR' => 1.0, 'GBP' => 1.0],
        ], false);
        $this->injectDonationFormToken();
    }

    protected function tearDown(): void
    {
        $this->setQueryableTransactionDepth(0);
        parent::tearDown();
    }

    /**
     * Dono\Vendor\Queryable\DB keeps a private static nesting counter. Forcing it to 1
     * before a test (and back to 0 after) makes product `DB::transaction()`
     * calls participate in WP_UnitTestCase's wrapping transaction instead of
     * committing through it. Harness-only; no product code is touched.
     */
    private function setQueryableTransactionDepth(int $depth): void
    {
        $prop = new ReflectionProperty(DB::class, 'transactionDepth');
        $prop->setAccessible(true);
        $prop->setValue(null, $depth);
    }

    /**
     * The public donation endpoint requires an HMAC-signed form token with a
     * minimum render age (AntiSpamGuard). JSON fixtures can't satisfy the
     * render-time gate without a real wait, so sign a backdated token at
     * dispatch. Test harness only; no product code is touched.
     */
    private function injectDonationFormToken(): void
    {
        add_filter('rest_pre_dispatch', function ($result, $server, $request) {
            if ($result !== null) return $result;
            if ($request->get_method() !== 'POST') return $result;
            if ($request->get_route() !== '/dono/v1/donations') return $result;

            $body = json_decode((string) $request->get_body(), true);
            if (is_array($body) && ! isset($body['_ft'])) {
                // Bind the token to the body's form id, matching production where
                // the form mints a token tied to its own id.
                $body['_ft'] = $this->validFormToken((int) ($body['form_id'] ?? 0));
                $request->set_body((string) wp_json_encode($body));
            }
            return $result;
        }, 10, 3);
    }

    private function validFormToken(int $formId = 0): string
    {
        return \Dono\Foundation\Plugin::instance()->container
            ->get(\Dono\Donations\AntiSpamGuard::class)
            ->mintFormToken($formId);
    }

    /**
     * Drain all pending dono.async.* jobs synchronously. Tests that exercise
     * the async pipeline call this after the action that enqueues work.
     */
    protected function runPendingAsyncJobs(int $maxIterations = 5): void
    {
        global $wpdb;
        $as = $wpdb->prefix . 'actionscheduler_actions';

        for ($i = 0; $i < $maxIterations; $i++) {
            $pending = $wpdb->get_results(
                "SELECT action_id, hook, args FROM {$as} WHERE hook LIKE 'dono.async.%' AND status = 'pending' ORDER BY action_id"
            );
            if (! $pending) return;
            foreach ($pending as $p) {
                // Mirror ActionScheduler_Action::execute() exactly: it calls
                // do_action_ref_array($hook, array_values($args)). Passing the
                // whole assoc array as one param (the old behaviour here) hid a
                // real bug where a multi-arg job no-oped under real AS.
                do_action_ref_array($p->hook, array_values((array) json_decode($p->args, true)));
                $wpdb->update($as, ['status' => 'complete'], ['action_id' => $p->action_id]);
            }
        }
    }

    /**
     * Capture wp_mail invocations. Returns an `ArrayObject` so the closure and
     * the caller share the same instance - assertions against the returned
     * object see the captured mails as they accumulate.
     *
     * Usage:
     *   $mails = $this->captureMails();
     *   ... do something that fires wp_mail ...
     *   $this->assertCount(1, $mails);
     *   $this->assertSame('subject', $mails[0]['subject']);
     */
    /**
     * Body of a streaming endpoint. rest_do_request never serves, so the
     * rest_pre_serve_request hook these routes write from does not fire and
     * get_data() is empty by design.
     *
     * @param array<string,mixed> $params
     */
    protected function serveBody(string $route, array $params = []): string
    {
        $request = new \WP_REST_Request('GET', $route);
        foreach ($params as $k => $v) {
            $request->set_param($k, $v);
        }

        $server = rest_get_server();
        $result = $server->dispatch($request);

        ob_start();
        apply_filters('rest_pre_serve_request', false, $result, $request, $server);

        return (string) ob_get_clean();
    }

    /**
     * A live portal session for the donor. Returns the session id; the caller
     * sets $_COOKIE['dono_donor_session'] to it.
     */
    protected function portalSession(int $donorId, string $csrf = 'tok', ?int $startedAt = null): string
    {
        $sid = bin2hex(random_bytes(32));
        set_transient('dono_portal_' . hash('sha256', $sid), [
            'donor_id' => $donorId,
            'csrf'     => $csrf,
            'started'  => $startedAt ?? time(),
            'seen'     => time(),
        ], HOUR_IN_SECONDS);

        return $sid;
    }

    protected function captureMails(): \ArrayObject
    {
        $mails = new \ArrayObject();
        add_filter('wp_mail', function ($args) use ($mails) {
            $mails[] = [
                'to'          => $args['to']          ?? null,
                'subject'     => $args['subject']     ?? null,
                'message'     => $args['message']     ?? null,
                'headers'     => $args['headers']     ?? null,
                'attachments' => $args['attachments'] ?? [],
            ];
            return $args;
        });
        return $mails;
    }
}
