<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Settings\SettingsService;

/**
 * The admin editor offers merge tags to an author as safe to drop into a
 * template. Mailer::interpolate only replaces the tags it is handed, so an
 * offered tag that no sender fills does not quietly disappear: it arrives in
 * the donor's inbox as literal braces.
 *
 * That makes the offered list a promise, and these pin both halves of it.
 */
final class EmailTemplateTagsTest extends IntegrationTestCase
{
    /** Every shipped template must say what it offers. */
    public function test_every_template_declares_its_tags(): void
    {
        $templates = array_keys((new SettingsService())->get('email')['templates'] ?? []);
        $declared  = SettingsService::templateTags();

        $this->assertNotEmpty($templates, 'no templates to check');

        foreach ($templates as $id) {
            $this->assertArrayHasKey(
                $id,
                $declared,
                "Template '{$id}' offers no tag list, so the editor cannot tell an author what is safe."
            );
        }
    }

    /**
     * The failure this exists for: a default body using a tag its sender does
     * not pass. That reaches the donor verbatim.
     */
    public function test_no_default_body_uses_a_tag_its_template_does_not_offer(): void
    {
        $email    = (new SettingsService())->get('email');
        $declared = SettingsService::templateTags();
        $offences = [];

        foreach ((array) ($email['templates'] ?? []) as $id => $tpl) {
            $text = (string) ($tpl['subject'] ?? '') . ' ' . (string) ($tpl['body'] ?? '');
            preg_match_all('/\{([a-z_]+)\}/', $text, $m);

            $undeclared = array_diff(array_unique($m[1]), $declared[$id] ?? []);
            if ($undeclared !== []) {
                $offences[] = $id . ': ' . implode(', ', $undeclared);
            }
        }

        $this->assertSame([], $offences, "Template bodies using tags nobody supplies:\n" . implode("\n", $offences));
    }

    /** An add-on may add tags for its own templates without patching core. */
    public function test_an_addon_can_declare_tags_through_the_filter(): void
    {
        add_filter('dono.email.template_tags', static function (array $tags): array {
            $tags['addon_thing'] = ['donor_name', 'widget_count'];
            return $tags;
        });

        $this->assertSame(['donor_name', 'widget_count'], SettingsService::templateTags()['addon_thing'] ?? null);
    }
}
