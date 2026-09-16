<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Forms\Blocks\FundPickerBlock;
use WP_REST_Request;

/**
 * Fund descriptions are written once under Donations, then shown on every form
 * that offers the fund. An org running a form where the names are enough needs
 * to turn the text off for that form without editing the fund, and the picker
 * renders in three places, so all of them have to agree.
 */
final class FundDescriptionToggleTest extends IntegrationTestCase
{
    private const DESCRIPTION = 'Clean water projects in the eastern districts';

    private int $campaignId;

    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->campaignId = $this->createCampaign();
        $this->createFund();
    }

    private function createCampaign(): int
    {
        $req = new WP_REST_Request('POST', '/gratora/v1/admin/campaigns');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode(['title' => 'Description campaign', 'status' => 'published']));

        return (int) rest_do_request($req)->get_data()['id'];
    }

    private function createFund(): void
    {
        $req = new WP_REST_Request('POST', '/gratora/v1/admin/funds');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode([
            'code'        => 'water-sanitation',
            'name'        => 'Water and Sanitation',
            'description' => self::DESCRIPTION,
        ]));
        $res = rest_do_request($req);

        $this->assertSame(201, $res->get_status(), 'the fund fixture has to exist for any of this to mean anything');
        $this->assertSame(self::DESCRIPTION, $res->get_data()['description'], 'and it has to carry the description');
    }

    private function formConfig(string $attrs): string
    {
        $req = new WP_REST_Request('POST', '/gratora/v1/admin/forms');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode([
            'title'       => 'Pick a fund',
            'campaign_id' => $this->campaignId,
            'blocks'      => '<!-- wp:gratora/fund-picker ' . $attrs . ' /-->'
                . '<!-- wp:gratora/submit-button {"label":"Give"} /-->',
        ]));
        $created = rest_do_request($req)->get_data();

        $form = \Gratora\Forms\Form::query()->find('id', (int) $created['id']);
        $form->status = 'published';
        $form->save();

        $config = $this->formConfigJsonIn(do_shortcode('[gratora_donation_form slug="' . $created['slug'] . '"]'));

        $this->assertNotSame('', $config, 'the form rendered no runtime config, so nothing below is being tested');
        $this->assertStringContainsString('Water and Sanitation', $config, 'the fund itself has to be on the form');

        return $config;
    }

    public function test_the_donor_reads_the_description_unless_the_org_turns_it_off(): void
    {
        $this->assertStringContainsString(self::DESCRIPTION, $this->formConfig(''));
    }

    public function test_turning_it_off_drops_the_text_and_keeps_the_fund(): void
    {
        $this->assertStringNotContainsString(
            self::DESCRIPTION,
            $this->formConfig('{"showDescriptions":false}'),
            'the description must not reach the page at all, not merely be hidden by CSS'
        );
    }

    public function test_the_no_specific_fund_tile_loses_its_text_on_the_same_switch(): void
    {
        $tile = '"allowEmpty":true,"emptyLabel":"Wherever you need it","emptyDescription":"We will put it where it does the most good"';

        $on = $this->formConfig('{' . $tile . '}');
        $this->assertStringContainsString('We will put it where it does the most good', $on);

        $off = $this->formConfig('{' . $tile . ',"showDescriptions":false}');
        $this->assertStringContainsString('Wherever you need it', $off, 'the tile itself stays offered');
        $this->assertStringNotContainsString('We will put it where it does the most good', $off);
    }

    public function test_the_block_renders_the_same_way_the_form_does(): void
    {
        $block = new FundPickerBlock();

        $tile = ['allowEmpty' => true, 'emptyLabel' => 'Wherever you need it', 'emptyDescription' => 'Put it where it helps most'];

        $on = $block->render($tile, '');
        $this->assertStringContainsString(self::DESCRIPTION, $on);
        $this->assertStringContainsString('Put it where it helps most', $on);

        $off = $block->render($tile + ['showDescriptions' => false], '');
        $this->assertStringContainsString('Water and Sanitation', $off);
        $this->assertStringContainsString('Wherever you need it', $off);
        $this->assertStringNotContainsString(self::DESCRIPTION, $off);
        $this->assertStringNotContainsString('Put it where it helps most', $off);
    }

    public function test_the_editor_keeps_its_copy_so_the_switch_can_be_turned_back_on(): void
    {
        $options = rest_do_request(new WP_REST_Request('GET', '/gratora/v1/admin/forms/funds'))->get_data();
        $water   = null;
        foreach ((array) $options as $o) {
            if (($o['label'] ?? '') === 'Water and Sanitation') {
                $water = $o;
            }
        }

        $this->assertNotNull($water, 'the editor endpoint has to offer the fund');
        $this->assertSame(self::DESCRIPTION, $water['description'], 'one form hiding descriptions cannot empty them for every other form');
    }
}
