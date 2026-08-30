<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Campaigns\CampaignRepository;
use FundKit\Campaigns\Styling\CampaignStyleResolver;
use FundKit\Forms\FormRepository;
use FundKit\Forms\Shortcode\DonationFormShortcode;
use FundKit\Foundation\Plugin;
use FundKit\Gateways\GatewayManager;

/**
 * The editor preview is an iframe srcdoc, so it has no wp_scripts queue: every
 * script it needs has to be written into the document by hand.
 *
 * That loop emitted the runtime's DECLARED dependencies only. The runtime
 * declares wp-i18n, wp-i18n depends on wp-hooks, and @wordpress/i18n reads
 * wp.hooks at module scope, so i18n threw, wp.i18n was never defined, the
 * runtime threw on top of it and the preview never hydrated. What was left on
 * screen was the server-rendered fallback markup, which carries almost none of
 * the classes runtime.css is scoped to, so the form looked completely unstyled
 * while the stylesheet was loading perfectly well.
 */
final class FormPreviewDocumentTest extends IntegrationTestCase
{
    private function shortcode(): DonationFormShortcode
    {
        $c = Plugin::instance()->container;

        return new DonationFormShortcode(
            $c->get(FormRepository::class),
            $c->get(CampaignStyleResolver::class),
            $c->get(CampaignRepository::class),
            null,
            $c->get(GatewayManager::class)
        );
    }

    private function document(): string
    {
        $blocks = '<!-- wp:fundkit/donation-amount /--><!-- wp:fundkit/name /-->'
            . '<!-- wp:fundkit/email /--><!-- wp:fundkit/submit-button /-->';

        $shortcode = $this->shortcode();

        return $shortcode->buildPreviewDocument($shortcode->renderPreview($blocks));
    }

    /** @return list<string> script basenames, in document order */
    private function scriptsIn(string $doc): array
    {
        preg_match_all('/<script src="([^"]+)"/', $doc, $m);

        return array_map(
            static fn (string $u): string => basename((string) parse_url($u, PHP_URL_PATH)),
            $m[1]
        );
    }

    public function test_a_dependency_of_a_dependency_is_in_the_document(): void
    {
        $scripts = $this->scriptsIn($this->document());

        $this->assertContains('hooks.min.js', $scripts, 'wp-i18n reads wp.hooks at module scope');
        $this->assertContains('i18n.min.js', $scripts);
    }

    /** A dependency that arrives after its dependent has already thrown. */
    public function test_dependencies_come_before_the_scripts_that_need_them(): void
    {
        $scripts = $this->scriptsIn($this->document());

        $this->assertLessThan(
            array_search('i18n.min.js', $scripts, true),
            array_search('hooks.min.js', $scripts, true),
            'hooks must be evaluated before i18n'
        );
        $this->assertSame(
            'index.js',
            end($scripts),
            'and the runtime itself goes last, after everything it needs'
        );
    }

    /** The stylesheet is only useful once the runtime has rendered the classes. */
    public function test_the_document_links_the_donor_facing_stylesheet(): void
    {
        $this->assertStringContainsString(
            'build/donation-form/runtime.css',
            $this->document(),
            'the iframe cannot inherit the admin page styles'
        );
    }
}
