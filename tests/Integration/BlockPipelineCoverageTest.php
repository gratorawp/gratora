<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use WP_REST_Request;

/**
 * Regression net for the class of bug the audit found (a block that is
 * inserterable but silently dropped by buildSteps, e.g. the old file-upload
 * stub / row gap). Every data/content block carries a unique marker; each
 * must survive into the runtime config the donor app reads.
 */
final class BlockPipelineCoverageTest extends IntegrationTestCase
{
    private int $campaignId;

    protected function setUp(): void
    {
        parent::setUp();
        $req = new WP_REST_Request('POST', '/dono/v1/admin/campaigns');
        $req->set_header('content-type', 'application/json');
        $req->set_body(json_encode(['title' => 'Coverage campaign', 'status' => 'published']));
        $this->campaignId = (int) rest_do_request($req)->get_data()['id'];
    }

    private function configFor(string $blocks): string
    {
        $req = new WP_REST_Request('POST', '/dono/v1/admin/forms');
        $req->set_header('content-type', 'application/json');
        $req->set_body(json_encode([
            'title'       => 'Coverage form',
            'blocks'      => $blocks,
            'campaign_id' => $this->campaignId,
        ]));
        $created = rest_do_request($req)->get_data();
        $slug    = $created['slug'];

        // Test blocks are minimal and would fail the publish-readiness check
        // (Name + Email required). Bypass via a direct model save.
        $form = \Dono\Forms\Form::query()->find('id', (int) $created['id']);
        $form->status = 'published';
        $form->save();

        $html = do_shortcode('[dono_donation_form slug="' . $slug . '"]');
        preg_match('/data-dono-form-config>(.+?)<\/script>/s', $html, $m);
        return (string) ($m[1] ?? '');
    }

    public function test_every_data_and_content_block_survives_into_the_config(): void
    {
        // One unique marker per block; each must reach the runtime config.
        $blocks = <<<BLOCKS
<!-- wp:dono/heading {"text":"MK_HEADING"} /-->
<!-- wp:dono/paragraph {"text":"MK_PARAGRAPH"} /-->
<!-- wp:dono/html {"content":"<span>MK_HTML</span>"} /-->
<!-- wp:dono/donation-amount {"presets":[91234],"currency":"EUR"} /-->
<!-- wp:dono/name /-->
<!-- wp:dono/email {"label":"MK_EMAIL"} /-->
<!-- wp:dono/country {"label":"MK_COUNTRY"} /-->
<!-- wp:dono/phone {"label":"MK_PHONE"} /-->
<!-- wp:dono/comment {"label":"MK_COMMENT"} /-->
<!-- wp:dono/address {"label":"MK_ADDRESS"} /-->
<!-- wp:dono/anonymous-toggle {"label":"MK_ANON"} /-->
<!-- wp:dono/cover-fees {"label":"MK_COVERFEES"} /-->
<!-- wp:dono/recurring-toggle {"label":"MK_RECURRING","frequencies":["one-time","monthly"]} /-->
<!-- wp:dono/fund-picker {"label":"MK_FUND","allowEmpty":true} /-->
<!-- wp:dono/text-input {"field":"mk_text","label":"MK_TEXT"} /-->
<!-- wp:dono/number-input {"field":"mk_num","label":"MK_NUMBER"} /-->
<!-- wp:dono/date {"field":"mk_date","label":"MK_DATE"} /-->
<!-- wp:dono/dropdown {"field":"mk_dd","label":"MK_DROPDOWN","options":["a"]} /-->
<!-- wp:dono/radio {"field":"mk_radio","label":"MK_RADIO","options":["a"]} /-->
<!-- wp:dono/checkbox {"field":"mk_check","label":"MK_CHECKBOX"} /-->
<!-- wp:dono/multi-select {"field":"mk_ms","label":"MK_MULTISELECT","options":["a"]} /-->
<!-- wp:dono/hidden {"field":"mk_hidden","defaultValue":"MK_HIDDEN"} /-->
<!-- wp:dono/submit-button {"label":"MK_SUBMIT"} /-->
BLOCKS;

        $config = $this->configFor($blocks);
        $this->assertNotSame('', $config, 'form must emit a runtime config');

        // dono/name is a two-part field (firstLabel/lastLabel), no single
        // label; assert it survived structurally instead of via a marker.
        $this->assertStringContainsString( '"kind":"name"', $config );

        $markers = [
            'MK_HEADING', 'MK_PARAGRAPH', 'MK_HTML', '91234',
            'MK_EMAIL', 'MK_COUNTRY', 'MK_PHONE', 'MK_COMMENT',
            'MK_ADDRESS', 'MK_ANON', 'MK_COVERFEES',
            'MK_RECURRING', 'MK_FUND', 'MK_TEXT', 'MK_NUMBER', 'MK_DATE',
            'MK_DROPDOWN', 'MK_RADIO', 'MK_CHECKBOX', 'MK_MULTISELECT',
            'MK_HIDDEN', 'MK_SUBMIT',
        ];
        foreach ($markers as $marker) {
            $this->assertStringContainsString(
                $marker,
                $config,
                "Block marker {$marker} was dropped from the runtime config."
            );
        }
    }

