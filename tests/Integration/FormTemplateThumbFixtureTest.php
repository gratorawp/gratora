<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Forms\FormTemplates;

/**
 * The template picker derives each thumbnail from the template's own block
 * markup, in JavaScript. The JS suite cannot read FormTemplates.php, so it
 * works from a fixture, and a fixture that drifts from the templates would let
 * a thumbnail regression pass unnoticed.
 *
 * Run with FUNDKIT_WRITE_THUMB_FIXTURE=1 to regenerate after changing a template.
 */
final class FormTemplateThumbFixtureTest extends IntegrationTestCase
{
    private const FIXTURE = __DIR__ . '/../js/fixtures/form-templates.json';

    /** @return array<string, string> */
    private function live(): array
    {
        $out = [];
        foreach (FormTemplates::all() as $t) {
            $out[(string) $t['id']] = (string) ($t['blocks'] ?? '');
        }
        ksort($out);

        return $out;
    }

    public function test_the_javascript_fixture_still_matches_the_templates(): void
    {
        $live = $this->live();

        if (getenv('FUNDKIT_WRITE_THUMB_FIXTURE')) {
            if (! is_dir(dirname(self::FIXTURE))) {
                mkdir(dirname(self::FIXTURE), 0o777, true);
            }
            file_put_contents(
                self::FIXTURE,
                json_encode($live, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
            );
        }

        $this->assertFileExists(self::FIXTURE, 'regenerate with FUNDKIT_WRITE_THUMB_FIXTURE=1');

        $fixture = json_decode((string) file_get_contents(self::FIXTURE), true);

        $this->assertSame(
            array_keys($live),
            array_keys((array) $fixture),
            'a template was added or removed; regenerate with FUNDKIT_WRITE_THUMB_FIXTURE=1'
        );
        $this->assertSame(
            $live,
            $fixture,
            'a template\'s blocks changed; regenerate with FUNDKIT_WRITE_THUMB_FIXTURE=1 and check the thumbnails still read apart'
        );
    }
}
