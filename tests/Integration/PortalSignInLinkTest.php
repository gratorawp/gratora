<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Async\AsyncDispatcher;
use Dono\Donors\DonorService;
use Dono\Donors\Portal\PortalPage;
use Dono\Foundation\Plugin;

/**
 * The donor-portal sign-in link is emailed via an async job. Action Scheduler
 * runs do_action_ref_array($hook, array_values($args)), so the enqueued
 * ['donor_id'=>.., 'email'=>..] arrives as two positional params - the handler
 * must accept that, not just a single array. It previously no-oped under real
 * AS (the email never sent), so this drives the job exactly as AS would.
 */
final class PortalSignInLinkTest extends IntegrationTestCase
{
    public function test_send_link_async_job_emails_a_sign_in_link(): void
    {
        $sent = [];
        add_filter('pre_wp_mail', function ($null, $atts) use (&$sent) {
            $sent[] = ['to' => $atts['to'] ?? '', 'subject' => $atts['subject'] ?? '', 'body' => $atts['message'] ?? ''];
            return false;
        }, 10, 2);

        $donor = Plugin::instance()->container
            ->get(DonorService::class)
            ->findOrCreate('signin-' . uniqid() . '@example.test', ['first_name' => 'Sign', 'last_name' => 'In']);

        Plugin::instance()->container->get(AsyncDispatcher::class)->enqueue('dono.async.send_portal_link', [
            'donor_id' => (int) $donor->id,
            'email'    => 'signin-target@example.test',
        ]);

        // runPendingAsyncJobs() mirrors AS: do_action_ref_array($hook, array_values($args)).
        $this->runPendingAsyncJobs();

        $this->assertCount(1, $sent, 'the async job sends exactly one sign-in email');
        $this->assertStringContainsString('token=', (string) $sent[0]['body'], 'the email carries a magic-link token');
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
        add_filter('pre_wp_mail', function ($null, $atts) use (&$sent) {
            $sent[] = ['body' => $atts['message'] ?? ''];
            return false;
        }, 10, 2);

        $donor = Plugin::instance()->container
            ->get(DonorService::class)
            ->findOrCreate('lands-on-page-' . uniqid() . '@example.test');

        Plugin::instance()->container->get(AsyncDispatcher::class)->enqueue('dono.async.send_portal_link', [
            'donor_id' => (int) $donor->id,
            'email'    => 'recipient@example.test',
        ]);
        $this->runPendingAsyncJobs();

        $this->assertCount(1, $sent);
        $this->assertStringContainsString(
            $expectedBase,
            (string) $sent[0]['body'],
            'magic-link email points at the real portal page permalink, not a 404 slug-guess'
        );
    }
}
