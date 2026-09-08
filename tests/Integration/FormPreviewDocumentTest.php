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
 * srcdoc has no script queue; include transitive dependencies and initialize the sandboxed
 * document explicitly.
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

    /**
     * The frame is sandboxed without allow-same-origin, so the document has an
     * opaque origin and the runtime's frame guard cannot tell it from a site
     * embedding the real form. Nothing but a document this server built can
     * carry the flag, because a framing site cannot script into it.
     */
    public function test_the_preview_document_says_that_it_is_one(): void
    {
        $this->assertStringContainsString('window.fundkitFormPreview = true', $this->document());
    }

    public function test_a_real_donation_page_carries_no_such_flag(): void
    {
        $blocks = '<!-- wp:fundkit/donation-amount /--><!-- wp:fundkit/submit-button /-->';

        $this->assertStringNotContainsString(
            'fundkitFormPreview',
            (string) $this->shortcode()->renderPreview($blocks)['html']
        );
    }
}
