<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Settings\SettingsService;
use FundKit\Foundation\Plugin;

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
        $shortcode = new \FundKit\Donors\Portal\PortalShortcode(
            Plugin::instance()->container->get(\FundKit\Donations\AntiSpamGuard::class)
        );
        $method    = new \ReflectionMethod($shortcode, 'brandCss');
        $method->setAccessible(true);

        return (string) $method->invoke($shortcode);
    }

    public function test_a_dark_ground_reaches_the_portal_with_light_ink(): void
    {
        $this->brand(['fundkit-bg' => '#0f172a']);

        $css = $this->css();

        $this->assertStringContainsString('--fundkit-text: #ffffff', str_replace(':#', ': #', $css));
    }

    public function test_a_pale_accent_reaches_the_portal_with_dark_ink(): void
    {
        $this->brand(['fundkit-accent' => '#ffd400']);

        $this->assertStringContainsString('--fundkit-on-accent:#10162a;', $this->css());
    }

    public function test_the_fields_keep_ink_of_their_own(): void
    {
        $this->brand(['fundkit-bg' => '#0f172a']);

        $this->assertStringContainsString('--fundkit-on-field:#10162a;', $this->css());
    }
}
