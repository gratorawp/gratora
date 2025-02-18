<?php

declare(strict_types=1);

namespace Dono\Tests\Unit\Assets;

use PHPUnit\Framework\TestCase;

/**
 * Locks in the donor-form styling-hardening work:
 *
 *  - every colour goes through a --dono-* token (raw hex only allowed in the
 *    token-default definitions or as a var(--dono-…, #fallback)), so a dev can
 *    theme every part via CSS variables;
 *  - the host-theme isolation boundary (:where(.dono-form)) stays present;
 *  - component rules keep the raised specificity (.dono-donation-form prefix)
 *    so theme element selectors don't out-rank them.
 *
 * If this fails, you reintroduced a hardcoded colour or removed a guard.
 */
final class RuntimeStylesGuardTest extends TestCase
{
    private function css(): string
    {
        $path = dirname(__DIR__, 3) . '/assets/donation-form/runtime.scss';
        $this->assertFileExists($path);
        return (string) file_get_contents($path);
    }

    public function test_no_hardcoded_hex_outside_tokens_or_fallbacks(): void
    {
        $offenders = [];
        foreach (explode("\n", $this->css()) as $i => $rawLine) {
            // Strip comments + sanctioned hex carriers, then see what's left.
            $line = preg_replace('#//.*$#', '', $rawLine);
            $line = preg_replace('#/\*.*?\*/#', '', (string) $line);

            // (a) token-default definitions: `--dono-x: #hex;`
            if (preg_match('/^\s*--dono-[a-z0-9-]+\s*:/', (string) $line)) {
                continue;
            }
            // (b) var(--dono-…, #fallback) - drop the whole var() expression.
            $line = preg_replace('/var\(\s*--dono-[^)]*\)/', '', (string) $line);

            if (preg_match('/#[0-9a-fA-F]{3,8}\b/', (string) $line)) {
                $offenders[] = ($i + 1) . ': ' . trim($rawLine);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Hardcoded colour(s) in runtime.scss must use a --dono-* token "
            . "or a var(--dono-…, #fallback):\n" . implode("\n", $offenders)
        );
    }

    public function test_isolation_boundary_and_specificity_guards_remain(): void
    {
        $css = $this->css();
        $this->assertStringContainsString(
            ':where(.dono-form)',
            $css,
            'The zero-specificity host-theme isolation boundary was removed.'
        );
        $this->assertStringContainsString(
            '.dono-donation-form .dono-form',
            $css,
            'Component rules lost the wrapper-prefixed specificity bump.'
        );
    }
}
