<?php

declare(strict_types=1);

namespace GiveFlow\Tests\Integration;

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
        $req = new WP_REST_Request('POST', '/giveflow/v1/admin/campaigns');
        $req->set_header('content-type', 'application/json');
        $req->set_body(json_encode(['title' => 'Coverage campaign', 'status' => 'published']));
        $this->campaignId = (int) rest_do_request($req)->get_data()['id'];
    }

    private function configFor(string $blocks): string
    {
        $req = new WP_REST_Request('POST', '/giveflow/v1/admin/forms');
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
        $form = \GiveFlow\Forms\Form::query()->find('id', (int) $created['id']);
        $form->status = 'published';
        $form->save();

        $html = do_shortcode('[giveflow_donation_form slug="' . $slug . '"]');
        preg_match('/data-giveflow-form-config>(.+?)<\/script>/s', $html, $m);
        return (string) ($m[1] ?? '');
    }

    public function test_every_data_and_content_block_survives_into_the_config(): void
    {
        // One unique marker per block; each must reach the runtime config.
        $blocks = <<<BLOCKS
<!-- wp:giveflow/heading {"text":"MK_HEADING"} /-->
<!-- wp:giveflow/paragraph {"text":"MK_PARAGRAPH"} /-->
<!-- wp:giveflow/html {"content":"<span>MK_HTML</span>"} /-->
<!-- wp:giveflow/donation-amount {"presets":[91234],"currency":"EUR"} /-->
<!-- wp:giveflow/name /-->
<!-- wp:giveflow/email {"label":"MK_EMAIL"} /-->
<!-- wp:giveflow/country {"label":"MK_COUNTRY"} /-->
<!-- wp:giveflow/phone {"label":"MK_PHONE"} /-->
<!-- wp:giveflow/comment {"label":"MK_COMMENT"} /-->
<!-- wp:giveflow/address {"label":"MK_ADDRESS"} /-->
<!-- wp:giveflow/anonymous-toggle {"label":"MK_ANON"} /-->
<!-- wp:giveflow/cover-fees {"label":"MK_COVERFEES"} /-->
<!-- wp:giveflow/recurring-toggle {"label":"MK_RECURRING","frequencies":["one-time","monthly"]} /-->
<!-- wp:giveflow/fund-picker {"label":"MK_FUND","allowEmpty":true} /-->
<!-- wp:giveflow/text-input {"field":"mk_text","label":"MK_TEXT"} /-->
<!-- wp:giveflow/number-input {"field":"mk_num","label":"MK_NUMBER"} /-->
<!-- wp:giveflow/date {"field":"mk_date","label":"MK_DATE"} /-->
<!-- wp:giveflow/dropdown {"field":"mk_dd","label":"MK_DROPDOWN","options":["a"]} /-->
<!-- wp:giveflow/radio {"field":"mk_radio","label":"MK_RADIO","options":["a"]} /-->
<!-- wp:giveflow/checkbox {"field":"mk_check","label":"MK_CHECKBOX"} /-->
<!-- wp:giveflow/multi-select {"field":"mk_ms","label":"MK_MULTISELECT","options":["a"]} /-->
<!-- wp:giveflow/hidden {"field":"mk_hidden","defaultValue":"MK_HIDDEN"} /-->
<!-- wp:giveflow/submit-button {"label":"MK_SUBMIT"} /-->
BLOCKS;

        $config = $this->configFor($blocks);
        $this->assertNotSame('', $config, 'form must emit a runtime config');

        // giveflow/name is a two-part field (firstLabel/lastLabel), no single
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
<!-- wp:giveflow/steps -->
<!-- wp:giveflow/step {"label":"MK_STEP_ONE"} -->
<!-- wp:giveflow/donation-amount {"presets":[500]} /-->
<!-- /wp:giveflow/step -->
<!-- wp:giveflow/step {"label":"MK_STEP_TWO"} -->
<!-- wp:giveflow/columns {"columns":2,"gap":20,"gapUnit":"px"} -->
<!-- wp:giveflow/heading {"text":"MK_IN_COLUMNS"} /-->
<!-- /wp:giveflow/columns -->
<!-- wp:giveflow/row {"columns":2,"gap":14,"gapUnit":"px"} -->
<!-- wp:giveflow/name /-->
<!-- wp:giveflow/email /-->
<!-- /wp:giveflow/row -->
<!-- /wp:giveflow/step -->
<!-- /wp:giveflow/steps -->

<!-- wp:giveflow/submit-button {"label":"MK_SUBMIT2"} /-->
BLOCKS;

        $config = $this->configFor($blocks);
        $cfg = json_decode($config, true);
        $this->assertIsArray($cfg);

        // Multi-step: more than one page index across the steps.
        $pages = array_unique(array_map(
            static fn ($s) => $s['page'] ?? 0,
            $cfg['steps'] ?? []
        ));
        $this->assertGreaterThan(1, count($pages), 'giveflow/steps must yield multiple pages');

        // Columns container and its nested child both survived.
        $this->assertStringContainsString('giveflow-block--columns', $config);
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
            '<!-- wp:giveflow/donation-amount /--><!-- wp:giveflow/name /--><!-- wp:giveflow/email /-->'
            . '<!-- wp:giveflow/fund-picker {"label":"MK_ORPHAN_FUND"} /-->'
        );

        $this->assertNotSame('', $config);
        $this->assertStringNotContainsString('MK_ORPHAN_FUND', $config, 'an empty fund picker must not reach donors');
        $this->assertStringNotContainsString('"kind":"fund"', $config);
    }

    public function test_content_only_wizard_step_keeps_its_content_on_its_own_page(): void
    {
        $blocks = <<<BLOCKS
<!-- wp:giveflow/steps -->
<!-- wp:giveflow/step {"title":"Intro"} -->
<!-- wp:giveflow/heading {"text":"MK_INTRO"} /-->
<!-- /wp:giveflow/step -->
<!-- wp:giveflow/step {"title":"Give"} -->
<!-- wp:giveflow/donation-amount /-->
<!-- wp:giveflow/email /-->
<!-- wp:giveflow/submit-button /-->
<!-- /wp:giveflow/step -->
<!-- /wp:giveflow/steps -->
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

    /**
     * A step left empty in the builder must not publish a blank wizard page.
     *
     * Static analysis reads the check that drops it as dead, because the step
     * list and the page counter are mutated by reference through a closure
     * captured by value, which it cannot follow. This settles it by running
     * the pipeline instead of reasoning about it.
     */
    public function test_a_step_left_empty_in_the_builder_is_dropped(): void
    {
        $blocks = <<<BLOCKS
<!-- wp:giveflow/steps -->
<!-- wp:giveflow/step {"title":"Give"} -->
<!-- wp:giveflow/donation-amount /-->
<!-- wp:giveflow/email /-->
<!-- /wp:giveflow/step -->
<!-- wp:giveflow/step {"title":"MK_EMPTY_STEP"} -->
<!-- /wp:giveflow/step -->
<!-- wp:giveflow/step {"title":"Finish"} -->
<!-- wp:giveflow/submit-button /-->
<!-- /wp:giveflow/step -->
<!-- /wp:giveflow/steps -->
BLOCKS;

        $raw = $this->configFor($blocks);
        $cfg = json_decode($raw, true);
        $this->assertIsArray($cfg);

        $this->assertStringNotContainsString(
            'MK_EMPTY_STEP',
            $raw,
            'an empty builder step published a blank wizard page'
        );

        // The pages that survive have to stay contiguous, or the wizard counts
        // to a page that is not there.
        $pages = array_values(array_unique(array_map(
            static fn (array $s): int => (int) ($s['page'] ?? 0),
            $cfg['steps'] ?? []
        )));
        sort($pages);
        $this->assertSame(range(0, count($pages) - 1), $pages, 'the surviving pages are not contiguous');
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
            '<!-- wp:giveflow/paragraph {"text":"MK_ALPHA"} /-->'
            . '<!-- wp:giveflow/name /-->'
            . '<!-- wp:giveflow/paragraph {"text":"MK_BETA"} /-->'
            . '<!-- wp:giveflow/email /-->'
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
            '<!-- wp:giveflow/donation-amount /--><!-- wp:giveflow/recurring-toggle /-->'
            . '<!-- wp:giveflow/name /--><!-- wp:giveflow/email /--><!-- wp:giveflow/submit-button /-->'
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
            '<!-- wp:giveflow/donation-amount /--><!-- wp:giveflow/name /--><!-- wp:giveflow/email /-->'
            . '<!-- wp:giveflow/privacy-notice /--><!-- wp:giveflow/submit-button /-->'
        );
        $this->assertNotSame('', $config);
        $this->assertStringNotContainsString(
            'wp:giveflow/privacy-notice',
            $config,
            'a bare privacy notice must render, not leak an unparsed block comment'
        );
    }

    public function test_a_field_row_stays_grouped_between_content(): void
    {
        $cfg = json_decode($this->configFor(
            '<!-- wp:giveflow/row {"columns":2} -->'
            . '<!-- wp:giveflow/name /-->'
            . '<!-- wp:giveflow/email /-->'
            . '<!-- /wp:giveflow/row -->'
            . '<!-- wp:giveflow/divider /-->'
            . '<!-- wp:giveflow/phone /-->'
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
<!-- wp:giveflow/heading {"text":"MK_PREAMBLE"} /-->
<!-- wp:giveflow/steps -->
<!-- wp:giveflow/step {"title":"Give"} -->
<!-- wp:giveflow/donation-amount /-->
<!-- /wp:giveflow/step -->
<!-- wp:giveflow/step {"title":"You"} -->
<!-- wp:giveflow/email /-->
<!-- wp:giveflow/submit-button /-->
<!-- /wp:giveflow/step -->
<!-- /wp:giveflow/steps -->
BLOCKS;

        $cfg = json_decode($this->configFor($blocks), true);
        $this->assertIsArray($cfg);

        $this->assertStringContainsString('MK_PREAMBLE', json_encode($cfg['preamble'] ?? []), 'pre-wizard content lifts into the preamble');
        foreach ($cfg['steps'] ?? [] as $s) {
            $this->assertStringNotContainsString('MK_PREAMBLE', json_encode($s['items'] ?? []), 'preamble content must not collapse onto a wizard page');
        }
    }
}
