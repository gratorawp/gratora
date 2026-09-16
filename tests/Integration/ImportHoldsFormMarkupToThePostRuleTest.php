<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Campaigns\Campaign;
use Gratora\Forms\Form;
use Gratora\Foundation\Crypto\Crypto;
use Gratora\Foundation\Identity\IdentityHasher;
use Gratora\Foundation\Plugin;
use Gratora\Foundation\Transfer\DataExporter;
use Gratora\Foundation\Transfer\DataImporter;
use Gratora\Vendor\Queryable\DB;

/**
 * The import route needs only manage_options, which a multisite site admin and
 * an admin under DISALLOW_UNFILTERED_HTML both hold without unfiltered_html. A
 * file is whatever its author wrote, so the form markup it carries answers to
 * the same rule as markup saved in the builder.
 */
final class ImportHoldsFormMarkupToThePostRuleTest extends IntegrationTestCase
{
    private const RAW = '<img src="x" onerror="window.gratoraProbe=1">'
        . '<script>window.gratoraProbe=2</script>'
        . '<p class="note">Thank you for giving</p>'
        . '<iframe src="https://example.org/embed"></iframe>';

    public function test_an_importer_without_unfiltered_html_stores_the_form_without_script(): void
    {
        $slug   = $this->seedForm();
        $export = $this->export();
        $this->wipeForms();

        add_filter('map_meta_cap', static function (array $caps, string $cap): array {
            return $cap === 'unfiltered_html' ? ['do_not_allow'] : $caps;
        }, 10, 2);
        $this->assertFalse(current_user_can('unfiltered_html'), 'fixture: the importer lacks unfiltered_html');

        $this->import($export);

        $blocks = (string) Form::query()->where('slug', $slug)->get()->blocks;

        $this->assertStringNotContainsString('onerror', $blocks);
        $this->assertStringNotContainsString('<script', $blocks);
        $this->assertStringNotContainsString('<iframe', $blocks);
        $this->assertStringContainsString('<p class="note">Thank you for giving</p>', $blocks, 'post-safe markup is kept');
        $this->assertStringContainsString('<!-- wp:gratora/row {"columns":2} -->', $blocks, 'the block delimiters and their attributes survive');
        $this->assertStringContainsString('<!-- wp:gratora/name /-->', $blocks);
    }

    public function test_an_importer_with_unfiltered_html_keeps_the_markup_as_written(): void
    {
        $slug   = $this->seedForm();
        $export = $this->export();
        $this->wipeForms();

        $this->assertTrue(current_user_can('unfiltered_html'), 'fixture: the importer holds unfiltered_html');

        $this->import($export);

        $blocks = (string) Form::query()->where('slug', $slug)->get()->blocks;

        $this->assertStringContainsString(self::RAW, $blocks);
    }

    private function seedForm(): string
    {
        $now = gmdate('Y-m-d H:i:s');

        $campaign = Campaign::make();
        $campaign->title      = 'Import Markup';
        $campaign->slug       = 'import-markup-' . uniqid();
        $campaign->status     = 'published';
        $campaign->currency   = 'USD';
        $campaign->created_at = $now;
        $campaign->updated_at = $now;
        $campaign->save();

        $form = Form::make();
        $form->title       = 'Import Markup Form';
        $form->slug        = 'import-markup-form-' . uniqid();
        $form->status      = 'published';
        $form->campaign_id = (int) $campaign->id;
        $form->blocks      = '<!-- wp:gratora/row {"columns":2} -->'
            . self::RAW
            . '<!-- wp:gratora/name /-->'
            . '<!-- /wp:gratora/row -->';
        $form->created_at  = $now;
        $form->updated_at  = $now;
        $form->save();

        return (string) $form->slug;
    }

    /** @return array<string,mixed> */
    private function export(): array
    {
        $out = fopen('php://temp', 'r+');
        Plugin::instance()->container->get(DataExporter::class)->writeJson($out);
        rewind($out);
        $decoded = json_decode((string) stream_get_contents($out), true);
        fclose($out);

        $this->assertIsArray($decoded);

        return $decoded;
    }

    /** @param array<string,mixed> $export */
    private function import(array $export): void
    {
        (new DataImporter(
            Plugin::instance()->container->get(Crypto::class),
            Plugin::instance()->container->get(IdentityHasher::class),
        ))->import($export);
    }

    private function wipeForms(): void
    {
        $prefix = DB::getPrefix();
        DB::raw("DELETE FROM {$prefix}gratora_form_donation_stats");
        DB::raw("DELETE FROM {$prefix}gratora_forms");
    }
}
