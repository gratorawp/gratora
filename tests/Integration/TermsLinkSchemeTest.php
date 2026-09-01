<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use WP_REST_Request;

/**
 * The terms block's link, on the page where a donor is typing card details.
 *
 * Block attributes never pass through kses - sanitizeBlocks only reaches
 * innerContent - so whatever was saved here travelled to the browser intact,
 * and the runtime is Preact, which does not refuse a javascript: href the way
 * React 19 does. The server-rendered fallback for this same block has always
 * used esc_url(); the hydrated path that replaces it did not, and that is the
 * one a donor clicks.
 *
 * fundkit_manage_forms is a granular capability an admin can hand to any role,
 * and it does not imply unfiltered_html, so this was reachable by someone who
 * was never trusted with script.
 */
final class TermsLinkSchemeTest extends IntegrationTestCase
{
    private int $campaignId;

    protected function setUp(): void
    {
        parent::setUp();

        $req = new WP_REST_Request('POST', '/fundkit/v1/admin/campaigns');
        $req->set_header('content-type', 'application/json');
        $req->set_body(json_encode(['title' => 'Terms campaign', 'status' => 'published']));
        $this->campaignId = (int) rest_do_request($req)->get_data()['id'];
    }

    private function configForLink(string $linkUrl): string
    {
        $blocks = '<!-- wp:fundkit/terms ' . json_encode([
            'terms'    => 'Please read these.',
            'linkUrl'  => $linkUrl,
            'linkText' => 'Read the terms',
        ]) . ' /-->';

        $req = new WP_REST_Request('POST', '/fundkit/v1/admin/forms');
        $req->set_header('content-type', 'application/json');
        $req->set_body(json_encode([
            'title'       => 'Terms form',
            'blocks'      => $blocks,
            'campaign_id' => $this->campaignId,
        ]));
        $created = rest_do_request($req)->get_data();

        $form = \FundKit\Forms\Form::query()->find('id', (int) $created['id']);
        $form->status = 'published';
        $form->save();

        $html = do_shortcode('[fundkit_donation_form slug="' . $created['slug'] . '"]');
        preg_match('/data-fundkit-form-config>(.+?)<\/script>/s', $html, $m);

        return (string) ($m[1] ?? '');
    }

    /** The linkUrl the runtime would actually bind to the href. */
    private function linkInConfig(string $linkUrl): string
    {
        $config = json_decode($this->configForLink($linkUrl), true);
        $this->assertIsArray($config, 'the form did not render, so this proves nothing');

        foreach ($config['steps'] ?? [] as $step) {
            foreach ($step['items'] ?? [] as $item) {
                if (($item['kind'] ?? '') === 'terms') {
                    return (string) ($item['linkUrl'] ?? '');
                }
            }
        }

        $this->fail('the terms block never reached the config');
    }

    /** Baseline, so the refusals below mean something. */
    public function test_an_ordinary_link_reaches_the_form(): void
    {
        $this->assertSame('https://example.org/terms', $this->linkInConfig('https://example.org/terms'));
    }

    /**
     * @dataProvider hostileLinks
     */
    public function test_a_script_url_never_reaches_the_browser(string $hostile, string $needle): void
    {
        $link = $this->linkInConfig($hostile);

        $this->assertStringNotContainsStringIgnoringCase($needle, $link);
        $this->assertStringNotContainsStringIgnoringCase('alert', $link);
    }

    /** @return array<string, array{0:string,1:string}> */
    public static function hostileLinks(): array
    {
        return [
            'javascript'        => ['javascript:alert(1)', 'javascript:'],
            'mixed case'        => ['JaVaScRiPt:alert(1)', 'alert(1)'],
            'leading space'     => ['  javascript:alert(1)', 'javascript:'],
            'embedded tab'      => ["java\tscript:alert(1)", 'alert(1)'],
            'data uri'          => ['data:text/html,<script>alert(1)</script>', 'data:text/html'],
            'vbscript'          => ['vbscript:msgbox(1)', 'vbscript:'],
        ];
    }
}
