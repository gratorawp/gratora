<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Settings\SettingsService;
use Gratora\Foundation\Plugin;

/**
 * The portal built its token map by hand, so it skipped the derivations the
 * form and the campaign page get. One brand setting produced a legible checkout
 * and an illegible account area.
 */
final class PortalGetsTheSameBrandTest extends IntegrationTestCase
{
    private function brand(array $tokens): void
    {
        Plugin::instance()->container->get(SettingsService::class)->update('org-brand', [
            'presets'    => [['id' => 'classic', 'name' => 'Classic', 'tokens' => $tokens]],
            'default_id' => 'classic',
        ]);
    }

    private function css(): string
    {
        $shortcode = new \Gratora\Donors\Portal\PortalShortcode(
            Plugin::instance()->container->get(\Gratora\Donations\AntiSpamGuard::class)
        );
        $method    = new \ReflectionMethod($shortcode, 'brandCss');
        $method->setAccessible(true);

        return (string) $method->invoke($shortcode);
    }

    public function test_a_dark_ground_reaches_the_portal_with_light_ink(): void
    {
        $this->brand(['gratora-bg' => '#0f172a']);

        $css = $this->css();

        $this->assertStringContainsString('--gratora-text: #ffffff', str_replace(':#', ': #', $css));
    }

    public function test_a_pale_accent_reaches_the_portal_with_dark_ink(): void
    {
        $this->brand(['gratora-accent' => '#ffd400']);

        $this->assertStringContainsString('--gratora-on-accent:#10162a;', $this->css());
    }

    public function test_the_fields_keep_ink_of_their_own(): void
    {
        $this->brand(['gratora-bg' => '#0f172a']);

        $this->assertStringContainsString('--gratora-on-field:#10162a;', $this->css());
    }
}
