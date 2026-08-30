<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Forms\Form;
use FundKit\Forms\FormReadinessService;
use FundKit\Foundation\Plugin;

/**
 * Two different switches put a form in test mode: the form's own checkbox and
 * the site-wide one in Settings. The readiness warning sent the author to
 * Settings for both, so when the cause was the form's own checkbox they arrived
 * at an org switch already off, and nothing they could touch there cleared it.
 */
final class FormTestModeCheckTest extends IntegrationTestCase
{
    /** @param array<string,mixed> $settings */
    private function form(array $settings): Form
    {
        $form = Form::make();
        $form->campaign_id = 1;
        $form->slug        = 'test-mode-' . bin2hex(random_bytes(3));
        $form->title       = 'Test mode probe';
        $form->status      = 'published';
        $form->blocks      = '';
        $form->settings    = $settings;
        $form->save();

        return $form;
    }

    /** @return array<string,mixed>|null */
    private function check(Form $form): ?array
    {
        $service = Plugin::instance()->container->get(FormReadinessService::class);
        foreach ($service->check($form) as $c) {
            if (($c['id'] ?? '') === 'test-mode') return $c;
        }
        return null;
    }

    public function test_the_form_s_own_switch_sends_the_author_to_the_form(): void
    {
        update_option('fundkit_gateway_config', ['test_mode' => false]);

        $check = $this->check($this->form(['test_mode' => true]));

        $this->assertSame('warn', $check['status']);
        $this->assertStringContainsString('page=fundkit-forms', $check['action_url']);
        $this->assertStringNotContainsString('fundkit-settings', $check['action_url'], 'the org switch is already off');
    }

    public function test_the_site_wide_switch_still_sends_them_to_settings(): void
    {
        update_option('fundkit_gateway_config', ['test_mode' => true]);

        $check = $this->check($this->form([]));

        $this->assertSame('warn', $check['status']);
        $this->assertStringContainsString('page=fundkit-settings', $check['action_url']);
    }

    public function test_neither_switch_on_is_a_pass(): void
    {
        update_option('fundkit_gateway_config', ['test_mode' => false]);

        $this->assertSame('pass', $this->check($this->form([]))['status']);
    }
}
