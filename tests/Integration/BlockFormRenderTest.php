<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Settings\SettingsService;
use WP_REST_Request;

/**
 * `[dono_donation_form slug="..."]` running through the block-render pipeline:
 *   shortcode → resolve form by slug → do_blocks(form.blocks) → wrap in <form>.
 */
final class BlockFormRenderTest extends IntegrationTestCase
{
    private int $campaignId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->campaignId = $this->createCampaign();
    }

    public function test_bare_shortcode_without_slug_renders_admin_diagnostic(): void
    {
        $html = do_shortcode('[dono_donation_form]');
        $this->assertStringContainsString('class="dono-donation-form__error"', $html);
    }

    public function test_explicit_slug_renders_matching_form(): void
    {
        $created = $this->createForm([
            'title'  => 'Tiny',
            'blocks' => '<!-- wp:dono/submit-button {"label":"Give now"} /-->',
        ]);

        $html = do_shortcode('[dono_donation_form slug="' . $created['slug'] . '"]');

        $this->assertStringContainsString('data-form-slug="' . $created['slug'] . '"', $html);
        $this->assertStringContainsString('Give now', $html);
    }

    public function test_amount_block_renders_presets_and_hidden_inputs(): void
    {
        $created = $this->createForm([
            'title'  => 'Amounts',
            'blocks' => '<!-- wp:dono/donation-amount {"presets":[1000,2500,5000,10000],"currency":"EUR"} /-->',
        ]);

        $html = do_shortcode('[dono_donation_form slug="' . $created['slug'] . '"]');

        $this->assertMatchesRegularExpression('/<input type="hidden"\s+name="amount_cents"\s+value="\d+"/', $html);
        $this->assertMatchesRegularExpression('/<input type="hidden"\s+name="currency"\s+value="EUR"/', $html);
        $this->assertStringContainsString('data-cents="1000"',  $html);
        $this->assertStringContainsString('data-cents="2500"',  $html);
        $this->assertStringContainsString('data-cents="5000"',  $html);
        $this->assertStringContainsString('data-cents="10000"', $html);
    }

    public function test_donor_block_renders_required_email_and_optional_name(): void
    {
        $created = $this->createForm([
            'title'  => 'Donor',
            'blocks' => '<!-- wp:dono/email /--><!-- wp:dono/name /-->',
        ]);

        $html = do_shortcode('[dono_donation_form slug="' . $created['slug'] . '"]');

        $this->assertMatchesRegularExpression('/name="email"[^>]*required/', $html);
        $this->assertMatchesRegularExpression('/name="profile\[first_name\]"[^>]*required/', $html);
        $this->assertMatchesRegularExpression('/name="profile\[last_name\]"[^>]*required/',  $html);
        $this->assertStringNotContainsString('name="profile[country]"', $html);
    }

    public function test_submit_block_renders_button_with_label(): void
    {
        $created = $this->createForm([
            'title'  => 'Submit',
            'blocks' => '<!-- wp:dono/submit-button /-->',
        ]);

        $html = do_shortcode('[dono_donation_form slug="' . $created['slug'] . '"]');

        // The SSR fallback (shown only without JS) must not submit: the form has
        // no action/method, so a real submit would GET the donor's inputs into
        // the URL. The Preact runtime swaps this out on mount.
        $this->assertMatchesRegularExpression('/<button\s+type="button"\s+class="dono-submit"\s+disabled>/', $html);
        $this->assertStringContainsString('Donate', $html);
    }

    public function test_unknown_slug_renders_admin_visible_error(): void
    {
        $html = do_shortcode('[dono_donation_form slug="does-not-exist"]');

        $this->assertStringContainsString('class="dono-donation-form__error"', $html);
        $this->assertStringContainsString('does-not-exist', $html);
        $this->assertStringNotContainsString('dono-donation-form--blocks', $html);
    }

    public function test_unknown_slug_renders_empty_string_for_visitors(): void
    {
        wp_set_current_user(0);

        $html = do_shortcode('[dono_donation_form slug="does-not-exist"]');

        $this->assertSame('', $html);
    }

    public function test_form_with_empty_blocks_renders_an_empty_form_wrapper(): void
    {
        $created = $this->createForm(['title' => 'Empty', 'blocks' => '']);

        $html = do_shortcode('[dono_donation_form slug="' . $created['slug'] . '"]');

        $this->assertStringContainsString('dono-donation-form--blocks', $html);
    }

    public function test_shortcode_default_gateway_is_offline_when_settings_empty(): void
    {
        $created = $this->createForm(['title' => 'Default gateway']);

        $html = do_shortcode('[dono_donation_form slug="' . $created['slug'] . '"]');

        $this->assertStringContainsString('data-gateway="offline"', $html);
    }

    public function test_shortcode_emits_test_mode_off_by_default(): void
    {
        $created = $this->createForm(['title' => 'Mode default']);

        $html = do_shortcode('[dono_donation_form slug="' . $created['slug'] . '"]');

        $this->assertStringContainsString('"testMode":false', $html);
    }

    public function test_shortcode_falls_back_to_offline_when_whitelisted_gateway_unavailable(): void
    {
        // Whitelisting Stripe does not make it selectable when Stripe is not
        // connected (not registered): optionsFor() intersects the allowed
        // list with what is actually available, so the form falls back to the
        // always-registered offline gateway.
        $created = $this->createForm([
            'title'    => 'Stripe-only',
            'settings' => ['gateways' => ['allowed' => ['stripe']]],
        ]);

        $html = do_shortcode('[dono_donation_form slug="' . $created['slug'] . '"]');

        $this->assertStringContainsString('data-gateway="offline"', $html);
    }

    public function test_shortcode_omits_stripe_config_when_stripe_not_offered(): void
    {
        // Stripe is not connected on the test site, so the form offers only
        // offline. The client Stripe config (publishable key) must be absent -
        // never leaked, and resolving StripeApi from the container must not
        // throw into the render path.
        $created = $this->createForm(['title' => 'No stripe']);

        $html = do_shortcode('[dono_donation_form slug="' . $created['slug'] . '"]');

        preg_match('/<script type="application\/json" data-dono-form-config>(.+?)<\/script>/s', $html, $m);
        $config = json_decode($m[1], true);

        $this->assertIsArray($config);
        $this->assertArrayHasKey('stripe', $config);
        $this->assertNull($config['stripe'], 'No Stripe publishable key should be emitted when Stripe is not offered.');
    }

    public function test_shortcode_emits_runtime_config_json_with_steps_per_block(): void
    {
        $created = $this->createForm([
            'title'  => 'Multi step',
            'blocks' => <<<BLOCKS
<!-- wp:dono/donation-amount {"presets":[1500,3000,7500],"currency":"USD","allowCustom":false} /-->

<!-- wp:dono/name {"requireFirst":true} /-->
<!-- wp:dono/country /-->

<!-- wp:dono/submit-button {"label":"Give USD"} /-->
BLOCKS,
        ]);

        $html = do_shortcode('[dono_donation_form slug="' . $created['slug'] . '"]');

        // Extract the config JSON the runtime will read.
        $this->assertMatchesRegularExpression(
            '/<script type="application\/json" data-dono-form-config>(.+?)<\/script>/s',
            $html,
            'Form should emit a config script tag for the Preact runtime.'
        );
        preg_match('/<script type="application\/json" data-dono-form-config>(.+?)<\/script>/s', $html, $m);
        $config = json_decode($m[1], true);

        $this->assertIsArray($config);
        $this->assertSame($created['slug'], $config['slug']);
        $this->assertSame('USD', $config['currency']);
        $this->assertNotEmpty($config['rest']);
        // nonce is emitted only for logged-in users (anonymous donors omit it so
        // a page-cached form never carries a stale nonce); the key is always present.
        $this->assertArrayHasKey('nonce', $config);

        // amount + (name, country coalesce into one donor step) + submit → 3 steps.
        $this->assertCount(3, $config['steps']);
        $this->assertSame('amount', $config['steps'][0]['type']);
        $this->assertSame([1500, 3000, 7500], array_column($config['steps'][0]['presets'], 'cents'));
        $this->assertFalse($config['steps'][0]['allowCustom']);

        $this->assertSame('donor', $config['steps'][1]['type']);
        $donorFields = $this->stepFields($config['steps'][1]);
        $fieldKinds = array_column($donorFields, 'kind');
        $this->assertSame(['name', 'country'], $fieldKinds);
        $nameField = $donorFields[0];
        $this->assertTrue($nameField['requireFirst']);

        $this->assertSame('submit', $config['steps'][2]['type']);
        $this->assertSame('Give USD', $config['steps'][2]['label']);
    }

    public function test_runtime_config_appends_submit_step_when_form_lacks_one(): void
    {
        $created = $this->createForm([
            'title'  => 'No submit',
            'blocks' => '<!-- wp:dono/donation-amount {"presets":[500]} /-->',
        ]);

        $html = do_shortcode('[dono_donation_form slug="' . $created['slug'] . '"]');
        preg_match('/<script type="application\/json" data-dono-form-config>(.+?)<\/script>/s', $html, $m);
        $config = json_decode($m[1], true);

        $types = array_column($config['steps'], 'type');
        $this->assertContains('submit', $types, 'Runtime needs a submit/review step at the end.');
    }

    public function test_currency_switcher_only_offers_org_enabled_currencies(): void
    {
        // Base is USD in the test env; org enables USD + EUR + GBP.
        (new SettingsService())->update('currency-locale', [
            'default_currency'     => 'USD',
            'supported_currencies' => ['USD', 'EUR', 'GBP'],
        ]);

        // Author picks EUR (enabled) + JPY (NOT enabled). JPY must be dropped,
        // base (USD) must lead, and the switcher must surface.
        $created = $this->createForm([
            'title'  => 'Switcher',
            'blocks' => <<<BLOCKS
<!-- wp:dono/donation-amount {"presets":[1000],"currency":"USD"} /-->

<!-- wp:dono/currency-switcher {"currencies":["EUR","JPY"]} /-->

<!-- wp:dono/submit-button {"label":"Give"} /-->
BLOCKS,
        ]);

        $html = do_shortcode('[dono_donation_form slug="' . $created['slug'] . '"]');
        preg_match('/<script type="application\/json" data-dono-form-config>(.+?)<\/script>/s', $html, $m);
        $config = json_decode($m[1], true);

        $this->assertSame(['USD', 'EUR'], $config['currencies'], 'base first, JPY (not enabled) dropped');
    }

    public function test_forms_currencies_endpoint_returns_org_enabled_set(): void
    {
        (new SettingsService())->update('currency-locale', [
            'default_currency'     => 'USD',
            'supported_currencies' => ['USD', 'EUR'],
        ]);

        $res = rest_do_request(new WP_REST_Request('GET', '/dono/v1/admin/forms/currencies'));
        $this->assertSame(200, $res->get_status());
        $data = $res->get_data();
        $this->assertSame('USD', $data['base']);
        $this->assertSame('USD', $data['currencies'][0], 'base currency is first');
        $this->assertContains('EUR', $data['currencies']);
    }

    public function test_runtime_config_includes_fx_rates_for_offered_currencies(): void
    {
        (new SettingsService())->update('currency-locale', [
            'default_currency'     => 'USD',
            'supported_currencies' => ['USD', 'EUR'],
        ]);
        update_option('dono_fx_rates', [
            'base'  => 'USD',
            'date'  => gmdate('Y-m-d'),
            'rates' => ['EUR' => 0.9],
        ], false);

        $created = $this->createForm([
            'title'  => 'Fx form',
            'blocks' => <<<BLOCKS
<!-- wp:dono/donation-amount {"presets":[1000],"currency":"USD"} /-->

<!-- wp:dono/currency-switcher {"currencies":["EUR"]} /-->

<!-- wp:dono/submit-button {"label":"Give"} /-->
BLOCKS,
        ]);

        $html = do_shortcode('[dono_donation_form slug="' . $created['slug'] . '"]');
        preg_match('/<script type="application\/json" data-dono-form-config>(.+?)<\/script>/s', $html, $m);
        $config = json_decode($m[1], true);

        $this->assertSame('USD', $config['fx']['base']);
        $this->assertEqualsWithDelta(1.0, $config['fx']['rates']['USD'], 1e-9, 'base is unity');
        $this->assertEqualsWithDelta(0.9, $config['fx']['rates']['EUR'], 1e-9);
    }

    public function test_runtime_config_carries_currency_switcher_style_and_align(): void
    {
        (new SettingsService())->update('currency-locale', [
            'default_currency'     => 'USD',
            'supported_currencies' => ['USD', 'EUR'],
        ]);

        $created = $this->createForm([
            'title'  => 'Switcher style',
            'blocks' => <<<BLOCKS
<!-- wp:dono/donation-amount {"presets":[1000],"currency":"USD"} /-->

<!-- wp:dono/currency-switcher {"currencies":["EUR"],"label":"Pick currency","style":"pills","align":"right"} /-->

<!-- wp:dono/submit-button {"label":"Give"} /-->
BLOCKS,
        ]);

        $html = do_shortcode('[dono_donation_form slug="' . $created['slug'] . '"]');
        preg_match('/<script type="application\/json" data-dono-form-config>(.+?)<\/script>/s', $html, $m);
        $config = json_decode($m[1], true);

        $this->assertSame('pills', $config['currencySwitcher']['style']);
        $this->assertSame('right', $config['currencySwitcher']['align']);
        $this->assertSame('Pick currency', $config['currencySwitcher']['label']);

        // Garbage style/align coerce back to safe defaults.
        $bad = $this->createForm([
            'title'  => 'Switcher bad',
            'blocks' => <<<BLOCKS
<!-- wp:dono/donation-amount {"presets":[1000],"currency":"USD"} /-->

<!-- wp:dono/currency-switcher {"currencies":["EUR"],"style":"nope","align":"sideways"} /-->

<!-- wp:dono/submit-button /-->
BLOCKS,
        ]);
        $html2 = do_shortcode('[dono_donation_form slug="' . $bad['slug'] . '"]');
        preg_match('/<script type="application\/json" data-dono-form-config>(.+?)<\/script>/s', $html2, $m2);
        $config2 = json_decode($m2[1], true);
        $this->assertSame('dropdown', $config2['currencySwitcher']['style']);
        $this->assertSame('left', $config2['currencySwitcher']['align']);
    }

    public function test_currency_switcher_omits_label_markup_when_label_empty(): void
    {
        (new SettingsService())->update('currency-locale', [
            'default_currency'     => 'USD',
            'supported_currencies' => ['USD', 'EUR'],
        ]);

        $withLabel = $this->createForm([
            'title'  => 'Labelled',
            'blocks' => '<!-- wp:dono/currency-switcher {"currencies":["EUR"],"label":"Pick one"} /-->',
        ]);
        $html = do_shortcode('[dono_donation_form slug="' . $withLabel['slug'] . '"]');
        $this->assertStringContainsString('dono-currency__label', $html);
        $this->assertStringContainsString('Pick one', $html);

        $noLabel = $this->createForm([
            'title'  => 'Unlabelled',
            'blocks' => '<!-- wp:dono/currency-switcher {"currencies":["EUR"]} /-->',
        ]);
        $html2 = do_shortcode('[dono_donation_form slug="' . $noLabel['slug'] . '"]');
        $this->assertStringNotContainsString('dono-currency__label', $html2, 'No label span when empty');
        // Accessible name still present on the control.
        $this->assertStringContainsString('aria-label="Currency"', $html2);
    }

    public function test_container_plain_class_and_inline_width_for_both_styles(): void
    {
        $plain = $this->createForm([
            'title'    => 'Plain wide',
            'blocks'   => '<!-- wp:dono/donation-amount {"presets":[1000]} /-->',
            'settings' => ['container' => ['style' => 'plain', 'width' => 900]],
        ]);
        $html = do_shortcode('[dono_donation_form slug="' . $plain['slug'] . '"]');
        $this->assertStringContainsString('dono-donation-form--plain', $html);
        $this->assertStringNotContainsString('dono-donation-form--framed', $html);
        $this->assertStringContainsString('--dono-form-max-width:900px', $html);
        $this->assertStringContainsString('max-width:900px', $html, 'inline max-width beats theme overrides');

        $framed = $this->createForm([
            'title'    => 'Framed wide',
            'blocks'   => '<!-- wp:dono/donation-amount {"presets":[1000]} /-->',
            'settings' => ['container' => ['style' => 'frame', 'width' => 1000]],
        ]);
        $html2 = do_shortcode('[dono_donation_form slug="' . $framed['slug'] . '"]');
        $this->assertStringContainsString('dono-donation-form--framed', $html2);
        $this->assertStringContainsString('max-width:1000px', $html2, 'width applies regardless of frame/plain');
    }

    public function test_submit_button_alignment_reaches_runtime_config(): void
    {
        $f = $this->createForm([
            'title'  => 'Aligned',
            'blocks' => "<!-- wp:dono/donation-amount {\"presets\":[1000]} /-->\n\n"
                . '<!-- wp:dono/submit-button {"label":"Give","align":"center"} /-->',
        ]);
        $html = do_shortcode('[dono_donation_form slug="' . $f['slug'] . '"]');
        preg_match('/data-dono-form-config>(.+?)<\/script>/s', $html, $m);
        $cfg = json_decode($m[1], true);
        $submit = null;
        foreach ($cfg['steps'] as $s) {
            if ($s['type'] === 'submit') { $submit = $s; break; }
        }
        $this->assertSame('center', $submit['align']);

        $bad = $this->createForm([
            'title'  => 'Bad align',
            'blocks' => '<!-- wp:dono/submit-button {"align":"sideways"} /-->',
        ]);
        $html2 = do_shortcode('[dono_donation_form slug="' . $bad['slug'] . '"]');
        preg_match('/data-dono-form-config>(.+?)<\/script>/s', $html2, $m2);
        $cfg2 = json_decode($m2[1], true);
        $sb = null;
        foreach ($cfg2['steps'] as $s) {
            if ($s['type'] === 'submit') { $sb = $s; break; }
        }
        $this->assertSame('left', $sb['align'], 'invalid align coerces to left');
    }

    public function test_divider_block_renders_and_emits_decoration(): void
    {
        $f = $this->createForm([
            'title'  => 'With divider',
            'blocks' => "<!-- wp:dono/donation-amount {\"presets\":[1000]} /-->\n\n"
                . '<!-- wp:dono/divider {"marginTop":40,"marginBottom":8,"thickness":3,"color":"#cccccc"} /-->'
                . "\n\n<!-- wp:dono/submit-button {\"label\":\"Give\"} /-->",
        ]);
        $html = do_shortcode('[dono_donation_form slug="' . $f['slug'] . '"]');

        // No-JS server render.
        $this->assertStringContainsString('dono-divider', $html);
        $this->assertStringContainsString('border-top:3px solid #cccccc', $html);

        // Runtime config decoration.
        preg_match('/data-dono-form-config>(.+?)<\/script>/s', $html, $m);
        $cfg = json_decode($m[1], true);
        $divider = null;
        foreach ($cfg['steps'] as $s) {
            foreach (($s['items'] ?? []) as $d) {
                if (($d['t'] ?? '') === 'deco' && ($d['kind'] ?? '') === 'divider') { $divider = $d; break 2; }
            }
        }
        $this->assertNotNull($divider, 'divider decoration present in config');
        $this->assertSame(40, $divider['marginTop']);
        $this->assertSame(3, $divider['thickness']);
        $this->assertSame('#cccccc', $divider['color']);
    }

    public function test_row_block_carries_gap_and_unit_into_runtime_config(): void
    {
        $f = $this->createForm([
            'title'  => 'Row gap',
            'blocks' => <<<BLOCKS
<!-- wp:dono/row {"columns":2,"gap":24,"gapUnit":"rem"} -->
<!-- wp:dono/name /-->
<!-- wp:dono/email /-->
<!-- /wp:dono/row -->

<!-- wp:dono/submit-button {"label":"Give"} /-->
BLOCKS,
        ]);

        $html = do_shortcode('[dono_donation_form slug="' . $f['slug'] . '"]');
        preg_match('/data-dono-form-config>(.+?)<\/script>/s', $html, $m);
        $cfg = json_decode($m[1], true);

        $donor = null;
        foreach ($cfg['steps'] as $s) {
            if ($s['type'] === 'donor') { $donor = $s; break; }
        }
        $this->assertNotNull($donor, 'row produces a donor step');
        $donorFields = $this->stepFields($donor);
        $this->assertNotEmpty($donorFields);

        foreach ($donorFields as $field) {
            $this->assertArrayHasKey('row', $field, 'row-wrapped field carries its row');
            $this->assertSame(2, $field['row']['columns']);
            $this->assertSame(24, $field['row']['gap'], 'gap reaches the runtime (was dropped before)');
            $this->assertSame('rem', $field['row']['gapUnit']);
        }
        // Both fields share one row grouping.
        $this->assertSame(
            $donorFields[0]['row']['id'],
            $donorFields[1]['row']['id']
        );
    }

    public function test_row_block_clamps_out_of_range_gap_and_bad_unit(): void
    {
        $f = $this->createForm([
            'title'  => 'Row gap clamp',
            'blocks' => <<<BLOCKS
<!-- wp:dono/row {"columns":2,"gap":999,"gapUnit":"parsecs"} -->
<!-- wp:dono/name /-->
<!-- wp:dono/email /-->
<!-- /wp:dono/row -->
BLOCKS,
        ]);

        $html = do_shortcode('[dono_donation_form slug="' . $f['slug'] . '"]');
        preg_match('/data-dono-form-config>(.+?)<\/script>/s', $html, $m);
        $cfg = json_decode($m[1], true);

        $donor = null;
        foreach ($cfg['steps'] as $s) {
            if ($s['type'] === 'donor') { $donor = $s; break; }
        }
        $this->assertNotNull($donor);
        $row = $this->stepFields($donor)[0]['row'];
        $this->assertSame(12, $row['gap'], 'out-of-range gap falls back to the 12 default');
        $this->assertSame('px', $row['gapUnit'], 'unknown unit falls back to px');
    }

    /** Field-items in a step under the interleaved items model. */
    private function stepFields(array $step): array
    {
        return array_values(array_filter(
            $step['items'] ?? [],
            static fn (array $it): bool => ($it['t'] ?? '') === 'field'
        ));
    }

    private function createCampaign(): int
    {
        $req = new WP_REST_Request('POST', '/dono/v1/admin/campaigns');
        $req->set_header('content-type', 'application/json');
        $req->set_body(json_encode(['title' => 'Test campaign', 'status' => 'published']));
        return (int) rest_do_request($req)->get_data()['id'];
    }

    /**
     * Create as draft via REST then mark published on the row so test blocks
     * stay minimal. Going through the published REST path would trip the
     * publish-readiness check (Name + Email required) on most fixtures.
     *
     * @param array{title:string,blocks?:string,status?:string} $input
     */
    public function test_config_json_cannot_break_out_of_its_script_block(): void
    {
        $created = $this->createForm([
            'title'  => 'XSS probe',
            'blocks' => '<!-- wp:dono/submit-button {"label":"Give"} /-->',
        ]);

        // Store a hostile thank-you message directly (simulating any author or
        // stored value that reaches the inline config JSON).
        $form = \Dono\Forms\Form::query()->find('id', (int) $created['id']);
        $form->settings = array_merge(
            is_array($form->settings) ? $form->settings : [],
            [ 'thank_you_message' => '</script><img src=x onerror=alert(1)>' ]
        );
        $form->save();

        $html = do_shortcode('[dono_donation_form slug="' . $created['slug'] . '"]');

        // Exactly one </script> (the legit config-block closer); the payload's
        // </script> and < are hex-escaped, so no breakout and no raw <img.
        $this->assertSame(1, substr_count(strtolower($html), '</script>'),
            'config JSON must not let a string break out of its <script> block');
        $this->assertStringNotContainsString('<img', $html, 'angle brackets in config strings must be escaped');
    }

    private function createForm(array $input): array
    {
        $req = new WP_REST_Request('POST', '/dono/v1/admin/forms');
        $req->set_header('content-type', 'application/json');
        $req->set_body(json_encode($input + ['campaign_id' => $this->campaignId]));
        $created = rest_do_request($req)->get_data();

        $form = \Dono\Forms\Form::query()->find('id', (int) $created['id']);
        $form->status = 'published';
        $form->save();

        $created['status'] = 'published';
        return $created;
    }
}
