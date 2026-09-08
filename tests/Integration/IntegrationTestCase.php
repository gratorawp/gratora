<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Foundation\Plugin;
use FundKit\Gateways\GatewayManager;
use FundKit\Vendor\Queryable\DB;
use ReflectionProperty;
use WP_UnitTestCase;
use wpdb;

/**
 * Pin Queryable transaction depth to 1 so product transactions use savepoints inside
 * WordPress’s per-test transaction. Otherwise START TRANSACTION commits the wrapper and leaks
 * rows and transients between tests. Migrations run once at bootstrap.
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
        update_option('fundkit_currency_locale', [
            'default_currency'     => 'USD',
            'supported_currencies' => ['USD', 'EUR', 'GBP'],
        ]);
        // A 1:1 FX snapshot so the suite's foreign (EUR/GBP) donations convert
        // to a base amount and are reportable - mirroring a configured org.
        // Without a rate, base_amount_cents stays NULL and the donation is
        // correctly excluded from base totals (tests assert face value).
        update_option('fundkit_fx_rates', [
            'base'       => 'USD',
            'date'       => gmdate('Y-m-d'),
            'fetched_at' => gmdate('c'),
            'rates'      => ['USD' => 1.0, 'EUR' => 1.0, 'GBP' => 1.0],
        ], false);
        $this->makeOfflinePayable();
        $this->injectDonationFormToken();
    }

    protected function tearDown(): void
    {
        $this->restoreGateways();
        $this->setQueryableTransactionDepth(0);
        parent::tearDown();
    }

    /**
     * Offline is the suite's workhorse gateway and it settles by the donor
     * following written instructions, so an org that can take money by it has
     * written some. The written config carries no `test_mode`, which is what a
     * test clearing this option is usually after.
     *
     * No `enabled` key: that flag defaults to on and the gateway tests assert
     * exactly that.
     */
    protected function makeOfflinePayable(): void
    {
        update_option('fundkit_gateway_config', [
            'offline' => ['instructions' => 'Transfer the amount quoting your reference.'],
        ]);
    }

    /** @var array<string,object>|null The registry as it stood before a test took a gateway out. */
    private ?array $gatewaysBefore = null;

    /**
     * Take a gateway out of the registry for one test.
     *
     * The registry is process-wide and the container memoises it, so a test
     * that unregisters and walks away leaves every later test in the process
     * running against a site missing a payment method. Restored in tearDown
     * rather than left to the next suite's setUp to register its own.
     */
    protected function deregisterGateway(string $id): void
    {
        $manager = Plugin::instance()->container->get(GatewayManager::class);
        $prop    = new ReflectionProperty($manager, 'gateways');
        $prop->setAccessible(true);

        $all = (array) $prop->getValue($manager);
        $this->gatewaysBefore ??= $all;
        unset($all[$id]);
        $prop->setValue($manager, $all);
    }

    private function restoreGateways(): void
    {
        if ($this->gatewaysBefore === null) {
            return;
        }

        $manager = Plugin::instance()->container->get(GatewayManager::class);
        $prop    = new ReflectionProperty($manager, 'gateways');
        $prop->setAccessible(true);
        $prop->setValue($manager, $this->gatewaysBefore);

        $this->gatewaysBefore = null;
    }

    /**
     * FundKit\Vendor\Queryable\DB keeps a private static nesting counter. Forcing it to 1
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
            if ($request->get_route() !== '/fundkit/v1/donations') return $result;

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
        return \FundKit\Foundation\Plugin::instance()->container
            ->get(\FundKit\Donations\AntiSpamGuard::class)
            ->mintFormToken($formId);
    }

    /**
     * Drain all pending fundkit.async.* jobs synchronously. Tests that exercise
     * the async pipeline call this after the action that enqueues work.
     */
    protected function runPendingAsyncJobs(int $maxIterations = 5): void
    {
        global $wpdb;
        $as = $wpdb->prefix . 'actionscheduler_actions';

        for ($i = 0; $i < $maxIterations; $i++) {
            // Past 191 characters ActionScheduler_DBStore keeps an md5 of the
            // payload in args and the payload itself in extended_args. Reading
            // args alone decodes that hash to null and runs the handler on its
            // defaults, so a job with a long argument passes while doing
            // nothing. Any fixture with a realistic name crosses that line.
            $pending = $wpdb->get_results(
                "SELECT action_id, hook, COALESCE(extended_args, args) AS args FROM {$as}
                 WHERE hook LIKE 'fundkit.async.%' AND status = 'pending' ORDER BY action_id"
            );
            if (! $pending) return;
            foreach ($pending as $p) {
                // Match Action Scheduler’s positional dispatch via array_values($args).
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
     * sets $_COOKIE['fundkit_donor_session'] to it.
     */
    protected function portalSession(int $donorId, string $csrf = 'tok', ?int $startedAt = null): string
    {
        $sid = bin2hex(random_bytes(32));
        set_transient('fundkit_portal_' . hash('sha256', $sid), [
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
    /**
     * Give a donation a status token this test knows, and hand back the raw one.
     *
     * The gateway confirm routes take money, so they check the per-donation
     * secret the submit response gave the browser. Only its hash is stored, so a
     * test cannot read the token back off the row: it stamps one it chose.
     *
     * @since 1.0.0
     */
    protected function stampStatusToken(string $reference, string $token = 'browser-held-token'): string
    {
        $repo     = \FundKit\Foundation\Plugin::instance()->container->get(\FundKit\Donations\DonationRepository::class);
        $donation = $repo->findByReference($reference);

        $this->assertNotNull($donation, "no donation for {$reference}");

        $donation->status_token_hash = hash('sha256', $token);
        $donation->save();

        return $token;
    }
}
