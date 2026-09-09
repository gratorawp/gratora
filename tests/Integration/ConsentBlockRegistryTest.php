<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Forms\Blocks\BlockRegistry;
use Gratora\Forms\FormSubmissionValidator;
use Gratora\Foundation\Plugin;
use Gratora\Settings\SettingsService;

/**
 * The consent block names purposes the organization defined; it does not invent
 * them. A purpose invented on a form exists on that form and nowhere else, so
 * the wording a donor agreed to and the wording the org later edits could never
 * be the same text, and the portal would have no label to offer for withdrawal.
 */
final class ConsentBlockRegistryTest extends IntegrationTestCase
{
    private function settings(): SettingsService
    {
        return Plugin::instance()->container->get(SettingsService::class);
    }

    private function register(bool $required = false): void
    {
        $this->settings()->update('consents', [
            'purposes' => [
                [
                    'key'         => 'newsletter',
                    'label'       => 'Our newsletter',
                    'description' => 'Monthly stories.',
                    'required'    => $required,
                    'default'     => false,
                    'version'     => 1,
                ],
            ],
        ]);
    }

    private function block(): \Gratora\Forms\Blocks\Block
    {
        foreach (Plugin::instance()->container->get(BlockRegistry::class)->all() as $b) {
            if ($b->name() === 'gratora/consent') return $b;
        }

        $this->fail('the consent block is not registered');
    }

    protected function tearDown(): void
    {
        delete_option('gratora_consents');
        parent::tearDown();
    }

    public function test_it_declares_no_purposes_of_its_own(): void
    {
        $this->assertSame([], $this->block()->attributes()['purposeKeys']['default']);
    }

    public function test_a_picked_purpose_renders_with_the_registry_wording(): void
    {
        $this->register();

        $html = $this->block()->render(['purposeKeys' => ['newsletter']], '');

        $this->assertStringContainsString('Our newsletter', $html, 'the label comes from the registry');
        $this->assertStringContainsString('Monthly stories.', $html);
        $this->assertStringContainsString('consents[newsletter]', $html);
    }

    public function test_editing_the_registry_changes_what_the_form_says(): void
    {
        $this->register();
        $this->assertStringContainsString('Our newsletter', $this->block()->render(['purposeKeys' => ['newsletter']], ''));

        $this->settings()->update('consents', [
            'purposes' => [
                ['key' => 'newsletter', 'label' => 'Monthly email', 'description' => '', 'required' => false, 'default' => false, 'version' => 2],
            ],
        ]);

        $html = $this->block()->render(['purposeKeys' => ['newsletter']], '');
        $this->assertStringContainsString('Monthly email', $html);
        $this->assertStringNotContainsString('Our newsletter', $html);
    }

    public function test_a_key_missing_from_the_registry_renders_nothing_for_donors(): void
    {
        $this->register();

        $html = $this->block()->render(['purposeKeys' => ['deleted_purpose']], '');

        $this->assertStringNotContainsString('consents[deleted_purpose]', $html);
        $this->assertStringNotContainsString('<fieldset', $html);
    }

    public function test_nothing_renders_when_no_purpose_is_picked(): void
    {
        $this->register();

        $this->assertStringNotContainsString('<fieldset', $this->block()->render(['purposeKeys' => []], ''));
    }

    public function test_required_is_enforced_from_the_registry(): void
    {
        $this->register(true);

        $blocks = '<!-- wp:gratora/donation-amount {"presets":[{"cents":2500}]} /-->'
            . '<!-- wp:gratora/consent {"purposeKeys":["newsletter"]} /-->'
            . '<!-- wp:gratora/submit-button /-->';

        $form = \Gratora\Forms\Form::make();
        $form->blocks = $blocks;
        $form->settings = [];

        $reject = (new FormSubmissionValidator())->validate($form, [
            'amount_cents' => 2500,
            'consents'     => ['newsletter' => false],
        ]);

        $this->assertNotNull($reject, 'a required registry purpose must block the submission');
    }

    public function test_the_validator_reads_the_picked_keys(): void
    {
        $ids = FormSubmissionValidator::consentPurposeIds(
            '<!-- wp:gratora/consent {"purposeKeys":["newsletter","other"]} /-->'
        );

        $this->assertArrayHasKey('newsletter', $ids);
        $this->assertArrayHasKey('other', $ids);
    }
}
