<?php

declare(strict_types=1);

namespace Dono\Tests\Unit\Assets;

use Dono\Settings\SettingsService;
use PHPUnit\Framework\TestCase;

/**
 * Every donor email core sends has to be listed in the settings editor. One
 * that is not is still sent, over the organization's name, with no screen
 * anywhere in wp-admin showing its subject, its body, or an off switch.
 *
 * magic_link is the single exception: its row would carry a send toggle, and a
 * site with that email off leaves a donor who asked for a sign-in link with a
 * confirmation screen and nothing in their inbox.
 *
 * @since 1.0.0
 */
final class EmailTemplateEditorCoverageTest extends TestCase
{
    private const NOT_EDITABLE = ['magic_link'];

    /**
     * @return list<string>
     */
    private function editorIds(): array
    {
        $path = dirname(__DIR__, 3) . '/assets/admin/_shared/emailTemplates.js';
        $this->assertFileExists($path);

        // The literal ids in coreTemplates(). Add-on rows read theirs off the
        // window global, so they carry no literal to match.
        preg_match_all("/id:\\s*'([a-z_]+)'/", (string) file_get_contents($path), $m);

        return $m[1];
    }

    public function test_every_core_donor_email_is_listed_in_the_editor(): void
    {
        $missing = array_values(array_diff(
            array_keys(SettingsService::templateTags()),
            $this->editorIds(),
            self::NOT_EDITABLE
        ));

        $this->assertSame(
            [],
            $missing,
            'These emails send with no editing surface: ' . implode(', ', $missing)
        );
    }

    public function test_the_editor_lists_no_template_core_does_not_send(): void
    {
        $unknown = array_values(array_diff(
            $this->editorIds(),
            array_keys(SettingsService::templateTags())
        ));

        $this->assertSame(
            [],
            $unknown,
            'These editor rows edit nothing: ' . implode(', ', $unknown)
        );
    }
}
