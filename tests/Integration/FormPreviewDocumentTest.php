<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Campaigns\CampaignRepository;
use Gratora\Campaigns\Styling\CampaignStyleResolver;
use Gratora\Forms\FormRepository;
use Gratora\Forms\Shortcode\DonationFormShortcode;
use Gratora\Foundation\Plugin;
use Gratora\Gateways\GatewayManager;
use WP_HTML_Tag_Processor;

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

    private function document(bool $autoResize = false, bool $transparent = false): string
    {
        $blocks = '<!-- wp:gratora/donation-amount /--><!-- wp:gratora/name /-->'
            . '<!-- wp:gratora/email /--><!-- wp:gratora/submit-button /-->';

        $shortcode = $this->shortcode();

        return $shortcode->buildPreviewDocument($shortcode->renderPreview($blocks), $autoResize, $transparent);
    }

    /** @return list<string> script basenames, in document order */
    private function scriptsIn(string $doc): array
    {
        preg_match_all('/<script\b[^>]*\bsrc="([^"]+)"/', $doc, $m);

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
        $this->assertStringContainsString('window.gratoraFormPreview = true', $this->document());
    }

    /**
     * A security plugin adds its CSP nonce through core's tag filters, so a
     * tag written by hand is one the page's policy refuses to run.
     */
    public function test_every_tag_in_the_document_is_printed_by_core(): void
    {
        $nonce = static fn (array $attributes): array => $attributes + ['nonce' => 'probe-nonce'];
        add_filter('wp_script_attributes', $nonce);
        add_filter('wp_inline_script_attributes', $nonce);

        $processor = new WP_HTML_Tag_Processor($this->document(autoResize: true));
        $seen      = [];

        while ($processor->next_tag()) {
            $tag = (string) $processor->get_tag();
            $id  = (string) $processor->get_attribute('id');

            if ($tag === 'SCRIPT') {
                $this->assertSame('probe-nonce', $processor->get_attribute('nonce'), 'a script core did not print: ' . $id);
            } elseif ($tag === 'STYLE') {
                $this->assertStringEndsWith('-inline-css', $id, 'a style block core did not print');
            } elseif ($tag === 'LINK') {
                $this->assertStringEndsWith('-css', $id, 'a stylesheet core did not print');
            } else {
                continue;
            }

            $seen[] = $tag;
        }

        $this->assertContains('SCRIPT', $seen);
        $this->assertContains('STYLE', $seen);
        $this->assertContains('LINK', $seen);
    }

    /** A queue marks what it prints as done, and the next document would go without. */
    public function test_a_second_document_in_the_same_request_is_as_whole_as_the_first(): void
    {
        $first  = $this->document();
        $second = $this->document();

        $this->assertSame($this->scriptsIn($first), $this->scriptsIn($second));
        $this->assertStringContainsString('build/donation-form/runtime.css', $second);
        $this->assertStringContainsString('window.gratoraFormPreview = true', $second);
    }

    /**
     * What the document's style blocks give $property on its html or body, by
     * specificity and then source order. Understands only html with classes,
     * and body with or without that ancestor.
     */
    private function styled(string $doc, string $element, string $property): ?string
    {
        $processor = new WP_HTML_Tag_Processor($doc);
        $processor->next_tag('HTML');
        $rootClasses = iterator_to_array($processor->class_list(), false);

        $css = '';
        while ($processor->next_tag('STYLE')) {
            $css .= $processor->get_modifiable_text();
        }

        $pattern = $element === 'body'
            ? '/^(?:(html)((?:\.[\w-]+)*)\s+)?body$/'
            : '/^(html)((?:\.[\w-]+)*)$/';

        $value = null;
        $best  = -1;
        preg_match_all('/([^{}]+)\{([^}]*)\}/', $css, $rules, PREG_SET_ORDER);
        foreach ($rules as [, $selectors, $declarations]) {
            if (! preg_match('/(?:^|;)\s*' . preg_quote($property, '/') . '\s*:\s*([^;]+)/', $declarations, $declared)) {
                continue;
            }

            foreach (explode(',', $selectors) as $selector) {
                if (! preg_match($pattern, trim($selector), $parts)) {
                    continue;
                }

                $classes = array_values(array_filter(explode('.', $parts[2] ?? '')));
                if (array_diff($classes, $rootClasses) !== []) {
                    continue;
                }

                $specificity = count($classes) * 10 + (($parts[1] ?? '') === 'html' ? 1 : 0) + ($element === 'body' ? 1 : 0);
                if ($specificity >= $best) {
                    $best  = $specificity;
                    $value = trim($declared[1]);
                }
            }
        }

        return $value;
    }

    /**
     * Regression pin. A fitted frame sizes itself to the document, so only it
     * measures, and a body stretched to the viewport it sets would run away.
     */
    public function test_only_a_fitted_frame_measures_itself_and_hugs_its_content(): void
    {
        $fitted = $this->document(autoResize: true);
        $plain  = $this->document();

        $this->assertStringContainsString('frameElement', $fitted);
        $this->assertStringNotContainsString('frameElement', $plain);

        $this->assertSame('0', $this->styled($fitted, 'body', 'min-height'));
        $this->assertSame('100vh', $this->styled($plain, 'body', 'min-height'));
    }

    /** Regression pin. The block editor frames the preview over its own canvas. */
    public function test_only_a_transparent_frame_drops_the_page_background(): void
    {
        $this->assertSame('transparent', $this->styled($this->document(transparent: true), 'body', 'background'));
        $this->assertSame('#fff', $this->styled($this->document(), 'body', 'background'));
    }

    /** The flag switches the frame guard off, so a real form printed later in the request must not inherit it. */
    public function test_building_a_preview_leaves_nothing_for_a_real_page_to_print(): void
    {
        $this->document();

        $this->assertFalse(wp_script_is('gratora-form-preview-flag', 'enqueued'));
        $this->assertStringNotContainsString(
            'gratoraFormPreview',
            implode("\n", (array) wp_scripts()->get_data('gratora-donation-form-runtime', 'before'))
                . implode("\n", (array) wp_scripts()->get_data('gratora-donation-form-runtime', 'after'))
        );
    }

    public function test_a_real_donation_page_carries_no_such_flag(): void
    {
        $blocks = '<!-- wp:gratora/donation-amount /--><!-- wp:gratora/submit-button /-->';

        $this->assertStringNotContainsString(
            'gratoraFormPreview',
            (string) $this->shortcode()->renderPreview($blocks)['html']
        );
    }
}
