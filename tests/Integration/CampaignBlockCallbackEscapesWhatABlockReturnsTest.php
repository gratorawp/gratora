<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Campaigns\Blocks\CampaignBlock;
use Gratora\Campaigns\Blocks\CampaignBlockRegistry;
use Gratora\Campaigns\Blocks\CampaignFormBlock;
use Gratora\Campaigns\Blocks\CampaignGridBlock;
use Gratora\Campaigns\Blocks\CampaignImageBlock;
use Gratora\Campaigns\Blocks\CampaignProgressBlock;
use Gratora\Campaigns\Blocks\EmbeddedForm;
use Gratora\Campaigns\Blocks\SupporterWallBlock;
use Gratora\Campaigns\Campaign;
use Gratora\Campaigns\CampaignRepository;
use Gratora\Donors\DonorAvatars;
use Gratora\Forms\Form;
use Gratora\Foundation\Plugin;
use WP_Block_Type_Registry;
use WP_HTML_Tag_Processor;
use WP_REST_Request;

/**
 * WordPress prints whatever a page block's render callback returns. The
 * callback holds a block's own markup to the page allow-list, which has to
 * pass every icon, responsive image and progress value the blocks draw, and
 * leaves the donation form a block carries to the form's own escaping.
 */
final class CampaignBlockCallbackEscapesWhatABlockReturnsTest extends IntegrationTestCase
{
    private const PROBES = ['gratora-test/page-probe', 'gratora-test/form-probe'];

    private int $campaignId;
    private int $otherCampaignId;
    private string $formSlug;

    protected function setUp(): void
    {
        parent::setUp();

        $image = (int) self::factory()->attachment->create_object([
            'file'           => '2026/09/page-cover.jpg',
            'post_mime_type' => 'image/jpeg',
        ]);
        wp_update_attachment_metadata($image, [
            'width'  => 2400,
            'height' => 1600,
            'file'   => '2026/09/page-cover.jpg',
            'sizes'  => [
                'medium'       => ['file' => 'page-cover-300x200.jpg', 'width' => 300, 'height' => 200, 'mime-type' => 'image/jpeg'],
                'medium_large' => ['file' => 'page-cover-768x512.jpg', 'width' => 768, 'height' => 512, 'mime-type' => 'image/jpeg'],
                'large'        => ['file' => 'page-cover-1024x683.jpg', 'width' => 1024, 'height' => 683, 'mime-type' => 'image/jpeg'],
            ],
        ]);

        $this->campaignId = $this->publishedCampaign('Page block probe', $image);
        $this->otherCampaignId = $this->publishedCampaign('Another page block probe', $image);

        $req = new WP_REST_Request('POST', '/gratora/v1/admin/forms');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode([
            'title'       => 'Page block probe form',
            'campaign_id' => $this->campaignId,
            'blocks'      => '<!-- wp:gratora/donation-amount {"presets":[1000]} /--><!-- wp:gratora/submit-button /-->',
        ]));
        $form = Form::query()->find('id', (int) rest_do_request($req)->get_data()['id']);
        $form->status = 'published';
        $form->save();

