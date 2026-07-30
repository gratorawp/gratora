<?php

declare(strict_types=1);

namespace Dono\Tests\Unit;

use Dono\Campaigns\Styling\Tokens;
use PHPUnit\Framework\TestCase;

/**
 * Every brand token an admin can edit has to reach the donor-facing form, or
 * the control is a lie: it moves in the editor and changes nothing a donor
 * sees. This walks the catalogue against the stylesheets that actually render
 * the form and the campaign blocks, so a token added to the editor without
 * being wired to the frontend fails here rather than shipping as a dead knob.
 *
 * It reads SCSS source, not compiled CSS: if the var is referenced in source it
 * compiles through, and source is what a developer touches.
 */
final class TokenCoverageTest extends TestCase
{
    private static function frontendCss(): string
    {
        $root  = dirname(__DIR__, 2);
        $globs = [
            '/assets/donation-form/runtime.scss',
            '/assets/campaign-blocks/blocks.scss',
            '/assets/donor-portal/portal.scss',
        ];
        $css = '';
        foreach ($globs as $rel) {
            $path = $root . $rel;
            if (is_file($path)) {
                $css .= file_get_contents($path) . "\n";
            }
        }
        // The block server-renderers set --dono-accent inline on their wrappers.
        foreach (glob($root . '/src/Campaigns/Blocks/views/*.php') ?: [] as $view) {
            $css .= file_get_contents($view) . "\n";
        }
        return $css;
    }

    public function test_the_catalogue_is_not_empty(): void
    {
        // Guards against the glob or the API silently returning nothing, which
        // would make every coverage assertion below pass vacuously.
        $this->assertNotEmpty(Tokens::catalogue(), 'the token catalogue is empty');
        $this->assertNotEmpty(self::frontendCss(), 'no frontend stylesheet was read');
    }

    /**
     * @dataProvider tokenKeys
     */
    public function test_every_editable_token_reaches_the_frontend(string $token): void
    {
        $this->assertStringContainsString(
            "var(--{$token}",
            self::frontendCss(),
            "the brand token {$token} is editable but nothing on the donor-facing form uses it"
        );
    }

    /** @return array<string, array{0:string}> */
    public static function tokenKeys(): array
    {
        $out = [];
        foreach (array_keys(Tokens::catalogue()) as $key) {
            $out[$key] = [$key];
        }
        return $out;
    }
}
