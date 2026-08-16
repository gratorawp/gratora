<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Async\AsyncDispatcher;
use Dono\Donors\DonorService;
use Dono\Donors\Portal\PortalPage;
use Dono\Foundation\Plugin;

/**
 * The donor-portal sign-in link is emailed via an async job. Action Scheduler
 * runs do_action_ref_array($hook, array_values($args)), so an enqueued array
 * arrives spread into positional params, not as one array. It previously
 * no-oped under real AS (the email never sent), so this drives the job exactly
 * as AS would, in both shapes the controller enqueues.
 */
final class PortalSignInLinkTest extends IntegrationTestCase
{
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
            ->enqueue('dono.async.send_portal_link', ['email' => $email]);

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
            ->enqueue('dono.async.send_portal_link', [
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
            ->enqueue('dono.async.send_portal_link', [
                'email'      => 'known-' . $id . '@example.test',
                'first_name' => 'Ada',
                'last_name'  => 'Lovelace',
            ]);

        $this->runPendingAsyncJobs();

        $this->assertCount(1, $sent, 'the address in the first positional param is the one resolved');
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
            ->enqueue('dono.async.send_portal_link', ['email' => $email]);
        $this->runPendingAsyncJobs();

        $this->assertCount(1, $sent);
        $this->assertStringContainsString(
            $expectedBase,
            (string) $sent[0]['body'],
            'magic-link email points at the real portal page permalink, not a 404 slug-guess'
        );
    }
}
