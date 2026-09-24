<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Campaigns\Campaign;
use Gratora\Campaigns\Styling\CampaignStyleVars;
use Gratora\Receipts\Renderers\GenericReceiptRenderer;
use Gratora\Reports\CampaignReportBuilder;
use Gratora\Reports\RevenueReportBuilder;
use Gratora\Settings\SettingsService;
use Gratora\Foundation\Plugin;
use WP_REST_Request;

/**
 * A donor who gave on a branded page and then downloads the paperwork should
 * recognise it. The receipt took the org default whichever campaign the
 * donation was made to, and every other document took no colour at all.
 */
final class DocumentsCarryTheBrandTest extends IntegrationTestCase
{
    private function orgAccent(string $hex): void
    {
        Plugin::instance()->container->get(SettingsService::class)->update('org-brand', [
            'presets'    => [['id' => 'classic', 'name' => 'Classic', 'tokens' => ['gratora-accent' => $hex]]],
            'default_id' => 'classic',
        ]);
        CampaignStyleVars::flush();
    }

    private function campaign(string $accent): Campaign
    {
        $now = gmdate('Y-m-d H:i:s');
        $c = Campaign::make();
        $c->title      = 'Branded';
        $c->slug       = 'branded-' . uniqid();
        $c->status     = 'published';
        $c->currency   = 'USD';
        $c->style      = ['tokens' => ['gratora-accent' => $accent]];
        $c->created_at = $now;
        $c->updated_at = $now;
        $c->save();

        CampaignStyleVars::flush();

        return $c;
    }

    /** Dompdf returns bytes nothing can read back, so the markup is the seam. */
    private function markupOf(callable $build): string
    {
        $seen = '';
        $spy  = static function (string $html) use (&$seen): string {
            $seen = $html;

            return $html;
        };
        add_filter('gratora.pdf.html', $spy, 10);

        try {
            $build();
        } finally {
            remove_filter('gratora.pdf.html', $spy, 10);
        }

        return $seen;
    }

    public function test_the_campaign_report_takes_the_campaign_accent_not_the_org_default(): void
    {
        $this->orgAccent('#211d3f');
        $campaign = $this->campaign('#7c3aed');
        $builder  = Plugin::instance()->container->get(CampaignReportBuilder::class);

        $html = $this->markupOf(static fn () => $builder->build($campaign, 'all-time'));

        $this->assertStringContainsString('#7c3aed', $html);
        $this->assertStringNotContainsString('#211d3f', $html);
    }

    /** An org-wide document has no campaign behind it, so it takes the default. */
    public function test_an_org_document_falls_back_to_the_org_accent(): void
    {
        $this->orgAccent('#0f766e');
        $builder = Plugin::instance()->container->get(RevenueReportBuilder::class);

        $html = $this->markupOf(static fn () => $builder->build(2026));

        $this->assertStringContainsString('#0f766e', $html);
    }

    /**
     * The paper is white. A heading or a link drawn in a pale accent is 1.25:1
     * on it, so text takes dark ink there while a rule keeps the accent.
     */
    public function test_the_receipt_template_splits_text_ink_from_the_accent(): void
    {
        $this->orgAccent('#fde68a');

        $renderer = Plugin::instance()->container->get(GenericReceiptRenderer::class);
        $ref      = new \ReflectionMethod($renderer, 'loadTemplate');
        $ref->setAccessible(true);
        $template = (array) $ref->invoke($renderer);

        $this->assertSame('#10162a', $template['accent_ink']);
        $this->assertSame('#fde68a', $template['accent_color']);
    }

    public function test_the_receipt_heading_takes_ink_and_its_rule_the_accent(): void
    {
        $this->orgAccent('#fde68a');

        $html = $this->markupOf(fn () => $this->payAndReceipt());

        $this->assertMatchesRegularExpression('/h1\s*\{[^}]*color: #10162a;/', $html);
        $this->assertMatchesRegularExpression('/\.ref\s*\{[^}]*border-left: 3pt solid #fde68a;/', $html);
    }

    public function test_the_receipt_email_link_reads_on_white(): void
    {
        $this->orgAccent('#fde68a');
        $mails = $this->captureMails();

        $this->payAndReceipt();

        $this->assertStringContainsString('color:#10162a', $this->receiptMail($mails));
        $this->assertStringNotContainsString('#fde68a', $this->receiptMail($mails));
    }

    public function test_the_built_in_receipt_email_link_reads_on_white(): void
    {
        $this->orgAccent('#fde68a');
        $settings = Plugin::instance()->container->get(SettingsService::class);
        $email    = $settings->get('email');
        $email['templates']['donation_receipt']['body'] = '';
        $settings->update('email', $email);
        $mails = $this->captureMails();

        $this->payAndReceipt();

        $this->assertStringContainsString('Re-download your receipt', $this->receiptMail($mails));
        $this->assertStringContainsString('color:#10162a', $this->receiptMail($mails));
        $this->assertStringNotContainsString('#fde68a', $this->receiptMail($mails));
    }

    public function test_a_report_heading_takes_ink_and_its_rules_the_accent(): void
    {
        $this->orgAccent('#211d3f');
        $campaign = $this->campaign('#fde68a');
        $builder  = Plugin::instance()->container->get(CampaignReportBuilder::class);

        $html = $this->markupOf(static fn () => $builder->build($campaign, 'all-time'));

        $this->assertStringContainsString('h1{color:#10162a}', $html);
        $this->assertStringContainsString('border-top:2px solid #fde68a', $html);
    }

    public function test_an_org_report_heading_takes_ink_and_its_rule_the_accent(): void
    {
        $this->orgAccent('#fde68a');
        $builder = Plugin::instance()->container->get(RevenueReportBuilder::class);

        $html = $this->markupOf(static fn () => $builder->build(2026));

        $this->assertMatchesRegularExpression('/h1\{[^}]*color:#10162a\}/', $html);
        $this->assertStringContainsString('border-top:2px solid #fde68a', $html);
    }

    /** An accent that reads on white is still the heading's colour. */
    public function test_an_accent_that_reads_on_paper_keeps_the_heading(): void
    {
        $this->orgAccent('#211d3f');
        $campaign = $this->campaign('#46277c');
        $builder  = Plugin::instance()->container->get(CampaignReportBuilder::class);

        $html = $this->markupOf(static fn () => $builder->build($campaign, 'all-time'));

        $this->assertStringContainsString('h1{color:#46277c}', $html);
    }

    private function payAndReceipt(): void
    {
        $create = new WP_REST_Request('POST', '/gratora/v1/donations');
        $create->set_header('content-type', 'application/json');
        $create->set_body((string) wp_json_encode([
            'email'        => 'ink-' . uniqid() . '@example.test',
            'amount_cents' => 5000,
            'currency'     => 'USD',
            'gateway'      => 'offline',
            'profile'      => ['first_name' => 'Ida', 'last_name' => 'Kerr'],
        ]));
        $reference = (string) rest_do_request($create)->get_data()['reference'];

        $confirm = new WP_REST_Request('POST', "/gratora/v1/donations/{$reference}/confirm");
        $confirm->set_header('content-type', 'application/json');
        $confirm->set_body('{}');
        rest_do_request($confirm);

        $this->runPendingAsyncJobs();
    }

    private function receiptMail(\ArrayObject $mails): string
    {
        foreach ($mails as $mail) {
            if (! empty($mail['attachments'])) {
                return (string) $mail['message'];
            }
        }

        $this->fail('no receipt email was sent');
    }
}