    public function test_layout_containers_and_multi_step_survive(): void
    {
        $blocks = <<<BLOCKS
<!-- wp:dono/steps -->
<!-- wp:dono/step {"label":"MK_STEP_ONE"} -->
<!-- wp:dono/donation-amount {"presets":[500]} /-->
<!-- /wp:dono/step -->
<!-- wp:dono/step {"label":"MK_STEP_TWO"} -->
<!-- wp:dono/columns {"columns":2,"gap":20,"gapUnit":"px"} -->
<!-- wp:dono/heading {"text":"MK_IN_COLUMNS"} /-->
<!-- /wp:dono/columns -->
<!-- wp:dono/row {"columns":2,"gap":14,"gapUnit":"px"} -->
<!-- wp:dono/name /-->
<!-- wp:dono/email /-->
<!-- /wp:dono/row -->
<!-- /wp:dono/step -->
<!-- /wp:dono/steps -->

<!-- wp:dono/submit-button {"label":"MK_SUBMIT2"} /-->
BLOCKS;

        $config = $this->configFor($blocks);
        $cfg = json_decode($config, true);
        $this->assertIsArray($cfg);

        // Multi-step: more than one page index across the steps.
        $pages = array_unique(array_map(
            static fn ($s) => $s['page'] ?? 0,
            $cfg['steps'] ?? []
        ));
        $this->assertGreaterThan(1, count($pages), 'dono/steps must yield multiple pages');

        // Columns container and its nested child both survived.
        $this->assertStringContainsString('dono-block--columns', $config);
        $this->assertStringContainsString('MK_IN_COLUMNS', $config);

        // Row gap reached the config (the audit's row gap/gapUnit fix).
        $this->assertStringContainsString('"gap":14', $config);
        $this->assertStringContainsString('MK_SUBMIT2', $config);
    }

    public function test_fund_picker_with_no_funds_and_no_empty_option_is_dropped(): void
    {
        // With no selectable funds and no explicit no-fund tile, the picker
        // would be an orphaned empty field, so the walker omits it entirely.
        $config = $this->configFor(
            '<!-- wp:dono/donation-amount /--><!-- wp:dono/name /--><!-- wp:dono/email /-->'
            . '<!-- wp:dono/fund-picker {"label":"MK_ORPHAN_FUND"} /-->'
        );

        $this->assertNotSame('', $config);
        $this->assertStringNotContainsString('MK_ORPHAN_FUND', $config, 'an empty fund picker must not reach donors');
        $this->assertStringNotContainsString('"kind":"fund"', $config);
    }

    public function test_content_only_wizard_step_keeps_its_content_on_its_own_page(): void
    {
        $blocks = <<<BLOCKS
<!-- wp:dono/steps -->
<!-- wp:dono/step {"title":"Intro"} -->
<!-- wp:dono/heading {"text":"MK_INTRO"} /-->
<!-- /wp:dono/step -->
<!-- wp:dono/step {"title":"Give"} -->
<!-- wp:dono/donation-amount /-->
<!-- wp:dono/email /-->
<!-- wp:dono/submit-button /-->
<!-- /wp:dono/step -->
<!-- /wp:dono/steps -->
BLOCKS;

        $cfg = json_decode($this->configFor($blocks), true);
        $this->assertIsArray($cfg);

        // The content-only first page has a step carrying its heading (not blank,
        // not leaked onto page 1).
        $introOnPage0 = false;
        foreach ($cfg['steps'] ?? [] as $s) {
            $body = json_encode($s['items'] ?? []);
            if ((int) ($s['page'] ?? -1) === 0 && str_contains((string) $body, 'MK_INTRO')) {
                $introOnPage0 = true;
            }
            if ((int) ($s['page'] ?? -1) !== 0) {
                $this->assertStringNotContainsString('MK_INTRO', (string) $body, 'intro must not leak onto a later page');
            }
        }
        $this->assertTrue($introOnPage0, 'the content-only step renders its content on page 0');
    }

    /** The first donor step's ordered items. */
    private function donorItems(array $cfg): array
    {
        foreach ($cfg['steps'] ?? [] as $s) {
            if (($s['type'] ?? '') === 'donor') {
                return array_values($s['items'] ?? []);
            }
        }
        return [];
    }

