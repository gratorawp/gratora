<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Campaigns\Campaign;
use Gratora\Forms\Blocks\Block;
use Gratora\Forms\Blocks\BlockRegistry;
use Gratora\Foundation\Plugin;
use WP_Block_Type_Registry;

/**
 * WordPress prints whatever a block's render callback returns. Every block in
 * the form registry, an add-on's included, is printed through a callback that
 * holds its output to the form's allow-list, which has to pass every control a
 * field draws.
 */
final class FormBlockCallbackEscapesWhatABlockReturnsTest extends IntegrationTestCase
{
    private const PROBE = 'gratora-test/probe';

    protected function tearDown(): void
    {
        if (WP_Block_Type_Registry::get_instance()->is_registered(self::PROBE)) {
            unregister_block_type(self::PROBE);
        }

        parent::tearDown();
    }

    public function test_a_block_returning_a_handler_and_a_script_renders_without_either(): void
    {
        $registry = new BlockRegistry();
        $registry->add(new class () implements Block {
            public function name(): string
            {
                return 'gratora-test/probe';
            }

            public function attributes(): array
            {
                return [];
            }

            public function render(array $attrs, string $content): string
            {
                return '<p class="probe" data-kept="yes">kept</p>'
                    . '<img src="x" onerror="window.gratoraProbe=1">'
                    . '<script>window.gratoraProbe=2</script>';
            }
        });
        $registry->register();

        $html = do_blocks('<!-- wp:gratora-test/probe /-->');

        $this->assertStringNotContainsString('onerror', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringContainsString('<p class="probe" data-kept="yes">kept</p>', $html);
    }

    public function test_every_control_a_field_draws_survives_its_callback(): void
    {
        update_option('gratora_privacy', ['privacy_policy_url' => 'https://example.org/privacy']);

        $campaign = Campaign::make();
        $campaign->title        = 'Callback Goal';
        $campaign->slug         = 'callback-goal-' . uniqid();
        $campaign->status       = 'published';
        $campaign->currency     = 'USD';
        $campaign->goal_type    = 'amount';
        $campaign->goal_cents   = 100000;
        $campaign->raised_cents = 25000;
        $campaign->created_at   = gmdate('Y-m-d H:i:s');
        $campaign->updated_at   = $campaign->created_at;
        $campaign->save();

        $fields = [
            '<!-- wp:gratora/donation-amount {"presets":[{"cents":1000,"impact":"Feeds a family","preselected":true},{"cents":2500}],"allowCustom":true,"currency":"USD"} /-->',
            '<!-- wp:gratora/currency-switcher {"currencies":["USD","EUR"],"style":"pills"} /-->',
            '<!-- wp:gratora/currency-switcher {"currencies":["USD","EUR"],"style":"dropdown","label":"Pay in"} /-->',
            '<!-- wp:gratora/goal {"campaignId":' . (int) $campaign->id . ',"showDeadline":true} /-->',
            '<!-- wp:gratora/cover-fees {"percent":2.9,"fixed":30,"defaultOn":true} /-->',
            '<!-- wp:gratora/name {"firstPlaceholder":"Ada","lastPlaceholder":"Lovelace"} /-->',
            '<!-- wp:gratora/address {"requireRegion":true} /-->',
            '<!-- wp:gratora/country {"required":true} /-->',
            '<!-- wp:gratora/text-input {"label":"Code","field":"code","maxLength":8,"pattern":"[A-Z]{3}[0-9]+","placeholder":"ABC123","helpText":"From your letter","required":true} /-->',
            '<!-- wp:gratora/number-input {"label":"Guests","field":"guests","min":1,"max":10,"step":0.5,"placeholder":"2","required":true} /-->',
            '<!-- wp:gratora/date {"label":"When","field":"when","minDate":"2026-01-01","maxDate":"2027-12-31","required":true} /-->',
            '<!-- wp:gratora/dropdown {"label":"Shirt","field":"shirt","placeholder":"Pick one","options":[{"label":"Small"},{"label":"Large","isDefault":true}],"required":true} /-->',
            '<!-- wp:gratora/radio {"label":"Size","field":"size","options":[{"label":"Small","isDefault":true},{"label":"Large"}],"required":true} /-->',
            '<!-- wp:gratora/multi-select {"label":"Colours","field":"colours","options":[{"label":"Red"},{"label":"Blue","isDefault":true}],"required":true,"minSelections":1,"maxSelections":2} /-->',
            '<!-- wp:gratora/checkbox {"label":"Keep me posted","field":"posted","defaultOn":true,"required":true} /-->',
            '<!-- wp:gratora/comment {"required":true} /-->',
            '<!-- wp:gratora/terms {"terms":"Be kind.","linkUrl":"https://example.org/terms"} /-->',
            '<!-- wp:gratora/privacy-notice /-->',
            '<!-- wp:gratora/divider {"thickness":3} /-->',
            '<!-- wp:gratora/section {"background":"rgba(12,34,56,0.5)","shadow":"0 4px 12px rgba(0,0,0,0.1)","border":{"color":"hsla(0, 0%, 0%, 0.2)","width":2,"style":"dashed","radius":8}} /-->',
            '<!-- wp:gratora/submit-button {"label":"Give"} /-->',
        ];

        $registry = Plugin::instance()->container->get(BlockRegistry::class);
        $types    = WP_Block_Type_Registry::get_instance();

        foreach ($fields as $markup) {
            $parsed = parse_blocks($markup)[0];
            $name   = (string) $parsed['blockName'];

            $drawn = $registry->all()[$name]->render(
                $types->get_registered($name)->prepare_attributes_for_render($parsed['attrs']),
                ''
            );

            $this->assertNotSame([], $this->tagTokens($drawn), "fixture: $name drew something");
            $this->assertSame($this->tagTokens($drawn), $this->tagTokens(do_blocks($markup)), "$name lost an element or attribute to its callback");
        }
    }
}
