<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Forms\FormTemplates;

/**
 * A template names the settings it has an opinion about, and applying one
 * leaves the rest of the form alone. Undo restores blocks, not settings, so
 * anything a template overwrites without naming is configuration the author
 * cannot get back.
 *
 * @covers \Dono\Forms\FormTemplates
 */
final class FormTemplateSettingsScopeTest extends IntegrationTestCase
{
    /**
     * Where a particular organisation sends its donors afterwards is not
     * something a starter template can know.
     */
    public function test_no_template_has_an_opinion_about_the_thank_you_redirect(): void
    {
        foreach (FormTemplates::all() as $template) {
            $this->assertArrayNotHasKey(
                'redirect_url',
                (array) ($template['settings'] ?? []),
                sprintf('template "%s" clears the redirect the author set', (string) ($template['id'] ?? '?'))
            );
        }
    }

    /** The same holds for anything else a template cannot know about a site. */
    public function test_no_template_declares_a_setting_it_cannot_know(): void
    {
        $cannotKnow = ['goal', 'container', 'test_mode', 'campaign_id'];

        foreach (FormTemplates::all() as $template) {
            $settings = (array) ($template['settings'] ?? []);
            foreach ($cannotKnow as $key) {
                $this->assertArrayNotHasKey(
                    $key,
                    $settings,
                    sprintf('template "%s" overwrites %s', (string) ($template['id'] ?? '?'), $key)
                );
            }
        }
    }
}