        $this->formSlug = (string) $form->slug;
    }

    protected function tearDown(): void
    {
        foreach (self::PROBES as $name) {
            if (WP_Block_Type_Registry::get_instance()->is_registered($name)) {
                unregister_block_type($name);
            }
        }

        parent::tearDown();
    }

    private function publishedCampaign(string $title, int $image): int
    {
        $campaign = Campaign::make();
        $campaign->title               = $title;
        $campaign->slug                = sanitize_title($title) . '-' . uniqid();
        $campaign->status              = 'published';
        $campaign->currency            = 'USD';
        $campaign->goal_type           = 'amount';
        $campaign->goal_cents          = 100000;
        $campaign->raised_cents        = 25000;
        $campaign->image_attachment_id = $image;
        $campaign->created_at          = gmdate('Y-m-d H:i:s');
        $campaign->updated_at          = $campaign->created_at;
        $campaign->save();

        return (int) $campaign->id;
    }

    public function test_a_page_block_returning_a_handler_and_a_script_renders_without_either(): void
    {
        $registry = new CampaignBlockRegistry();
        $registry->add(new class (new CampaignRepository()) extends CampaignBlock {
            public function name(): string
            {
                return 'gratora-test/page-probe';
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

        $html = do_blocks('<!-- wp:gratora-test/page-probe /-->');

        $this->assertStringNotContainsString('onerror', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringContainsString('<p class="probe" data-kept="yes">kept</p>', $html);
    }

    public function test_a_form_carrying_block_has_its_own_markup_escaped_and_the_form_left_whole(): void
    {
        $registry = new CampaignBlockRegistry();
        $registry->add(new class (new CampaignRepository(), $this->formSlug) extends CampaignFormBlock {
            public function __construct(CampaignRepository $campaigns, private readonly string $slug)
            {
                parent::__construct($campaigns);
            }

            public function name(): string
            {
                return 'gratora-test/form-probe';
            }

            public function attributes(): array
            {
                return [];
            }

            public function render(array $attrs): EmbeddedForm
            {
                return new EmbeddedForm(
                    '<div class="probe-around"><img src="x" onerror="window.gratoraProbe=1">',
                    formSlug: $this->slug,
                    after: '<script>window.gratoraProbe=2</script></div>',
                );
            }
        });
        $registry->register();

        $html = do_blocks('<!-- wp:gratora-test/form-probe /-->');

        $this->assertStringNotContainsString('onerror', $html);
        $this->assertStringNotContainsString('<script>window.gratoraProbe', $html);
        $this->assertStringContainsString('<div class="probe-around">', $html);
        $this->assertSame($this->formSlug, $this->formConfigIn($html)['slug'] ?? null, 'the form between them keeps the config it runs on');
    }

    public function test_real_page_blocks_keep_their_icons_images_and_progress_values(): void
    {
        $c         = Plugin::instance()->container;
        $campaigns = new CampaignRepository();
        $id        = $this->campaignId;

        $blocks = [
            '<!-- wp:gratora/campaign-image {"campaignId":' . $id . '} /-->'    => new CampaignImageBlock($campaigns),
            '<!-- wp:gratora/campaign-progress {"campaignId":' . $id . '} /-->' => new CampaignProgressBlock($campaigns),
            '<!-- wp:gratora/campaign-grid {"campaignId":' . $id . '} /-->'     => new CampaignGridBlock($campaigns),
            '<!-- wp:gratora/supporter-wall {"campaignId":' . $id . '} /-->'    => new SupporterWallBlock($campaigns, $c->get(DonorAvatars::class)),
        ];

        $printed = '';
        foreach ($blocks as $markup => $block) {
            [$html, $drawn] = $this->printedAndDrawn($markup, $block);

            $this->assertSame($this->tagTokens($drawn), $this->tagTokens($html), $block->name() . ' lost an element or attribute to its callback');
            $printed .= $html;
        }

        $this->assertNotEmpty($this->attributeOf($printed, 'IMG', 'srcset'), 'fixture: the cover is a responsive image');
        $this->assertSame('high', $this->attributeOf($printed, 'IMG', 'fetchpriority'));
        $this->assertSame('0 0 24 24', $this->attributeOf($printed, 'SVG', 'viewbox'), 'fixture: an icon is drawn');
        $this->assertNotNull($this->attributeOf($printed, 'CIRCLE', 'r'), 'fixture: the empty wall draws its icon');
        $this->assertNotNull($this->attributeOf($printed, 'DIV', 'aria-valuenow'), 'fixture: the progress bar states its value');
        $this->assertNotNull($this->attributeOf($printed, 'SPAN', 'aria-valuenow'), 'fixture: a campaign card states its progress');

        $other         = Campaign::query()->find('id', $this->otherCampaignId);
        $other->status = 'draft';
        $other->save();

        [$empty, $drawnEmpty] = $this->printedAndDrawn('<!-- wp:gratora/campaign-grid {"campaignId":' . $id . '} /-->', $blocks[array_keys($blocks)[2]]);
        $this->assertSame($this->tagTokens($drawnEmpty), $this->tagTokens($empty), 'the empty grid lost part of its icon');
        $this->assertNotNull($this->attributeOf($empty, 'RECT', 'rx'), 'fixture: with nothing else published, the grid draws its empty icon');

        $button = do_blocks('<!-- wp:gratora/donate-button {"campaignId":' . $id . '} /-->');

        $this->assertSame('true', $this->attributeOf($button, 'DIV', 'aria-modal'));
        $this->assertSame('currentColor', $this->attributeOf($button, 'PATH', 'fill'), 'the close icon keeps its fill');
    }

    /**
     * What the page prints for a block, and what the block drew in the same
     * render with nothing held back.
     *
     * @return array{string, string}
     */
    private function printedAndDrawn(string $markup, CampaignBlock $block): array
    {
        $type     = WP_Block_Type_Registry::get_instance()->get_registered($block->name());
        $callback = $type->render_callback;
        $printed  = do_blocks($markup);

        $type->render_callback = static fn (array $attrs, string $content): string => $block->render($attrs, $content);
        try {
            $drawn = do_blocks($markup);
        } finally {
            $type->render_callback = $callback;
        }

        return [$printed, $drawn];
    }

    /** The first value of $attribute on a $tag that carries it, or null. */
    private function attributeOf(string $html, string $tag, string $attribute): string|bool|null
    {
        $processor = new WP_HTML_Tag_Processor($html);

        while ($processor->next_tag($tag)) {
            $value = $processor->get_attribute($attribute);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }
}
