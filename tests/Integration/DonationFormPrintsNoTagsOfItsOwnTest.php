<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Campaigns\CampaignRepository;
use Gratora\Campaigns\Styling\CampaignStyleResolver;
use Gratora\Donations\AntiSpamGuard;
use Gratora\Forms\Form;
use Gratora\Forms\FormRepository;
use Gratora\Forms\Shortcode\DonationFormShortcode;
use Gratora\Foundation\Plugin;
use Gratora\Gateways\GatewayManager;
use Gratora\Gateways\TestMode;
use WP_HTML_Tag_Processor;
use WP_REST_Request;

/**
 * A plugin may not write its own script, style or link tags into a page: its
 * assets go through the queue, where a theme, a cache or a CSP plugin can see
 * and change them. The form's markup carries one element core prints for it,
 * the JSON config the runtime reads.
 */
final class DonationFormPrintsNoTagsOfItsOwnTest extends IntegrationTestCase
{
    private const RUNTIME  = 'gratora-donation-form-runtime';
    private const FAILSAFE = 'gratora-form-cloak-failsafe';

    private string $slug;

    protected function setUp(): void
    {
        parent::setUp();

        $req = new WP_REST_Request('POST', '/gratora/v1/admin/campaigns');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode(['title' => 'Tag probe', 'status' => 'published']));
        $campaignId = (int) rest_do_request($req)->get_data()['id'];

        $req = new WP_REST_Request('POST', '/gratora/v1/admin/forms');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode([
            'title'       => 'Tag probe form',
            'campaign_id' => $campaignId,
            'blocks'      => '<!-- wp:gratora/donation-amount {"presets":[1000]} /--><!-- wp:gratora/submit-button /-->',
        ]));
        $created = rest_do_request($req)->get_data();

        $form         = Form::query()->find('id', (int) $created['id']);
        $form->status = 'published';
        $form->save();

        $this->slug = (string) $form->slug;

        // The script queue outlives a test, and render() only queues what is
        // not queued already.
        wp_dequeue_script(self::RUNTIME);
        wp_dequeue_style(self::RUNTIME);
        wp_dequeue_script(self::FAILSAFE);
    }

    /** @return list<string> every script, style and link element, in order */
    private function assetTagsIn(string $html): array
    {
        $processor = new WP_HTML_Tag_Processor($html);
        $tags      = [];

        while ($processor->next_tag()) {
            $tag = (string) $processor->get_tag();
            if (! in_array($tag, ['SCRIPT', 'STYLE', 'LINK'], true)) {
                continue;
            }

            $tags[] = $processor->get_attribute('data-gratora-form-config') !== null
                ? $tag . ' ' . (string) $processor->get_attribute('type') . ' config'
                : $tag;
        }

        return $tags;
    }

    /** A fresh instance, so no render in an earlier test counts for this one. */
    private function render(): string
    {
        $c = Plugin::instance()->container;

        $shortcode = new DonationFormShortcode(
            $c->get(FormRepository::class),
            $c->get(CampaignStyleResolver::class),
            $c->get(CampaignRepository::class),
            $c->get(AntiSpamGuard::class),
            $c->get(GatewayManager::class),
            $c->get(TestMode::class),
        );

        return $shortcode->render(['slug' => $this->slug]);
    }

    public function test_the_only_asset_tag_in_the_form_is_the_config_core_prints(): void
    {
        $this->assertSame(['SCRIPT application/json config'], $this->assetTagsIn($this->render()));
    }

    /**
     * A form in a block on a classic theme, or inside the donate button's
     * modal, renders after the head has printed.
     */
    public function test_a_form_rendered_after_the_head_queues_its_stylesheet_instead_of_writing_one(): void
    {
        // WP_UnitTestCase puts $wp_actions back after every test.
        $GLOBALS['wp_actions']['wp_head'] = 1;

        $html = $this->render();

        $this->assertSame(['SCRIPT application/json config'], $this->assetTagsIn($html));
        $this->assertTrue(wp_style_is(self::RUNTIME, 'enqueued'), 'the stylesheet goes through the queue, where core hoists it into the head');
    }

    public function test_a_form_on_the_page_queues_the_cloak_failsafe_as_an_inline_script_core_prints(): void
    {
        $this->render();

        $this->assertTrue(wp_script_is(self::FAILSAFE, 'enqueued'));

        ob_start();
        wp_scripts()->do_item(self::FAILSAFE);
        $printed = (string) ob_get_clean();

        $processor = new WP_HTML_Tag_Processor($printed);
        $this->assertTrue($processor->next_tag('SCRIPT'), 'core prints the failsafe');
        $this->assertNull($processor->get_attribute('src'), 'inline, so it still runs when the runtime bundle never arrives');

        $code = $processor->get_modifiable_text();
        $this->assertStringContainsString('data-gratora-ready', $code, 'it reveals a form the runtime never marked ready');
        $this->assertStringContainsString('4000', $code, 'after the same four seconds the stylesheet waits');
    }
}