    public function test_fields_and_content_interleave_in_authored_order(): void
    {
        $cfg = json_decode($this->configFor(
            '<!-- wp:dono/paragraph {"text":"MK_ALPHA"} /-->'
            . '<!-- wp:dono/name /-->'
            . '<!-- wp:dono/paragraph {"text":"MK_BETA"} /-->'
            . '<!-- wp:dono/email /-->'
        ), true);
        $this->assertIsArray($cfg);

        $items = $this->donorItems($cfg);
        // Reduce to a compact signature of what each item is.
        $sig = array_map(static function (array $it): string {
            $t = (string) ($it['t'] ?? '');
            if ($t === 'field') return 'field:' . ($it['kind'] ?? '');
            $mark = str_contains(json_encode($it), 'MK_ALPHA') ? 'ALPHA'
                : (str_contains(json_encode($it), 'MK_BETA') ? 'BETA' : ($it['kind'] ?? ''));
            return 'deco:' . $mark;
        }, $items);

        $this->assertSame(
            ['deco:ALPHA', 'field:name', 'deco:BETA', 'field:email'],
            $sig,
            'fields and content must keep the order the admin authored them in'
        );
    }

    public function test_untouched_recurring_toggle_still_offers_frequencies(): void
    {
        // Gutenberg strips the frequencies attr when it equals the registered
        // default, so an untouched toggle serializes with no attrs. The walker
        // must still emit a frequency picker (otherwise recurring is silently off).
        $cfg = json_decode($this->configFor(
            '<!-- wp:dono/donation-amount /--><!-- wp:dono/recurring-toggle /-->'
            . '<!-- wp:dono/name /--><!-- wp:dono/email /--><!-- wp:dono/submit-button /-->'
        ), true);
        $this->assertIsArray($cfg);

        $kinds = array_map(static fn ($it) => $it['kind'] ?? '', $this->donorItems($cfg));
        $this->assertContains('frequency', $kinds, 'an untouched recurring toggle must still offer a frequency choice');
    }

    public function test_untouched_privacy_notice_parses_into_the_config(): void
    {
        // Empty attrs must encode as {} not []; [] leaves the block comment
        // unparsed and the notice silently disappears from the mounted form.
        $config = $this->configFor(
            '<!-- wp:dono/donation-amount /--><!-- wp:dono/name /--><!-- wp:dono/email /-->'
            . '<!-- wp:dono/privacy-notice /--><!-- wp:dono/submit-button /-->'
        );
        $this->assertNotSame('', $config);
        $this->assertStringNotContainsString(
            'wp:dono/privacy-notice',
            $config,
            'a bare privacy notice must render, not leak an unparsed block comment'
        );
    }

    public function test_a_field_row_stays_grouped_between_content(): void
    {
        $cfg = json_decode($this->configFor(
            '<!-- wp:dono/row {"columns":2} -->'
            . '<!-- wp:dono/name /-->'
            . '<!-- wp:dono/email /-->'
            . '<!-- /wp:dono/row -->'
            . '<!-- wp:dono/divider /-->'
            . '<!-- wp:dono/phone /-->'
        ), true);

        $items = $this->donorItems($cfg);
        $this->assertCount(4, $items);

        // name + email are fields sharing one row; then a divider; then phone.
        $this->assertSame('field', $items[0]['t']);
        $this->assertSame('field', $items[1]['t']);
        $this->assertNotEmpty($items[0]['row']['id'] ?? null);
        $this->assertSame($items[0]['row']['id'] ?? 'a', $items[1]['row']['id'] ?? 'b', 'row fields stay in one grid');
        $this->assertSame('deco', $items[2]['t']);
        $this->assertSame('divider', $items[2]['kind']);
        $this->assertSame('field', $items[3]['t']);
        $this->assertSame('phone', $items[3]['kind']);
    }

    public function test_root_content_before_a_wizard_is_lifted_into_the_preamble(): void
    {
        $blocks = <<<BLOCKS
<!-- wp:dono/heading {"text":"MK_PREAMBLE"} /-->
<!-- wp:dono/steps -->
<!-- wp:dono/step {"title":"Give"} -->
<!-- wp:dono/donation-amount /-->
<!-- /wp:dono/step -->
<!-- wp:dono/step {"title":"You"} -->
<!-- wp:dono/email /-->
<!-- wp:dono/submit-button /-->
<!-- /wp:dono/step -->
<!-- /wp:dono/steps -->
BLOCKS;

        $cfg = json_decode($this->configFor($blocks), true);
        $this->assertIsArray($cfg);

        $this->assertStringContainsString('MK_PREAMBLE', json_encode($cfg['preamble'] ?? []), 'pre-wizard content lifts into the preamble');
        foreach ($cfg['steps'] ?? [] as $s) {
            $this->assertStringNotContainsString('MK_PREAMBLE', json_encode($s['items'] ?? []), 'preamble content must not collapse onto a wizard page');
        }
    }
}
