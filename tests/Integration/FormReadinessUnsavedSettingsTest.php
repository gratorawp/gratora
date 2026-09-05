<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Forms\Form;
use WP_REST_Request;

/**
 * The Preview view puts the pre-launch checks beside the preview iframe. The
 * iframe posts the editor's live settings and the checks posted only the
 * blocks, so the panel graded a form the author was no longer looking at: tick
 * test mode, and it still read "Form is ready to publish" next to a preview
 * that took no real payment.
 */
final class FormReadinessUnsavedSettingsTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        update_option('fundkit_gateway_config', ['test_mode' => false]);
    }

    /** @param array<string,mixed> $settings */
    private function form(array $settings = []): Form
    {
        $form = Form::make();
        $form->campaign_id = 1;
        $form->slug        = 'readiness-' . bin2hex(random_bytes(3));
        $form->title       = 'Readiness probe';
        $form->status      = 'published';
        $form->blocks      = '';
        $form->settings    = $settings;
        $form->save();

        return $form;
    }

    /**
     * @param array<string,mixed>|null $settings
     * @return array<string,mixed>|null
     */
    private function check(Form $form, string $id, ?array $settings = null): ?array
    {
        $req = new WP_REST_Request('POST', '/fundkit/v1/admin/forms/' . (int) $form->id . '/readiness');
        $req->set_param('id', (int) $form->id);
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode(
            $settings === null ? ['blocks' => ''] : ['blocks' => '', 'settings' => $settings]
        ));

        $res = rest_do_request($req);
        $this->assertSame(200, $res->get_status());

        foreach ((array) $res->get_data()['checks'] as $c) {
            if (($c['id'] ?? '') === $id) return $c;
        }

        return null;
    }

    public function test_an_unsaved_test_mode_tick_is_graded(): void
    {
        $form = $this->form(['test_mode' => false]);

        $this->assertSame('pass', $this->check($form, 'test-mode')['status'], 'fixture: the saved form is live');

        $check = $this->check($form, 'test-mode', ['test_mode' => true]);

        $this->assertSame(
            'warn',
            $check['status'],
            'the panel said the form was ready while the preview beside it took no real payment'
        );
    }

    public function test_unticking_test_mode_is_graded_too(): void
    {
        $form = $this->form(['test_mode' => true]);

        $this->assertSame('warn', $this->check($form, 'test-mode')['status'], 'fixture: the saved form is in test mode');
        $this->assertSame('pass', $this->check($form, 'test-mode', ['test_mode' => false])['status']);
    }

    /** Posting no settings still grades the saved ones, as it always did. */
    public function test_the_saved_settings_are_used_when_none_are_posted(): void
    {
        $form = $this->form(['test_mode' => true]);

        $this->assertSame('warn', $this->check($form, 'test-mode')['status']);
    }

    /** Nothing the panel is shown is written back to the form. */
    public function test_grading_unsaved_settings_does_not_save_them(): void
    {
        $form = $this->form(['test_mode' => false]);

        $this->check($form, 'test-mode', ['test_mode' => true]);

        $stored = Form::query()->where('id', (int) $form->id)->get();
        $this->assertFalse((bool) ($stored->settings['test_mode'] ?? false));
    }
}
