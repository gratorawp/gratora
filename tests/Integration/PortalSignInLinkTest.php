<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Async\AsyncDispatcher;
use Gratora\Donors\DonorService;
use Gratora\Donors\Portal\PortalPage;
use Gratora\Foundation\Plugin;

/**
 * Invoke the email job with positional arguments, matching Action Scheduler’s array_values
 * dispatch.
 */
final class PortalSignInLinkTest extends IntegrationTestCase
{
    /** Proof the caller loaded the portal page, which send-link is gated on. */
    private function portalToken(): string
    {
        return Plugin::instance()->container
            ->get(\Gratora\Donations\AntiSpamGuard::class)
            ->mintPortalToken();
    }

    private function requestLink(string $email): void
    {
        $req = new \WP_REST_Request('POST', '/gratora/v1/portal/send-link');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode(['email' => $email, 'token' => $this->portalToken()]));

        $res = rest_do_request($req);
        $this->assertSame(200, $res->get_status(), (string) wp_json_encode($res->get_data()));
    }

    /** @param array<int,array<string,string>> $sent */
    private function captureMail(array &$sent): void
    {
        add_filter('pre_wp_mail', function ($null, $atts) use (&$sent) {
            $sent[] = ['to' => $atts['to'] ?? '', 'subject' => $atts['subject'] ?? '', 'body' => $atts['message'] ?? ''];
            return false;
        }, 10, 2);
    }

    public function test_send_link_async_job_emails_a_sign_in_link(): void
    {
        $sent = [];
        $this->captureMail($sent);

        $email = 'signin-' . uniqid() . '@example.test';
        Plugin::instance()->container
            ->get(DonorService::class)
            ->findOrCreate($email, ['first_name' => 'Sign', 'last_name' => 'In']);

        // The shape /portal/send-link enqueues: one named value, which AS
        // spreads into a single positional string.
        Plugin::instance()->container->get(AsyncDispatcher::class)
            ->enqueue('gratora.async.send_portal_link', ['email' => $email]);

        // runPendingAsyncJobs() mirrors AS: do_action_ref_array($hook, array_values($args)).
        $this->runPendingAsyncJobs();

        $this->assertCount(1, $sent, 'the async job sends exactly one sign-in email');
        $this->assertStringContainsString('token=', (string) $sent[0]['body'], 'the email carries a magic-link token');
    }

    /**
     * The register shape carries the name that registration typed, so it is
     * three positional params rather than one. A handler that only understood
     * the sign-in shape would drop every signup mail on the floor.
     */
    public function test_the_registration_shape_reaches_the_job_intact(): void
    {
        $sent = [];
        $this->captureMail($sent);

        Plugin::instance()->container->get(AsyncDispatcher::class)
            ->enqueue('gratora.async.send_portal_link', [
                'email'      => 'newcomer-' . uniqid() . '@example.test',
                'first_name' => 'Ada',
                'last_name'  => 'Lovelace',
            ]);

        $this->runPendingAsyncJobs();

        // No claim was ever recorded for this address, so there is nothing for
        // the link to point at and nothing is sent. What is under test is that
        // the handler read the email out of the first positional param rather
        // than mistaking the whole job for one.
        $this->assertCount(0, $sent);

        Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate('known-' . ($id = uniqid()) . '@example.test');

        Plugin::instance()->container->get(AsyncDispatcher::class)
            ->enqueue('gratora.async.send_portal_link', [
                'email'      => 'known-' . $id . '@example.test',
                'first_name' => 'Ada',
                'last_name'  => 'Lovelace',
            ]);

        $this->runPendingAsyncJobs();

        $this->assertCount(1, $sent, 'the address in the first positional param is the one resolved');
        $this->assertStringContainsString('token=', (string) $sent[0]['body']);
    }

    /**
     * Action Scheduler keeps its args in a table nothing erases, for a month
     * after the job has run, so a readable address there outlives the erasure
     * that was supposed to remove it.
     */
    public function test_the_queued_job_does_not_carry_the_address_in_the_clear(): void
    {
        global $wpdb;

        $email = 'sealed-' . uniqid() . '@example.test';

        $this->requestLink($email);

        $args = $wpdb->get_col(
            "SELECT COALESCE(extended_args, args) FROM {$wpdb->prefix}actionscheduler_actions
             WHERE hook = 'gratora.async.send_portal_link'"
        );

        $this->assertNotSame([], $args, 'the job was queued');
        foreach ($args as $row) {
            $this->assertStringNotContainsString($email, (string) $row);
        }
    }

    public function test_a_sealed_job_still_sends_the_link(): void
    {
        $sent = [];
        $this->captureMail($sent);

        $email = 'sealed-send-' . uniqid() . '@example.test';
        Plugin::instance()->container->get(DonorService::class)->findOrCreate($email);

        $this->requestLink($email);

        $this->runPendingAsyncJobs();

        $this->assertCount(1, $sent);
        $this->assertStringContainsString('token=', (string) $sent[0]['body']);
    }

    public function test_sign_in_email_links_to_the_actual_donor_portal_page(): void
    {
        // Ensure the portal page exists (Plugin::onActivation() does this in
        // production; tests exercise the URL resolver directly).
        $pageId = (new PortalPage())->ensure();
        $this->assertGreaterThan(0, $pageId);
        $expectedBase = (string) get_permalink($pageId);
        $this->assertNotSame('', $expectedBase, 'portal page resolves to a real permalink');

        $sent = [];
        $this->captureMail($sent);

        $email = 'lands-on-page-' . uniqid() . '@example.test';
        Plugin::instance()->container->get(DonorService::class)->findOrCreate($email);

        Plugin::instance()->container->get(AsyncDispatcher::class)
            ->enqueue('gratora.async.send_portal_link', ['email' => $email]);
        $this->runPendingAsyncJobs();

        $this->assertCount(1, $sent);
        $this->assertStringContainsString(
            $expectedBase,
            (string) $sent[0]['body'],
            'magic-link email points at the real portal page permalink, not a 404 slug-guess'
        );
    }
}
