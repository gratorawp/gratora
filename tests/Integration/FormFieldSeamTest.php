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

    /**
     * The recap is a block now, not something the submit step draws. Where it
     * ends up is the author's decision, so it has to reach the runtime as a
     * positioned item like any other block rather than as a property of the
     * submit step.
     */
    public function test_the_summary_reaches_the_runtime_where_it_was_dropped(): void
    {
        $config = $this->configFor(
            '<!-- wp:dono/donation-amount /-->'
            . '<!-- wp:dono/donation-summary /-->'
            . '<!-- wp:dono/submit-button /-->'
        );

        $this->assertStringContainsString('"kind":"summary"', $config);

        // As a decoration. Tagged as a field it joins the field run, goes to
        // DonorStep, and renders nothing: the block is in the markup, the item
        // is in the config, and the donor sees no summary at all.
        $this->assertMatchesRegularExpression(
            '/"kind":"summary"[^}]*"t":"deco"/',
            $config,
            'the summary must reach the runtime as a decoration'
        );
    }

    /** And a form without the block asks for nothing to be drawn. */
    public function test_a_form_without_the_block_gets_no_summary(): void
    {
        $config = $this->configFor(
            '<!-- wp:dono/donation-amount /--><!-- wp:dono/submit-button /-->'
        );

        $this->assertStringNotContainsString('"kind":"summary"', $config);
        // And the submit step no longer carries the switch that used to decide.
        $this->assertStringNotContainsString('showSummary', $config);
    }
}
