<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Campaigns\Campaign;
use Gratora\Forms\Form;
use Gratora\Forms\Rendering\FormDocument;
use Gratora\Forms\Shortcode\DonationFormShortcode;
use Gratora\Foundation\Plugin;

/**
 * The rendering seam: markup plus the assets that hydrate it, for a stored form and for the
 * editor's unsaved stub alike.
 */
final class FormDocumentServiceTest extends IntegrationTestCase
{
    private const BLOCKS = '<!-- wp:gratora/donation-amount /--><!-- wp:gratora/name /-->'
        . '<!-- wp:gratora/email /--><!-- wp:gratora/submit-button /-->';

    private const SYNTHETIC = [
        'gratora-doc-a',
        'gratora-doc-b',
        'gratora-doc-c',
        'gratora-doc-x',
        'gratora-doc-y',
    ];

    protected function tearDown(): void
    {
        foreach (self::SYNTHETIC as $handle) {
            wp_deregister_script($handle);
        }

        parent::tearDown();
    }

    private function shortcode(): DonationFormShortcode
    {
        return Plugin::instance()->container->get(DonationFormShortcode::class);
    }

    private function storedForm(): Form
    {
        $campaign             = Campaign::make();
        $campaign->title      = 'Document';
        $campaign->slug       = 'document-' . uniqid();
        $campaign->status     = 'published';
        $campaign->created_at = gmdate('Y-m-d H:i:s');
        $campaign->updated_at = $campaign->created_at;
        $campaign->save();

        $form              = Form::make();
        $form->title       = 'Document form';
        $form->slug        = 'document-form-' . uniqid();
        $form->status      = 'published';
        $form->blocks      = self::BLOCKS;
        $form->campaign_id = (int) $campaign->id;
        $form->created_at  = gmdate('Y-m-d H:i:s');
        $form->updated_at  = $form->created_at;
        $form->save();

        return $form;
    }

    private function chain(): void
    {
        wp_register_script('gratora-doc-a', 'https://example.org/a.js', [], '1', true);
        wp_register_script('gratora-doc-b', 'https://example.org/b.js', ['gratora-doc-a'], '1', true);
        wp_register_script('gratora-doc-c', 'https://example.org/c.js', ['gratora-doc-b'], '1', true);
    }

    public function test_a_stored_form_yields_the_document_shape(): void
    {
        $document = (new FormDocument($this->shortcode()))->forForm($this->storedForm());

        $this->assertSame(['html', 'cssUrl', 'jsUrl', 'jsDeps'], array_keys($document));
        $this->assertStringContainsString('build/donation-form/runtime.css?v=', $document['cssUrl']);
        $this->assertStringContainsString('build/donation-form/runtime/index.js?v=', $document['jsUrl']);
        $this->assertIsArray($document['jsDeps']);
    }

    public function test_the_markup_is_the_stored_form_and_not_a_stub(): void
    {
        $form     = $this->storedForm();
        $document = (new FormDocument($this->shortcode()))->forForm($form);

        $this->assertStringContainsString('data-form-slug="' . $form->slug . '"', $document['html']);

        $config = $this->formConfigIn($document['html']);
        $this->assertSame((int) $form->id, $config['form_id']);
        $this->assertSame((int) $form->campaign_id, $config['campaign_id']);
        // A stored row is what the submit gate scopes a token to, so this is
        // the whole difference between the seam and the editor preview.
        $this->assertNotSame('', (string) $config['spam']['formToken']);
    }

    public function test_the_preview_stub_still_mints_no_form_token(): void
    {
        $preview = $this->shortcode()->renderPreview(self::BLOCKS);

        $config = $this->formConfigIn($preview['html']);
        $this->assertSame(0, $config['form_id']);
        $this->assertSame('', (string) $config['spam']['formToken']);
    }

    public function test_renderPreview_returns_the_same_shape_and_the_same_assets(): void
    {
        $shortcode = $this->shortcode();

        $preview = $shortcode->renderPreview(self::BLOCKS);
        $stored  = (new FormDocument($shortcode))->forForm($this->storedForm());

        $this->assertSame(['html', 'cssUrl', 'jsUrl', 'jsDeps'], array_keys($preview));
        $this->assertStringContainsString('data-form-slug="preview-', $preview['html']);
        $this->assertSame($stored['cssUrl'], $preview['cssUrl']);
        $this->assertSame($stored['jsUrl'], $preview['jsUrl']);
        $this->assertSame($stored['jsDeps'], $preview['jsDeps']);
    }

    /**
     * The declared handle list alone once shipped a dependent without the
     * dependency it reads at module scope, and nothing hydrated.
     */
    public function test_the_walk_reaches_a_dependency_of_a_dependency(): void
    {
        $this->chain();

        $this->assertSame(
            ['gratora-doc-a', 'gratora-doc-b', 'gratora-doc-c'],
            FormDocument::withDependencies(['gratora-doc-c'])
        );
    }

    public function test_the_walk_terminates_on_a_dependency_cycle(): void
    {
        wp_register_script('gratora-doc-x', 'https://example.org/x.js', ['gratora-doc-y'], '1', true);
        wp_register_script('gratora-doc-y', 'https://example.org/y.js', ['gratora-doc-x'], '1', true);

        $ordered = FormDocument::withDependencies(['gratora-doc-x']);

        $this->assertContains('gratora-doc-x', $ordered);
        $this->assertContains('gratora-doc-y', $ordered);
    }

    public function test_the_preview_document_inlines_the_walked_dependencies(): void
    {
        $this->chain();
        $shortcode = $this->shortcode();

        $preview           = $shortcode->renderPreview(self::BLOCKS);
        $preview['jsDeps'] = ['gratora-doc-c'];
        $document          = $shortcode->buildPreviewDocument($preview);

        $this->assertStringContainsString('https://example.org/a.js', $document);
        $this->assertLessThan(
            strpos($document, 'https://example.org/c.js'),
            strpos($document, 'https://example.org/a.js'),
            'a dependency that arrives after its dependent has already thrown'
        );
    }
}
