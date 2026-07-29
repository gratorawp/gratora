<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Forms\Form;
use Dono\Forms\Shortcode\FormFieldAssets;
use WP_REST_Request;

/**
 * What an add-on needs to put a field of its own on a donation form: the walker
 * has to turn its block into a runtime item, and the browser needs a registry to
 * hang the component off. Without both, a field block outside core renders in
 * the editor and then silently disappears from the live form.
 */
final class FormFieldSeamTest extends IntegrationTestCase
{
    private int $campaignId;

    protected function setUp(): void
    {
        parent::setUp();

        $req = new WP_REST_Request('POST', '/dono/v1/admin/campaigns');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode(['title' => 'Seam campaign', 'status' => 'published']));
        $this->campaignId = (int) rest_do_request($req)->get_data()['id'];
    }

    public function test_an_add_on_block_becomes_a_runtime_field(): void
    {
        add_filter('dono.form.block_field', static function ($item, string $name, array $attrs) {
            if ($name !== 'acme/keepsake') return $item;

            return ['kind' => 'keepsake', 'label' => (string) ($attrs['label'] ?? '')];
        }, 10, 3);

        $config = json_decode($this->configFor(
            '<!-- wp:acme/keepsake {"label":"MK_KEEPSAKE"} /-->'
        ), true);

        $field = $this->firstField($config);
        $this->assertSame('keepsake', $field['kind'] ?? null);
        $this->assertSame('MK_KEEPSAKE', $field['label'] ?? null);
        $this->assertSame('field', $field['t'] ?? null, 'the walker tags it like any other field');
    }

    public function test_an_add_on_field_carries_its_conditional_visibility(): void
    {
        add_filter('dono.form.block_field', static function ($item, string $name) {
            return $name === 'acme/keepsake' ? ['kind' => 'keepsake'] : $item;
        }, 10, 2);

        $config = json_decode($this->configFor(
            '<!-- wp:acme/keepsake {"condition":{"field":"amount_cents","op":">","value":"5000"}} /-->'
        ), true);

        $this->assertSame(
            ['field' => 'amount_cents', 'op' => '>', 'value' => '5000'],
            $this->firstField($config)['condition'] ?? null
        );
    }

    /** A block nobody claims is left out rather than emitted as a broken field. */
    public function test_an_unclaimed_block_is_dropped(): void
    {
        $config = json_decode($this->configFor(
            '<!-- wp:acme/keepsake {"label":"MK_KEEPSAKE"} /-->'
        ), true);

        $this->assertNull($this->firstField($config));
    }

    public function test_the_form_publishes_a_browser_registry_for_add_on_fields(): void
    {
        $fired = [];
        add_action(FormFieldAssets::ACTION, static function (string $handle) use (&$fired): void {
            $fired[] = $handle;
        });

        FormFieldAssets::enqueue();

        $this->assertSame([FormFieldAssets::HANDLE], $fired, 'add-ons are told which handle to depend on');
        $this->assertTrue(wp_script_is(FormFieldAssets::HANDLE, 'enqueued'));
    }

    public function test_the_donation_form_runtime_depends_on_the_field_registry(): void
    {
        global $wp_scripts;

        $slug = $this->publishedForm('<!-- wp:dono/submit-button /-->');
        do_shortcode('[dono_donation_form slug="' . $slug . '"]');

        $runtime = $wp_scripts->registered['dono-donation-form-runtime'] ?? null;
        $this->assertNotNull($runtime);
        $this->assertContains(FormFieldAssets::HANDLE, $runtime->deps);
    }

    /** @param array<string,mixed>|null $config */
    private function firstField(?array $config): ?array
    {
        foreach ((array) ($config['steps'] ?? []) as $step) {
            foreach ((array) ($step['items'] ?? []) as $item) {
                if (($item['t'] ?? '') === 'field') return $item;
            }
        }

        return null;
    }

    private function configFor(string $blocks): string
    {
        $html = do_shortcode('[dono_donation_form slug="' . $this->publishedForm($blocks) . '"]');
        preg_match('/data-dono-form-config>(.+?)<\/script>/s', $html, $m);

        return (string) ($m[1] ?? '');
    }

    private function publishedForm(string $blocks): string
    {
        $req = new WP_REST_Request('POST', '/dono/v1/admin/forms');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode([
            'title'       => 'Seam form',
            'blocks'      => $blocks,
            'campaign_id' => $this->campaignId,
        ]));
        $created = rest_do_request($req)->get_data();

        $form = Form::query()->find('id', (int) $created['id']);
        $form->status = 'published';
        $form->save();

        return (string) $created['slug'];
    }
}
